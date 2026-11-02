<?php
require_once __DIR__ . '/includes/app.php';

$propertyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$reference = isset($_GET['reference']) && is_string($_GET['reference']) ? $_GET['reference'] : '';
if ((!$propertyId || $propertyId < 1) && $reference === '') {
    http_response_code(404);
    exit('Annonce introuvable.');
}

$db = Database::connect();
$property = null;
$payment = null;
if ($reference !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $reference)) {
        http_response_code(404);
        exit('Paiement introuvable.');
    }
    $paymentQuery = $db->prepare(
        'SELECT mp.reference, mp.amount, mp.operator, mp.phone, mp.created_at,
            p.id AS property_id, p.title, p.transaction_type
         FROM momo_payments mp
         JOIN properties p ON p.id = mp.property_id
         WHERE mp.reference = :reference AND mp.status = "simulated"'
    );
    $paymentQuery->execute(['reference' => $reference]);
    $payment = $paymentQuery->fetch();
    if (!$payment) {
        http_response_code(404);
        exit('Paiement introuvable.');
    }
} else {
    $propertyQuery = $db->prepare('SELECT id, title, price, transaction_type FROM properties WHERE id = :id AND status = "published"');
    $propertyQuery->execute(['id' => $propertyId]);
    $property = $propertyQuery->fetch();
    if (!$property) {
        http_response_code(404);
        exit('Cette annonce n’est plus disponible.');
    }
    $depositAmount = intdiv((int) $property['price'], 10);
    if ((int) $property['price'] % 10 >= 5) {
        $depositAmount++;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$payment) {
    verify_csrf();
    $operator = post_string('operator');
    $phone = trim(post_string('phone'));
    $normalizedPhone = preg_replace('/[\s-]+/', '', $phone);
    $operators = ['MTN MoMo', 'Moov Money', 'Celtiis Cash'];

    if (!in_array($operator, $operators, true)) {
        $error = 'Choisissez un opérateur Mobile Money valide.';
    } elseif (!is_string($normalizedPhone) || !preg_match('/^\+?[0-9]{8,15}$/', $normalizedPhone)) {
        $error = 'Saisissez un numéro de téléphone valide.';
    } else {
        $reference = bin2hex(random_bytes(32));
        $createPayment = $db->prepare(
            'INSERT INTO momo_payments (property_id, reference, amount, operator, phone, status)
             VALUES (:property_id, :reference, :amount, :operator, :phone, "simulated")'
        );
        $createPayment->execute([
            'property_id' => $property['id'],
            'reference' => $reference,
            'amount' => $depositAmount,
            'operator' => $operator,
            'phone' => $normalizedPhone,
        ]);
        redirect('paiement-momo.php?reference=' . $reference);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Paiement MoMo simulé — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=8"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section payment-page">
    <a class="back-link" href="<?= $payment ? 'property.php?id=' . (int) $payment['property_id'] : 'property.php?id=' . (int) $property['id'] ?>">← Retour à l’annonce</a>
    <?php if ($payment): ?>
        <section class="payment-result">
            <span class="eyebrow">SIMULATION TERMINÉE</span>
            <h1>Acompte simulé avec succès.</h1>
            <p>Aucun argent n’a été débité. Cette démonstration ne contacte aucun opérateur Mobile Money.</p>
            <dl>
                <div><dt>Bien</dt><dd><?= e($payment['title']) ?></dd></div>
                <div><dt>Acompte simulé (10 %)</dt><dd><?= e(number_format((float) $payment['amount'], 0, ',', ' ') . ' FCFA') ?></dd></div>
                <div><dt>Opérateur</dt><dd><?= e($payment['operator']) ?></dd></div>
                <div><dt>Numéro saisi</dt><dd><?= e($payment['phone']) ?></dd></div>
                <div><dt>Référence de simulation</dt><dd><?= e($payment['reference']) ?></dd></div>
            </dl>
        </section>
    <?php else: ?>
        <section class="payment-card">
            <span class="eyebrow">PAIEMENT MOBILE MONEY · MODE DÉMO</span>
            <h1>Simuler un paiement MoMo.</h1>
            <p>Annonce : <strong><?= e($property['title']) ?></strong></p>
            <div class="payment-amount"><?= e(number_format((float) $depositAmount, 0, ',', ' ') . ' FCFA') ?></div>
            <p class="payment-disclaimer">Acompte de réservation simulé : 10 % du prix affiché. Aucun paiement réel, débit ou appel à un opérateur ne sera effectué.</p>
            <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form class="stack-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Opérateur Mobile Money
                    <select name="operator" required>
                        <option value="">Choisir un opérateur</option>
                        <option value="MTN MoMo" <?= post_string('operator') === 'MTN MoMo' ? 'selected' : '' ?>>MTN MoMo</option>
                        <option value="Moov Money" <?= post_string('operator') === 'Moov Money' ? 'selected' : '' ?>>Moov Money</option>
                        <option value="Celtiis Cash" <?= post_string('operator') === 'Celtiis Cash' ? 'selected' : '' ?>>Celtiis Cash</option>
                    </select>
                </label>
                <label>Numéro de téléphone<input type="tel" name="phone" autocomplete="tel" required maxlength="20" value="<?= e(post_string('phone')) ?>" placeholder="+229 01 00 00 00 00"></label>
                <button class="button button-dark button-full" type="submit">Simuler le paiement de l’acompte</button>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
