<?php
require_once __DIR__ . '/../includes/app.php';
$admin = require_admin();
$db = Database::connect();
$error = '';

// Expire overdue holds and release their properties before processing new actions.
$db->exec('UPDATE property_reservations SET status = "expired" WHERE status = "active" AND expires_at <= NOW()');
$db->exec(
    'UPDATE properties p SET p.operational_status = "available"
     WHERE p.operational_status = "reserved" AND p.status = "published"
       AND NOT EXISTS (
           SELECT 1 FROM property_reservations r
           WHERE r.property_id = p.id AND r.status = "active" AND r.expires_at > NOW()
       )'
);

// Validate calendar dates without accepting normalized or impossible dates.
$validDate = static function (string $date): bool {
    $parts = explode('-', $date);

    return count($parts) === 3 && strlen($parts[0]) === 4 && strlen($parts[1]) === 2
        && strlen($parts[2]) === 2 && ctype_digit(implode('', $parts))
        && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
};

// Add an administrative audit event for each material sales action.
$recordActivity = static function (string $activity, int $propertyId) use ($db, $admin): void {
    $statement = $db->prepare(
        'INSERT INTO property_activity (property_id, actor_id, activity) VALUES (:property_id, :actor_id, :activity)'
    );
    $statement->execute([
        'property_id' => $propertyId,
        'actor_id' => $admin['id'],
        'activity' => $activity,
    ]);
};

