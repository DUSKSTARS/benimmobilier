<?php
require_once __DIR__ . '/includes/app.php';
$user = current_user();
$propertyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$propertyId || $propertyId < 1) {
    http_response_code(404);
    exit('Annonce introuvable.');
}

$isAdmin = $user !== null && ($user['role'] ?? '') === 'admin';
$visibilityCondition = $isAdmin ? '' : ' AND p.status = "published"';
$statement = Database::connect()->prepare(
    'SELECT p.*, u.first_name, u.last_name, u.bio, u.email AS owner_email,
        (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM properties p LEFT JOIN users u ON u.id = p.user_id
     WHERE p.id = :id' . $visibilityCondition
);
$statement->execute(['id' => $propertyId]);
$property = $statement->fetch();
if (!$property) {
    http_response_code(404);
    exit('Cette annonce n’est plus disponible.');
}

$imagesQuery = Database::connect()->prepare(
    'SELECT image_url FROM property_images WHERE property_id = :property_id ORDER BY position'
);
$imagesQuery->execute(['property_id' => $propertyId]);
$galleryImages = array_column($imagesQuery->fetchAll(), 'image_url');
if (!$galleryImages) {
    $galleryImages = [$property['image_url']];
} else {
    $property['image_url'] = $galleryImages[0];
}

$isFavorite = false;
if ($user) {
    $favoriteQuery = Database::connect()->prepare('SELECT 1 FROM favorites WHERE user_id = :user_id AND property_id = :property_id');
    $favoriteQuery->execute(['user_id' => $user['id'], 'property_id' => $propertyId]);
    $isFavorite = (bool) $favoriteQuery->fetchColumn();
} else {
    $favoriteQuery = Database::connect()->prepare('SELECT 1 FROM favorites WHERE guest_token = :guest_token AND property_id = :property_id');
    $favoriteQuery->execute(['guest_token' => guest_favorite_token(), 'property_id' => $propertyId]);
    $isFavorite = (bool) $favoriteQuery->fetchColumn();
}
$typeLabels = ['apartment' => 'Appartement', 'house' => 'Maison / villa', 'land' => 'Terrain', 'commercial' => 'Local commercial'];
$returnTo = 'property.php?id=' . (int) $propertyId;
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($property['title']) ?> — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=9"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section detail-page">
    <a class="back-link" href="<?= $isAdmin ? 'admin/index.php' : 'index.php#annonces' ?>"><?= $isAdmin ? '← Retour à l’administration' : '← Retour aux annonces' ?></a>
    <div class="detail-hero">
        <button class="detail-hero-image" type="button" data-gallery-image="<?= e($property['image_url']) ?>" data-gallery-alt="<?= e($property['title']) ?>" aria-label="Agrandir l’image principale">
            <img src="<?= e($property['image_url']) ?>" alt="<?= e($property['title']) ?>">
        </button>
        <span class="property-badge"><?= $property['transaction_type'] === 'rent' ? 'À LOUER' : 'À VENDRE' ?></span>
        <?php if ($isAdmin && $property['status'] !== 'published'): ?><span class="property-badge detail-status-badge">ADMIN · <?= e(strtoupper($property['status'])) ?></span><?php endif; ?>
    </div>
    <?php if (count($galleryImages) > 1): ?>
        <div class="detail-gallery" aria-label="Autres images du bien">
            <?php foreach (array_slice($galleryImages, 1) as $index => $image): ?>
                <button class="detail-gallery-image" type="button" data-gallery-image="<?= e($image) ?>" data-gallery-alt="<?= e($property['title'] . ' — image ' . ($index + 2)) ?>" aria-label="Agrandir l’image <?= $index + 2 ?>">
                    <img src="<?= e($image) ?>" alt="" loading="lazy">
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="detail-layout">
        <article class="detail-content">
            <div class="detail-location"><span aria-hidden="true">⌖</span> <?= e($property['city']) ?><?= $property['district'] ? ' · ' . e($property['district']) : '' ?></div>
            <h1><?= e($property['title']) ?></h1>
            <div class="detail-facts"><span><?= (int) $property['area'] ?> m²</span><span><?= (int) $property['bedrooms'] ?> chambres</span><span><?= e($typeLabels[$property['property_type']] ?? $property['property_type']) ?></span></div>
            <?php if ($isAdmin && $property['rejection_reason']): ?><p class="rejection-note"><strong>Motif de refus :</strong> <?= e($property['rejection_reason']) ?></p><?php endif; ?>
            <section class="detail-description"><h2>À propos de ce lieu</h2><p><?= nl2br(e($property['description'])) ?></p></section>
            <section class="seller-card">
                <div class="seller-avatar"><?= e(strtoupper(substr($property['first_name'] ?: 'H', 0, 1))) ?></div>
                <div><span class="eyebrow">VOTRE CONTACT</span><strong><?= e(trim(($property['first_name'] ?? '') . ' ' . ($property['last_name'] ?? '')) ?: 'Équipe Habita') ?></strong><p><?= e($property['bio'] ?: 'À votre écoute pour vous accompagner dans votre projet.') ?></p></div>
                <?php if ($property['user_id']): ?><a class="text-link" href="profil.php?id=<?= (int) $property['user_id'] ?>">Voir le profil →</a><?php endif; ?>
            </section>
        </article>
        <aside class="detail-sidebar">
            <span class="eyebrow"><?= $property['transaction_type'] === 'rent' ? 'LOYER MENSUEL' : 'PRIX DU BIEN' ?></span>
            <div class="detail-price"><?= e(format_price($property['price'], $property['transaction_type'])) ?></div>
            <div class="like-count" data-favorite-summary="<?= (int) $property['id'] ?>"><span data-favorite-count="<?= (int) $property['id'] ?>"><?= (int) $property['favorite_count'] ?></span><span data-favorite-label> personne<?= (int) $property['favorite_count'] === 1 ? '' : 's' ?> aime<?= (int) $property['favorite_count'] === 1 ? '' : 'nt' ?> ce bien</span></div>
            <?php if ($property['status'] === 'published'): ?>
                <form class="favorite-form" action="favoris.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                    <button class="button favorite-toggle-button <?= $isFavorite ? 'is-favorite button-outline' : 'button-dark' ?> button-full" type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"><?= $isFavorite ? '♥  Ne plus aimer' : '♡  J’aime cet article' ?></button>
                </form>
                <?php if (!$isAdmin): ?><a class="button button-outline button-full contact-button" href="paiement-momo.php?id=<?= (int) $property['id'] ?>">Réserver avec un acompte MoMo (10 %)</a><?php endif; ?>
            <?php endif; ?>
            <?php if ($property['status'] === 'published' && !empty($property['owner_email'])): ?>
                <a class="button button-outline button-full contact-button" href="mailto:<?= e($property['owner_email']) ?>?subject=<?= rawurlencode('À propos du bien : ' . $property['title']) ?>">Contacter le propriétaire</a>
                <div class="sidebar-note">Une question ? Le propriétaire pourra vous répondre directement par e-mail.</div>
            <?php elseif ($property['status'] === 'published'): ?>
                <div class="sidebar-note">Cette annonce de démonstration n'est pas rattachée à un propriétaire.</div>
            <?php elseif ($isAdmin): ?>
                <div class="sidebar-note">Fiche privée réservée à l’administration : cette annonce n’est pas encore publiée.</div>
            <?php endif; ?>
        </aside>
    </div>
</main>
<dialog class="image-zoom-dialog" aria-label="Image agrandie">
    <button class="image-zoom-close" type="button" aria-label="Fermer">×</button>
    <img src="" alt="">
</dialog>
<script>
    const zoomDialog = document.querySelector('.image-zoom-dialog');
    const zoomImage = zoomDialog.querySelector('img');
    document.querySelectorAll('[data-gallery-image]').forEach((button) => {
        button.addEventListener('click', () => {
            zoomImage.src = button.dataset.galleryImage;
            zoomImage.alt = button.dataset.galleryAlt;
            zoomDialog.showModal();
        });
    });
    zoomDialog.querySelector('.image-zoom-close').addEventListener('click', () => zoomDialog.close());
    zoomDialog.addEventListener('click', (event) => {
        if (event.target === zoomDialog) zoomDialog.close();
    });
</script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
