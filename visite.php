<?php
require_once __DIR__ . '/includes/app.php';

$propertyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    http_response_code(404);
    exit('Bien introuvable.');
}

$db = Database::connect();
$propertyQuery = $db->prepare(
    'SELECT id, title, city, district, image_url FROM properties
     WHERE id = :id AND status = "published"
       AND operational_status IN ("available", "reserved")'
);
$propertyQuery->execute(['id' => $propertyId]);
$property = $propertyQuery->fetch();
if (!$property) {
    http_response_code(404);
    exit('Ce bien n’est plus disponible pour une visite.');
}

$error = '';
$user = current_user();
$name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$email = (string) ($user['email'] ?? '');
$phone = (string) ($user['phone'] ?? '');
$scheduledAt = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim(post_string('prospect_name'));
    $email = trim(post_string('prospect_email'));
    $phone = trim(post_string('prospect_phone'));
    $scheduledAt = post_string('scheduled_at');
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $scheduledAt);
    $validDate = $date !== false && $date->format('Y-m-d\TH:i') === $scheduledAt
        && $date > new DateTime();

    if ($name === '' || strlen($name) > 160 || strlen($phone) > 40
        || ($phone === '' && $email === '')
        || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || strlen($email) > 190
        || !$validDate) {
        $error = 'Vérifiez votre nom, vos coordonnées et la date souhaitée.';
    } else {
        $db->beginTransaction();
        try {
            $visit = $db->prepare(
                'INSERT INTO property_visits
                    (property_id, agent_id, prospect_name, prospect_email, prospect_phone, scheduled_at)
                 VALUES (:property_id, NULL, :prospect_name, :prospect_email, :prospect_phone, :scheduled_at)'
            );
            $visit->execute([
                'property_id' => $propertyId,
                'prospect_name' => $name,
                'prospect_email' => $email !== '' ? $email : null,
                'prospect_phone' => $phone !== '' ? $phone : null,
                'scheduled_at' => $date->format('Y-m-d H:i:s'),
            ]);
            $db->prepare(
                'INSERT INTO property_activity (property_id, actor_id, activity)
                 VALUES (:property_id, :actor_id, :activity)'
            )->execute([
                'property_id' => $propertyId,
                'actor_id' => $user['id'] ?? null,
                'activity' => 'Demande de visite reçue pour ' . $property['title'],
            ]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Votre demande de visite a été transmise à Habita. L’équipe vous recontactera pour confirmer le rendez-vous.',
        ];
        redirect('visite.php?id=' . (int) $propertyId . '&sent=1');
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demander une visite — Habita</title>
    <link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css?v=11">
</head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section form-page">
    <div class="form-intro">
        <span class="eyebrow">DÉCOUVREZ LE BIEN SUR PLACE</span>
        <h1>Demander une<br>visite.</h1>
        <p><?= e($property['title']) ?> · <?= e($property['city']) ?><?= $property['district'] ? ' · ' . e($property['district']) : '' ?></p>
        <img class="visit-property-image" src="<?= e($property['image_url']) ?>" alt="<?= e($property['title']) ?>">
    </div>
    <form class="listing-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <h2>Vos coordonnées</h2>
        <p>Choisissez une date et une heure souhaitées. L’équipe Habita confirmera le rendez-vous et désignera un agent.</p>
        <label>Nom et prénom<input name="prospect_name" maxlength="160" required value="<?= e($name) ?>"></label>
        <div class="form-row">
            <label>Téléphone<input type="tel" name="prospect_phone" maxlength="40" value="<?= e($phone) ?>"></label>
            <label>E-mail<input type="email" name="prospect_email" maxlength="190" value="<?= e($email) ?>"></label>
        </div>
        <p class="map-help">Renseignez au moins un moyen de vous contacter.</p>
        <label>Date et heure souhaitées<input type="datetime-local" name="scheduled_at" min="<?= e(date('Y-m-d\TH:i')) ?>" required value="<?= e($scheduledAt) ?>"></label>
        <button class="button button-dark" type="submit">Envoyer la demande de visite <span aria-hidden="true">→</span></button>
    </form>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
