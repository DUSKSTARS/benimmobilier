<?php
$isFavorite = false;
if ($user) {
    $favoriteCheck = Database::connect()->prepare('SELECT 1 FROM favorites WHERE user_id = :user_id AND property_id = :property_id');
    $favoriteCheck->execute(['user_id' => $user['id'], 'property_id' => $property['id']]);
    $isFavorite = (bool) $favoriteCheck->fetchColumn();
} else {
    $favoriteCheck = Database::connect()->prepare('SELECT 1 FROM favorites WHERE guest_token = :guest_token AND property_id = :property_id');
    $favoriteCheck->execute(['guest_token' => guest_favorite_token(), 'property_id' => $property['id']]);
    $isFavorite = (bool) $favoriteCheck->fetchColumn();
}
?>
<article class="property-card">
    <a class="property-image-link" href="property.php?id=<?= (int) $property['id'] ?>">
        <img class="property-image" src="<?= e($property['image_url']) ?>" alt="<?= e($property['title']) ?>" loading="lazy">
        <span class="property-badge"><?= $property['transaction_type'] === 'rent' ? 'À LOUER' : 'À VENDRE' ?></span>
    </a>
    <form class="favorite-form" action="favoris.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
        <input type="hidden" name="return_to" value="<?= e($returnTo ?? 'index.php') ?>">
        <button class="favorite-button <?= $isFavorite ? 'is-favorite' : '' ?>" type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" aria-label="<?= $isFavorite ? 'Retirer des favoris' : 'Aimer cet article' ?>" title="<?= $isFavorite ? 'Retirer des favoris' : 'Aimer cet article' ?>"><?= $isFavorite ? '♥' : '♡' ?></button>
    </form>
    <div class="property-details">
        <div class="property-price"><?= e(format_price($property['price'], $property['transaction_type'])) ?></div>
        <a class="property-title" href="property.php?id=<?= (int) $property['id'] ?>"><?= e($property['title']) ?></a>
        <div class="property-location"><span aria-hidden="true">⌖</span> <?= e($property['city']) ?><?= $property['district'] ? ', ' . e($property['district']) : '' ?></div>
        <div class="property-features"><span><?= (int) $property['bedrooms'] ?> chambres</span><span><?= (int) $property['area'] ?> m²</span><span><span data-favorite-count="<?= (int) $property['id'] ?>"><?= (int) $property['favorite_count'] ?></span> ♥</span></div>
        <?php if (!empty($property['user_id'])): ?><a class="property-owner" href="profil.php?id=<?= (int) $property['user_id'] ?>">Par <?= e(trim(($property['first_name'] ?? '') . ' ' . ($property['last_name'] ?? ''))) ?></a><?php else: ?><span class="property-owner">Sélection Habita</span><?php endif; ?>
    </div>
</article>
