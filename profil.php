<?php
require_once __DIR__ . '/includes/app.php';
$profileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$profileId || $profileId < 1) {
    http_response_code(404);
    exit('Profil introuvable.');
}

$profileQuery = Database::connect()->prepare('SELECT id, first_name, last_name, bio, profile_photo, created_at FROM users WHERE id = :id');
$profileQuery->execute(['id' => $profileId]);
$profile = $profileQuery->fetch();
if (!$profile) {
    http_response_code(404);
    exit('Profil introuvable.');
}

$listingQuery = Database::connect()->prepare(
    'SELECT p.*, u.first_name, u.last_name,
        (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM properties p LEFT JOIN users u ON u.id = p.user_id
     WHERE p.user_id = :user_id AND p.status = "published" ORDER BY p.created_at DESC'
);
$listingQuery->execute(['user_id' => $profileId]);
$properties = $listingQuery->fetchAll();
$user = current_user();
$returnTo = 'index.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Profil de <?= e($profile['first_name']) ?> — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=9"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section">
    <section class="public-profile"><?php if ($profile['profile_photo']): ?><img class="public-avatar public-avatar-photo" src="<?= e($profile['profile_photo']) ?>" alt="Photo de profil de <?= e($profile['first_name']) ?>"><?php else: ?><div class="public-avatar"><?= e(strtoupper(substr($profile['first_name'], 0, 1))) ?></div><?php endif; ?><div><span class="eyebrow">MEMBRE HABITA DEPUIS <?= e(date('Y', strtotime($profile['created_at']))) ?></span><h1><?= e($profile['first_name'] . ' ' . $profile['last_name']) ?></h1><p><?= e($profile['bio'] ?: 'À la recherche de belles histoires immobilières.') ?></p></div></section>
    <div class="section-heading profile-list-heading"><div><span class="eyebrow">ANNONCES DISPONIBLES</span><h2>Les biens proposés.</h2></div></div>
    <?php if ($properties): ?><div class="property-grid"><?php foreach ($properties as $property): require __DIR__ . '/includes/property-card.php'; endforeach; ?></div>
    <?php else: ?><div class="empty-state"><h3>Aucune annonce en ligne pour le moment.</h3><p>Revenez bientôt pour découvrir les prochains biens.</p></div><?php endif; ?>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