// Handle sales, negotiation, reservation, and payment submissions securely.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'save_buyer') {
            $firstName = trim(post_string('first_name'));
            $lastName = trim(post_string('last_name'));
            $email = trim(post_string('email'));
            $phone = trim(post_string('phone'));
            $address = trim(post_string('address'));
            if ($firstName === '' || $lastName === '' || strlen($firstName) > 80 || strlen($lastName) > 80
                || $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 190 || strlen($phone) > 40 || strlen($address) > 255) {
                throw new RuntimeException('Vérifiez les coordonnées de l’acheteur.');
            }
            $db->prepare(
                'INSERT INTO buyers (first_name, last_name, email, phone, address)
                 VALUES (:first_name, :last_name, :email, :phone, :address)'
            )->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'address' => $address !== '' ? $address : null,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'L’acheteur a été enregistré.'];
            redirect('sales.php#buyers');
        }

        if ($action === 'save_negotiation') {
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            $buyerId = filter_var(post_string('buyer_id'), FILTER_VALIDATE_INT);
            $offer = filter_input(INPUT_POST, 'offer_amount', FILTER_VALIDATE_INT);
            $notes = trim(post_string('notes'));
            if (!$propertyId || !$buyerId || $offer === false || $offer < 1 || strlen($notes) > 5000) {
                throw new RuntimeException('Choisissez un bien et un acheteur, puis indiquez une offre valide.');
            }
            $propertyQuery = $db->prepare('SELECT id FROM properties WHERE id = :id AND transaction_type = "sale"');
            $propertyQuery->execute(['id' => $propertyId]);
            $buyerQuery = $db->prepare('SELECT id FROM buyers WHERE id = :id');
            $buyerQuery->execute(['id' => $buyerId]);
            if (!$propertyQuery->fetchColumn() || !$buyerQuery->fetchColumn()) {
                throw new RuntimeException('Le bien ou l’acheteur sélectionné est introuvable.');
            }
            $db->prepare(
                'INSERT INTO sale_negotiations (property_id, buyer_id, offer_amount, notes)
                 VALUES (:property_id, :buyer_id, :offer_amount, :notes)'
            )->execute([
                'property_id' => $propertyId, 'buyer_id' => $buyerId,
                'offer_amount' => $offer, 'notes' => $notes !== '' ? $notes : null,
            ]);
            $recordActivity('Nouvelle négociation de vente enregistrée', $propertyId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La négociation a été enregistrée.'];
            redirect('sales.php#negotiations');
        }

        if ($action === 'update_negotiation') {
            $negotiationId = filter_var(post_string('negotiation_id'), FILTER_VALIDATE_INT);
            $status = post_string('status');
            if (!$negotiationId || !in_array($status, ['open', 'accepted', 'rejected', 'withdrawn'], true)) {
                throw new RuntimeException('Négociation ou statut invalide.');
            }
            $negotiationCheck = $db->prepare('SELECT id FROM sale_negotiations WHERE id = :id');
            $negotiationCheck->execute(['id' => $negotiationId]);
            if (!$negotiationCheck->fetchColumn()) {
                throw new RuntimeException('La négociation sélectionnée est introuvable.');
            }
            $db->prepare('UPDATE sale_negotiations SET status = :status WHERE id = :id')
                ->execute(['status' => $status, 'id' => $negotiationId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le statut de la négociation a été mis à jour.'];
            redirect('sales.php#negotiations');
        }

        if ($action === 'reserve_property') {
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            $buyerId = filter_var(post_string('buyer_id'), FILTER_VALIDATE_INT);
            $agreedPrice = filter_input(INPUT_POST, 'agreed_price', FILTER_VALIDATE_INT);
            $expiresAt = post_string('expires_at');
            if (!$propertyId || !$buyerId || $agreedPrice === false || $agreedPrice < 1
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $expiresAt)) {
                throw new RuntimeException('Vérifiez le bien, l’acheteur, le prix et la date d’expiration.');
            }
            $expiresTimestamp = strtotime($expiresAt);
            if ($expiresTimestamp === false || $expiresTimestamp <= time()) {
                throw new RuntimeException('La date d’expiration de la réservation doit être dans le futur.');
            }
            $db->beginTransaction();
            try {
                $propertyQuery = $db->prepare(
                    'SELECT title, transaction_type, operational_status FROM properties WHERE id = :id FOR UPDATE'
                );
                $propertyQuery->execute(['id' => $propertyId]);
                $property = $propertyQuery->fetch();
                $buyerQuery = $db->prepare('SELECT id FROM buyers WHERE id = :id');
                $buyerQuery->execute(['id' => $buyerId]);
                if (!$property || $property['transaction_type'] !== 'sale' || !$buyerQuery->fetchColumn()) {
                    throw new RuntimeException('La réservation nécessite un bien à vendre et un acheteur existants.');
                }
                if (!in_array($property['operational_status'], ['available', 'reserved'], true)) {
                    throw new RuntimeException('Ce bien n’est pas disponible pour une réservation.');
                }
                $activeReservation = $db->prepare(
                    'SELECT id FROM property_reservations
                     WHERE property_id = :property_id AND status = "active" AND expires_at > NOW() LIMIT 1'
                );
                $activeReservation->execute(['property_id' => $propertyId]);
                if ($activeReservation->fetchColumn()) {
                    throw new RuntimeException('Ce bien possède déjà une réservation active.');
                }
                $db->prepare(
                    'INSERT INTO property_reservations
                        (property_id, buyer_id, agreed_price, reserved_at, expires_at, status)
                     VALUES (:property_id, :buyer_id, :agreed_price, NOW(), :expires_at, "active")'
                )->execute([
                    'property_id' => $propertyId, 'buyer_id' => $buyerId,
                    'agreed_price' => $agreedPrice, 'expires_at' => str_replace('T', ' ', $expiresAt) . ':00',
                ]);
                $db->prepare('UPDATE properties SET operational_status = "reserved" WHERE id = :id')
                    ->execute(['id' => $propertyId]);
                $recordActivity('Réservation créée pour ' . $property['title'], $propertyId);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La réservation a été créée.'];
            redirect('sales.php#reservations');
        }

        if ($action === 'reservation_payment') {
            $reservationId = filter_var(post_string('reservation_id'), FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_INT);
            $paymentMethod = trim(post_string('payment_method'));
            $reference = trim(post_string('payment_reference'));
            if (!$reservationId || $amount === false || $amount < 1
                || strlen($paymentMethod) > 80 || strlen($reference) > 120) {
                throw new RuntimeException('Vérifiez la réservation et le montant de l’acompte.');
            }
            $db->beginTransaction();
            try {
                $reservationQuery = $db->prepare(
                    'SELECT r.agreed_price, r.status, r.expires_at, r.property_id,
                        (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.reservation_id = r.id) AS paid_total
                     FROM property_reservations r WHERE r.id = :id FOR UPDATE'
                );
                $reservationQuery->execute(['id' => $reservationId]);
                $reservation = $reservationQuery->fetch();
                if (!$reservation || $reservation['status'] !== 'active'
                    || strtotime($reservation['expires_at']) <= time()
                    || $amount > (int) $reservation['agreed_price'] - (int) $reservation['paid_total']) {
                    throw new RuntimeException('La réservation est inactive ou le paiement dépasse le solde réservé.');
                }
                $db->prepare(
                    'INSERT INTO sale_payments (reservation_id, amount, payment_type, payment_method, payment_reference, paid_at)
                     VALUES (:reservation_id, :amount, "deposit", :payment_method, :payment_reference, NOW())'
                )->execute([
                    'reservation_id' => $reservationId, 'amount' => $amount,
                    'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                    'payment_reference' => $reference !== '' ? $reference : null,
                ]);
                $paymentId = (int) $db->lastInsertId();
                $recordActivity('Acompte de réservation enregistré', (int) $reservation['property_id']);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'L’acompte a été enregistré.'];
            redirect('sale-document.php?type=receipt&payment_id=' . $paymentId);
        }

        if ($action === 'close_reservation') {
            $reservationId = filter_var(post_string('reservation_id'), FILTER_VALIDATE_INT);
            if (!$reservationId) {
                throw new RuntimeException('Réservation invalide.');
            }
            $db->beginTransaction();
            try {
                $reservationQuery = $db->prepare(
                    'SELECT r.property_id, r.status, p.title FROM property_reservations r
                     JOIN properties p ON p.id = r.property_id WHERE r.id = :id FOR UPDATE'
                );
                $reservationQuery->execute(['id' => $reservationId]);
                $reservation = $reservationQuery->fetch();
                if (!$reservation || $reservation['status'] !== 'active') {
                    throw new RuntimeException('Cette réservation n’est plus active.');
                }
                $db->prepare('UPDATE property_reservations SET status = "cancelled" WHERE id = :id')
                    ->execute(['id' => $reservationId]);
                $db->prepare('UPDATE properties SET operational_status = "available" WHERE id = :id')
                    ->execute(['id' => $reservation['property_id']]);
                $recordActivity('Réservation annulée pour ' . $reservation['title'], (int) $reservation['property_id']);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La réservation a été annulée.'];
            redirect('sales.php#reservations');
        }

        if ($action === 'record_sale') {
            $reservationIdInput = post_string('reservation_id');
            $reservationId = $reservationIdInput === '' ? null : filter_var($reservationIdInput, FILTER_VALIDATE_INT);
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            $buyerId = filter_var(post_string('buyer_id'), FILTER_VALIDATE_INT);
            $salePrice = filter_input(INPUT_POST, 'sale_price', FILTER_VALIDATE_INT);
            $saleDate = post_string('sale_date');
            $contractReference = trim(post_string('contract_reference'));
            if ($reservationIdInput !== '' && (!$reservationId || $reservationId < 1)
                || !$propertyId || !$buyerId || $salePrice === false || $salePrice < 1
                || !$validDate($saleDate) || strlen($contractReference) > 100) {
                throw new RuntimeException('Vérifiez le bien, l’acheteur, le prix de vente et la date.');
            }
            $db->beginTransaction();
            try {
                $propertyQuery = $db->prepare(
                    'SELECT title, operational_status, transaction_type FROM properties WHERE id = :id FOR UPDATE'
                );
                $propertyQuery->execute(['id' => $propertyId]);
                $property = $propertyQuery->fetch();
                $buyerQuery = $db->prepare('SELECT id FROM buyers WHERE id = :id');
                $buyerQuery->execute(['id' => $buyerId]);
                if (!$property || !$buyerQuery->fetchColumn() || $property['transaction_type'] !== 'sale') {
                    throw new RuntimeException('Le bien à vendre ou l’acheteur est introuvable.');
                }
                if ($reservationId) {
                    $reservationQuery = $db->prepare(
                        'SELECT property_id, buyer_id, agreed_price, status, expires_at
                         FROM property_reservations WHERE id = :id FOR UPDATE'
                    );
                    $reservationQuery->execute(['id' => $reservationId]);
                    $reservation = $reservationQuery->fetch();
                    if (!$reservation || $reservation['status'] !== 'active'
                        || strtotime($reservation['expires_at']) <= time()
                        || (int) $reservation['property_id'] !== $propertyId
                        || (int) $reservation['buyer_id'] !== $buyerId) {
                        throw new RuntimeException('La réservation sélectionnée ne correspond pas au bien et à l’acheteur.');
                    }
                    if ($property['operational_status'] !== 'reserved') {
                        throw new RuntimeException('Le bien n’a plus le statut réservé.');
                    }
                    $depositQuery = $db->prepare(
                        'SELECT COALESCE(SUM(amount), 0) FROM sale_payments WHERE reservation_id = :id'
                    );
                    $depositQuery->execute(['id' => $reservationId]);
                    if ((float) $depositQuery->fetchColumn() > $salePrice) {
                        throw new RuntimeException('Le prix de vente ne peut pas être inférieur aux acomptes déjà encaissés.');
                    }
                } elseif ($property['operational_status'] !== 'available') {
                    throw new RuntimeException('Une vente sans réservation nécessite un bien disponible.');
                }
                $existingSaleQuery = $db->prepare('SELECT id FROM property_sales WHERE property_id = :property_id');
                $existingSaleQuery->execute(['property_id' => $propertyId]);
                if ($existingSaleQuery->fetchColumn()) {
                    throw new RuntimeException('Une vente est déjà enregistrée pour ce bien.');
                }
                $db->prepare(
                    'INSERT INTO property_sales
                        (property_id, buyer_id, reservation_id, sale_price, sale_date, contract_reference)
                     VALUES (:property_id, :buyer_id, :reservation_id, :sale_price, :sale_date, :contract_reference)'
                )->execute([
                    'property_id' => $propertyId, 'buyer_id' => $buyerId,
                    'reservation_id' => $reservationId, 'sale_price' => $salePrice,
                    'sale_date' => $saleDate, 'contract_reference' => $contractReference !== '' ? $contractReference : null,
                ]);
                $saleId = (int) $db->lastInsertId();
                if ($reservationId) {
                    $db->prepare(
                        'UPDATE sale_payments SET sale_id = :sale_id WHERE reservation_id = :reservation_id'
                    )->execute(['sale_id' => $saleId, 'reservation_id' => $reservationId]);
                    $db->prepare('UPDATE property_reservations SET status = "converted" WHERE id = :id')
                        ->execute(['id' => $reservationId]);
                }
                $db->prepare('UPDATE properties SET operational_status = "sold" WHERE id = :id')
                    ->execute(['id' => $propertyId]);
                $recordActivity('Vente enregistrée pour ' . $property['title'] . ' — ' . number_format((float) $salePrice, 0, ',', ' ') . ' FCFA', $propertyId);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La vente a été enregistrée. Vous pouvez générer son document.'];
            redirect('sale-document.php?type=contract&sale_id=' . $saleId);
        }

        if ($action === 'sale_payment') {
            $saleId = filter_var(post_string('sale_id'), FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_INT);
            $paymentType = post_string('payment_type');
            $paymentMethod = trim(post_string('payment_method'));
            $reference = trim(post_string('payment_reference'));
            if (!$saleId || $amount === false || $amount < 1
                || !in_array($paymentType, ['installment', 'balance'], true)
                || strlen($paymentMethod) > 80 || strlen($reference) > 120) {
                throw new RuntimeException('Vérifiez le paiement et son mode.');
            }
            $db->beginTransaction();
            try {
                $saleQuery = $db->prepare(
                    'SELECT s.sale_price, s.property_id, p.title,
                        (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = s.id) AS paid_total
                     FROM property_sales s JOIN properties p ON p.id = s.property_id WHERE s.id = :id FOR UPDATE'
                );
                $saleQuery->execute(['id' => $saleId]);
                $sale = $saleQuery->fetch();
                if (!$sale || $amount > (int) $sale['sale_price'] - (int) $sale['paid_total']) {
                    throw new RuntimeException('Le montant dépasse le solde restant de la vente.');
                }
                $db->prepare(
                    'INSERT INTO sale_payments (sale_id, amount, payment_type, payment_method, payment_reference, paid_at)
                     VALUES (:sale_id, :amount, :payment_type, :payment_method, :payment_reference, NOW())'
                )->execute([
                    'sale_id' => $saleId, 'amount' => $amount, 'payment_type' => $paymentType,
                    'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                    'payment_reference' => $reference !== '' ? $reference : null,
                ]);
                $paymentId = (int) $db->lastInsertId();
                $recordActivity('Paiement de vente enregistré pour ' . $sale['title'], (int) $sale['property_id']);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le paiement a été enregistré.'];
            redirect('sale-document.php?type=receipt&payment_id=' . $paymentId);
        }

        throw new RuntimeException('Action commerciale inconnue.');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

// Load buyers, sale properties, and negotiation records for the administration screens.
$buyers = $db->query('SELECT * FROM buyers ORDER BY last_name, first_name')->fetchAll();
$saleProperties = $db->query(
    'SELECT id, title, city, price, operational_status FROM properties
     WHERE transaction_type = "sale" AND status = "published" ORDER BY title'
)->fetchAll();
$negotiations = $db->query(
    'SELECT n.*, p.title, b.first_name, b.last_name FROM sale_negotiations n
     JOIN properties p ON p.id = n.property_id JOIN buyers b ON b.id = n.buyer_id
     ORDER BY n.created_at DESC LIMIT 100'
)->fetchAll();
$reservations = $db->query(
    'SELECT r.*, p.title, b.first_name, b.last_name,
        (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.reservation_id = r.id) AS paid_total
     FROM property_reservations r JOIN properties p ON p.id = r.property_id
     JOIN buyers b ON b.id = r.buyer_id ORDER BY r.created_at DESC LIMIT 100'
)->fetchAll();
$sales = $db->query(
    'SELECT s.*, p.title, b.first_name, b.last_name,
        (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = s.id) AS paid_total
     FROM property_sales s JOIN properties p ON p.id = s.property_id
     JOIN buyers b ON b.id = s.buyer_id ORDER BY s.sale_date DESC, s.id DESC LIMIT 200'
)->fetchAll();
$payments = $db->query(
    'SELECT sp.*, COALESCE(s.property_id, r.property_id) AS property_id,
        COALESCE(s.buyer_id, r.buyer_id) AS buyer_id, p.title, b.first_name, b.last_name
     FROM sale_payments sp
     LEFT JOIN property_sales s ON s.id = sp.sale_id
     LEFT JOIN property_reservations r ON r.id = sp.reservation_id
     JOIN properties p ON p.id = COALESCE(s.property_id, r.property_id)
     JOIN buyers b ON b.id = COALESCE(s.buyer_id, r.buyer_id)
     ORDER BY sp.paid_at DESC LIMIT 200'
)->fetchAll();
$availableProperties = array_values(array_filter($saleProperties, static function (array $property): bool {
    return $property['operational_status'] === 'available';
}));
$reservationStatusLabels = ['active' => 'Active', 'converted' => 'Transformée en vente', 'cancelled' => 'Annulée', 'expired' => 'Expirée'];
$negotiationStatusLabels = ['open' => 'En cours', 'accepted' => 'Acceptée', 'rejected' => 'Refusée', 'withdrawn' => 'Retirée'];
$flash = flash_message();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ventes immobilières — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=11">
</head>
<body>
<header class="site-header">
    <div class="nav-wrap">
        <a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <nav class="portfolio-nav" aria-label="Gestion des ventes">
            <a href="#buyers">Acheteurs</a><a href="#negotiations">Négociations</a><a href="#reservations">Réservations</a>
            <a href="#sales">Ventes</a><a href="visits.php">Visites</a><a href="portfolio.php">Patrimoine</a>
        </nav>
    </div>
</header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="section page-section portfolio-page">
    <div class="section-heading">
        <div><span class="eyebrow">ESPACE SÉCURISÉ · <?= e($admin['first_name']) ?></span><h1>Ventes immobilières.</h1><p>Acheteurs, offres, réservations, encaissements et documents de vente.</p></div>
        <a class="button button-outline" href="portfolio.php">← Gestion immobilière</a>
    </div>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <section class="portfolio-panel" id="buyers">
        <div class="panel-heading"><div><span class="eyebrow">PROSPECTS ACHETEURS</span><h2>Enregistrer un acheteur</h2></div></div>
        <form class="portfolio-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_buyer">
            <div class="portfolio-form-grid">
                <label>Prénom<input name="first_name" maxlength="80" required></label><label>Nom<input name="last_name" maxlength="80" required></label>
                <label>E-mail<input type="email" name="email" maxlength="190"></label><label>Téléphone<input type="tel" name="phone" maxlength="40"></label>
                <label class="portfolio-wide">Adresse<input name="address" maxlength="255"></label>
            </div>
            <button class="button button-dark" type="submit">Enregistrer l’acheteur</button>
        </form>
        <div class="portfolio-list"><?php foreach ($buyers as $buyer): ?><article class="portfolio-list-item"><div><strong><?= e($buyer['first_name'] . ' ' . $buyer['last_name']) ?></strong><span><?= e($buyer['phone'] ?: 'Téléphone non renseigné') ?><?= $buyer['email'] ? ' · ' . e($buyer['email']) : '' ?></span></div></article><?php endforeach; ?></div>
    </section>

    <section class="portfolio-panel" id="negotiations">
        <div class="panel-heading"><div><span class="eyebrow">OFFRES ET DISCUSSIONS</span><h2>Enregistrer une négociation</h2></div></div>
        <form class="portfolio-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_negotiation">
            <div class="portfolio-form-grid">
                <label>Bien à vendre<select name="property_id" required><option value="">Sélectionner</option><?php foreach ($saleProperties as $property): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title'] . ' — ' . number_format((float) $property['price'], 0, ',', ' ') . ' FCFA') ?></option><?php endforeach; ?></select></label>
                <label>Acheteur<select name="buyer_id" required><option value="">Sélectionner</option><?php foreach ($buyers as $buyer): ?><option value="<?= (int) $buyer['id'] ?>"><?= e($buyer['first_name'] . ' ' . $buyer['last_name']) ?></option><?php endforeach; ?></select></label>
                <label>Prix proposé (FCFA)<input type="number" name="offer_amount" min="1" required></label>
                <label class="portfolio-wide">Notes de négociation<textarea name="notes" rows="3" maxlength="5000"></textarea></label>
            </div>
            <button class="button button-dark" type="submit">Ajouter l’offre</button>
        </form>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Date</th><th>Bien / acheteur</th><th>Offre</th><th>Notes</th><th>Statut</th></tr></thead><tbody>
            <?php foreach ($negotiations as $negotiation): ?><tr>
                <td><?= e(date('d/m/Y', strtotime($negotiation['created_at']))) ?></td>
                <td><?= e($negotiation['title']) ?><small><?= e($negotiation['first_name'] . ' ' . $negotiation['last_name']) ?></small></td>
                <td><?= e(number_format((float) $negotiation['offer_amount'], 0, ',', ' ')) ?> FCFA</td>
                <td><?= e($negotiation['notes'] ?: '—') ?></td>
                <td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_negotiation"><input type="hidden" name="negotiation_id" value="<?= (int) $negotiation['id'] ?>"><select name="status" aria-label="Statut de la négociation"><?php foreach ($negotiationStatusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $negotiation['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><button class="small-action" type="submit">Mettre à jour</button></form></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="portfolio-panel" id="reservations">
        <div class="panel-heading"><div><span class="eyebrow">BLOCAGE TEMPORAIRE DU BIEN</span><h2>Créer une réservation</h2></div></div>
        <form class="portfolio-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reserve_property">
            <div class="portfolio-form-grid">
                <label>Bien disponible<select name="property_id" required><option value="">Sélectionner</option><?php foreach ($availableProperties as $property): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title'] . ' — ' . $property['city']) ?></option><?php endforeach; ?></select></label>
                <label>Acheteur<select name="buyer_id" required><option value="">Sélectionner</option><?php foreach ($buyers as $buyer): ?><option value="<?= (int) $buyer['id'] ?>"><?= e($buyer['first_name'] . ' ' . $buyer['last_name']) ?></option><?php endforeach; ?></select></label>
                <label>Prix convenu (FCFA)<input type="number" name="agreed_price" min="1" required></label>
                <label>Expiration de la réservation<input type="datetime-local" name="expires_at" required></label>
            </div>
            <button class="button button-dark" type="submit">Réserver le bien</button>
        </form>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Bien / acheteur</th><th>Prix convenu</th><th>Acomptes</th><th>Expiration</th><th>Statut / actions</th></tr></thead><tbody>
            <?php foreach ($reservations as $reservation): ?><tr>
                <td><?= e($reservation['title']) ?><small><?= e($reservation['first_name'] . ' ' . $reservation['last_name']) ?></small></td>
                <td><?= e(number_format((float) $reservation['agreed_price'], 0, ',', ' ')) ?> FCFA</td>
                <td><?= e(number_format((float) $reservation['paid_total'], 0, ',', ' ')) ?> FCFA<?php if ((int) $reservation['paid_total'] > 0): ?><small><?php foreach ($payments as $payment): if ((int) $payment['reservation_id'] === (int) $reservation['id']): ?><a href="sale-document.php?type=receipt&amp;payment_id=<?= (int) $payment['id'] ?>">Reçu #<?= (int) $payment['id'] ?></a> <?php endif; endforeach; ?></small><?php endif; ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($reservation['expires_at']))) ?></td>
                <td><?= e($reservationStatusLabels[$reservation['status']] ?? $reservation['status']) ?>
                    <?php if ($reservation['status'] === 'active' && strtotime($reservation['expires_at']) > time()): ?>
                        <form class="portfolio-inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reservation_payment"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><input type="number" name="amount" min="1" max="<?= max(1, (int) $reservation['agreed_price'] - (int) $reservation['paid_total']) ?>" placeholder="Acompte FCFA" required><input name="payment_method" maxlength="80" placeholder="Mode"><input name="payment_reference" maxlength="120" placeholder="Référence"><button class="small-action" type="submit">Encaisser</button></form>
                        <form class="portfolio-inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_reservation"><input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>"><button class="small-action" type="submit">Annuler</button></form>
                    <?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="portfolio-panel" id="sales">
        <div class="panel-heading"><div><span class="eyebrow">ACTES ET TRANSACTIONS</span><h2>Enregistrer une vente</h2></div></div>
        <form class="portfolio-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="record_sale">
            <div class="portfolio-form-grid">
                <label>Bien<select name="property_id" required><option value="">Sélectionner</option><?php foreach ($saleProperties as $property): if ($property['operational_status'] !== 'sold'): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title'] . ' — ' . $property['operational_status']) ?></option><?php endif; endforeach; ?></select></label>
                <label>Réservation active (facultatif)<select name="reservation_id"><option value="">Vente sans réservation</option><?php foreach ($reservations as $reservation): if ($reservation['status'] === 'active' && strtotime($reservation['expires_at']) > time()): ?><option value="<?= (int) $reservation['id'] ?>"><?= e($reservation['title'] . ' — ' . $reservation['first_name'] . ' ' . $reservation['last_name']) ?></option><?php endif; endforeach; ?></select></label>
                <label>Acheteur<select name="buyer_id" required><option value="">Sélectionner</option><?php foreach ($buyers as $buyer): ?><option value="<?= (int) $buyer['id'] ?>"><?= e($buyer['first_name'] . ' ' . $buyer['last_name']) ?></option><?php endforeach; ?></select></label>
                <label>Prix final de vente (FCFA)<input type="number" name="sale_price" min="1" required></label>
                <label>Date de vente<input type="date" name="sale_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Référence de l’acte<input name="contract_reference" maxlength="100"></label>
            </div>
            <button class="button button-dark" type="submit">Confirmer la vente</button>
        </form>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Date</th><th>Bien / acheteur</th><th>Prix de vente</th><th>Payé / solde</th><th>Documents et paiements</th></tr></thead><tbody>
            <?php foreach ($sales as $sale): $balance = max(0, (int) $sale['sale_price'] - (int) $sale['paid_total']); ?><tr>
                <td><?= e(date('d/m/Y', strtotime($sale['sale_date']))) ?></td>
                <td><?= e($sale['title']) ?><small><?= e($sale['first_name'] . ' ' . $sale['last_name']) ?> · <?= e($sale['contract_reference'] ?: 'Sans référence') ?></small></td>
                <td><?= e(number_format((float) $sale['sale_price'], 0, ',', ' ')) ?> FCFA</td>
                <td><?= e(number_format((float) $sale['paid_total'], 0, ',', ' ')) ?> payé<small>Solde : <?= e(number_format((float) $balance, 0, ',', ' ')) ?> FCFA</small></td>
                <td><a class="small-action" href="sale-document.php?type=contract&amp;sale_id=<?= (int) $sale['id'] ?>">Acte de vente</a>
                    <?php if ($balance > 0): ?><form class="portfolio-inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="sale_payment"><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>"><input type="number" name="amount" min="1" max="<?= $balance ?>" placeholder="Montant FCFA" required><select name="payment_type"><option value="installment">Échéance</option><option value="balance">Solde</option></select><input name="payment_method" maxlength="80" placeholder="Mode"><input name="payment_reference" maxlength="120" placeholder="Référence"><button class="small-action" type="submit">Encaisser</button></form><?php endif; ?>
                    <?php foreach ($payments as $payment): if ((int) $payment['sale_id'] === (int) $sale['id']): ?><a class="small-action" href="sale-document.php?type=receipt&amp;payment_id=<?= (int) $payment['id'] ?>">Reçu #<?= (int) $payment['id'] ?></a><?php endif; endforeach; ?>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
