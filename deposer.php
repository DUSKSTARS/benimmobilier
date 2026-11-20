<?php
require_once __DIR__ . '/includes/app.php';
$user = require_auth();
$error = '';
$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$listing = null;
$listingImages = [];
$listingVideos = [];
if (isset($_GET['id'])) {
    if (!$editId || $editId < 1) {
        http_response_code(404);
        exit('Annonce introuvable.');
    }
    $existing = Database::connect()->prepare('SELECT * FROM properties WHERE id = :id AND user_id = :user_id');
    $existing->execute(['id' => $editId, 'user_id' => $user['id']]);
    $listing = $existing->fetch();
    if (!$listing) {
        http_response_code(404);
        exit('Annonce introuvable.');
    }
    $imageQuery = Database::connect()->prepare(
        'SELECT image_url FROM property_images WHERE property_id = :property_id ORDER BY position'
    );
    $imageQuery->execute(['property_id' => $editId]);
    $listingImages = array_column($imageQuery->fetchAll(), 'image_url');
    if (!$listingImages) {
        $listingImages = [$listing['image_url']];
    }
    $videoQuery = Database::connect()->prepare(
        'SELECT video_url FROM property_videos WHERE property_id = :property_id ORDER BY id'
    );
    $videoQuery->execute(['property_id' => $editId]);
    $listingVideos = array_values(array_filter(
        array_column($videoQuery->fetchAll(), 'video_url'),
        static fn (string $path): bool => property_video_mime_type($path) !== null
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim(post_string('title'));
    $description = trim(post_string('description'));
    $city = trim(post_string('city'));
    $district = trim(post_string('district'));
    $address = trim(post_string('address'));
    try {
        [$latitude, $longitude] = parse_location_coordinates(
            post_string('location_latitude'),
            post_string('location_longitude')
        );
    } catch (RuntimeException $exception) {
        $latitude = $longitude = null;
        $error = $exception->getMessage();
    }
    $transactionType = post_string('transaction_type');
    $propertyType = post_string('property_type');
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_INT);
    $area = filter_input(INPUT_POST, 'area', FILTER_VALIDATE_INT);
    $bedrooms = filter_input(INPUT_POST, 'bedrooms', FILTER_VALIDATE_INT);
    $livingRooms = filter_input(INPUT_POST, 'living_rooms', FILTER_VALIDATE_INT);
    $bathrooms = filter_input(INPUT_POST, 'bathrooms', FILTER_VALIDATE_INT);
    $images = $listingImages;
    $newImages = [];
    $newVideos = [];
    $validTypes = ['apartment', 'house', 'land', 'commercial', 'villa', 'studio', 'office', 'shop', 'building'];

    if ($error === '' && $latitude === null) {
        $error = 'Choisissez le point du bien sur la carte ou utilisez votre position actuelle.';
    } elseif ($error === '' && ($title === '' || $description === '' || $city === '' || $address === ''
        || !in_array($transactionType, ['sale', 'rent'], true) || !in_array($propertyType, $validTypes, true)
    )) {
        $error = 'Complétez tous les champs obligatoires.';
    } elseif ($error === '' && ($price === false || $price < 1 || $area === false || $area < 1
        || $bedrooms === false || $bedrooms < 0 || $livingRooms === false || $livingRooms < 0
        || $bathrooms === false || $bathrooms < 0)) {
        $error = 'Le prix, la surface et le nombre de pièces doivent être valides.';
    } elseif ($error === '' && (strlen($title) > 160 || strlen($city) > 100 || strlen($district) > 100
        || strlen($address) > 255 || strlen($description) > 5000)) {
        $error = 'Certains champs dépassent la longueur autorisée.';
    }

    if ($error === '') {
        $imageUploads = [];
        for ($imageNumber = 1; $imageNumber <= 4; $imageNumber++) {
            $fieldName = 'property_image_' . $imageNumber;
            if (isset($_FILES[$fieldName]) && is_array($_FILES[$fieldName])) {
                $imageUploads[$imageNumber] = $_FILES[$fieldName];
            }
        }

        $hasNewImage = false;
        foreach ($imageUploads as $imageUpload) {
            if (($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $hasNewImage = true;
                break;
            }
        }

        if ((!$listing || $hasNewImage)
            && (!isset($imageUploads[1]['error']) || $imageUploads[1]['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = 'Choisissez une image principale dans le premier champ.';
        }

        if ($error === '') {
            try {
                $newImages = save_property_photos($imageUploads);
                $newVideos = save_property_videos($_FILES['property_videos'] ?? []);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
                delete_property_photos($newImages);
                delete_property_videos($newVideos);
            }
        }
        if ($error === '' && $newImages) {
            $images = $newImages;
        } elseif ($error === '' && !$images) {
            $error = 'Choisissez au moins une image depuis votre appareil.';
        }

        if ($error === '') {
            $db = Database::connect();
            $values = [
                'title' => $title, 'description' => $description, 'city' => $city,
                'district' => $district !== '' ? $district : null, 'address' => $address,
                'location_latitude' => $latitude,
                'location_longitude' => $longitude,
                'transaction_type' => $transactionType, 'property_type' => $propertyType,
                'price' => $price, 'area' => $area, 'bedrooms' => $bedrooms,
                'living_rooms' => $livingRooms, 'bathrooms' => $bathrooms,
                'image_url' => $images[0],
            ];
            $db->beginTransaction();
            try {
                if ($listing) {
                    $values['id'] = $listing['id'];
                    $values['user_id'] = $user['id'];
                    $statement = $db->prepare(
                        'UPDATE properties SET title = :title, description = :description, city = :city, district = :district,
                            address = :address, location_latitude = :location_latitude,
                            location_longitude = :location_longitude,
                            transaction_type = :transaction_type, property_type = :property_type, price = :price, area = :area,
                            bedrooms = :bedrooms, living_rooms = :living_rooms, bathrooms = :bathrooms,
                            image_url = :image_url, status = "pending",
                            rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL
                         WHERE id = :id AND user_id = :user_id'
                    );
                    $statement->execute($values);
                    $propertyId = (int) $listing['id'];
                } else {
                    $values['user_id'] = $user['id'];
                    $statement = $db->prepare(
                        'INSERT INTO properties (user_id, title, description, city, district, address, location_latitude, location_longitude, transaction_type, property_type, price, area, bedrooms, living_rooms, bathrooms, image_url, status)
                         VALUES (:user_id, :title, :description, :city, :district, :address, :location_latitude, :location_longitude, :transaction_type, :property_type, :price, :area, :bedrooms, :living_rooms, :bathrooms, :image_url, "pending")'
                    );
                    $statement->execute($values);
                    $propertyId = (int) $db->lastInsertId();
                }

                $deleteImages = $db->prepare('DELETE FROM property_images WHERE property_id = :property_id');
                $deleteImages->execute(['property_id' => $propertyId]);
                $insertImage = $db->prepare(
                    'INSERT INTO property_images (property_id, image_url, position) VALUES (:property_id, :image_url, :position)'
                );
                foreach ($images as $index => $image) {
                    $insertImage->execute([
                        'property_id' => $propertyId,
                        'image_url' => $image,
                        'position' => $index + 1,
                    ]);
                }
                if ($newVideos) {
                    $db->prepare('DELETE FROM property_videos WHERE property_id = :property_id')
                        ->execute(['property_id' => $propertyId]);
                    $insertVideo = $db->prepare(
                        'INSERT INTO property_videos (property_id, video_url) VALUES (:property_id, :video_url)'
                    );
                    foreach ($newVideos as $videoPath) {
                        $insertVideo->execute(['property_id' => $propertyId, 'video_url' => $videoPath]);
                    }
                }
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                delete_property_photos($newImages);
                delete_property_videos($newVideos);
                throw $exception;
            }

            if ($newImages) {
                delete_property_photos($listingImages);
            }
            if ($newVideos) {
                delete_property_videos($listingVideos);
            }
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $listing
                    ? 'Votre annonce a été renvoyée à l’administration pour validation.'
                    : 'Votre annonce a été envoyée à l’administration pour validation.',
            ];
            redirect('mon-espace.php');
        }
    }
}
$fieldValue = static function (string $field, $default = '') use ($listing): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return post_string($field);
    }

    return (string) ($listing[$field] ?? $default);
};
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Déposer une annonce — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=11"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section form-page">
    <div class="form-intro"><span class="eyebrow">FAITES DE LA PLACE AUX NOUVEAUX PROJETS</span><h1><?= $listing ? 'Faites évoluer<br>votre annonce.' : 'Votre bien mérite<br>sa belle rencontre.' ?></h1><p><?= $listing ? 'Mettez à jour les informations de votre bien. Toute modification sera de nouveau vérifiée.' : 'Décrivez votre bien. Il sera publié après validation par l’administration.' ?></p></div>
    <form class="listing-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <h2><?= $listing ? 'Modifier les détails du bien' : 'Les détails du bien' ?></h2>
        <label>Titre de l'annonce<input name="title" maxlength="160" required placeholder="Ex. Villa lumineuse avec jardin" value="<?= e($fieldValue('title')) ?>"></label>
        <label>Description<textarea name="description" rows="5" maxlength="5000" required placeholder="Racontez ce qui rend ce lieu unique…"><?= e($fieldValue('description')) ?></textarea></label>
        <div class="form-row"><label>Type de transaction<select name="transaction_type" required><option value="sale" <?= $fieldValue('transaction_type', 'sale') === 'sale' ? 'selected' : '' ?>>À vendre</option><option value="rent" <?= $fieldValue('transaction_type') === 'rent' ? 'selected' : '' ?>>À louer</option></select></label><label>Type de bien<select name="property_type" required><?php foreach (['house' => 'Maison', 'apartment' => 'Appartement', 'land' => 'Terrain', 'villa' => 'Villa', 'studio' => 'Studio', 'office' => 'Bureau', 'shop' => 'Magasin', 'building' => 'Immeuble', 'commercial' => 'Local commercial'] as $typeValue => $typeLabel): ?><option value="<?= e($typeValue) ?>" <?= $fieldValue('property_type', 'house') === $typeValue ? 'selected' : '' ?>><?= e($typeLabel) ?></option><?php endforeach; ?></select></label></div>
        <div class="form-row"><label>Prix en FCFA<input type="number" name="price" min="1" step="1" required value="<?= e($fieldValue('price')) ?>"></label><label>Surface en m²<input type="number" name="area" min="1" step="1" required value="<?= e($fieldValue('area')) ?>"></label><label>Chambres<input type="number" name="bedrooms" min="0" step="1" required value="<?= e($fieldValue('bedrooms', '0')) ?>"></label></div>
        <div class="form-row"><label>Salons<input type="number" name="living_rooms" min="0" step="1" required value="<?= e($fieldValue('living_rooms', '0')) ?>"></label><label>Salles de bains<input type="number" name="bathrooms" min="0" step="1" required value="<?= e($fieldValue('bathrooms', '0')) ?>"></label></div>
        <div class="form-row"><label>Ville<input name="city" maxlength="100" required placeholder="Ex. Cotonou" value="<?= e($fieldValue('city')) ?>"></label><label>Quartier<input name="district" maxlength="100" placeholder="Ex. Haie Vive" value="<?= e($fieldValue('district')) ?>"></label></div>
        <label>Adresse complète<input name="address" maxlength="255" required placeholder="Rue, numéro, quartier" value="<?= e($fieldValue('address')) ?>"></label>
        <div class="location-picker" data-location-picker>
            <span class="optional">Localisation du bien</span>
            <p class="map-help">Cliquez sur la carte pour choisir un point, ou utilisez votre position actuelle.</p>
            <button class="button button-outline" type="button" data-use-current-location>Utiliser ma position actuelle</button>
            <div class="property-map" data-map-canvas aria-label="Carte pour choisir la localisation"></div>
            <p class="map-status" data-map-status aria-live="polite">Aucun point choisi.</p>
            <input type="hidden" name="location_latitude" value="<?= e($fieldValue('location_latitude')) ?>">
            <input type="hidden" name="location_longitude" value="<?= e($fieldValue('location_longitude')) ?>">
        </div>
        <div class="property-image-fields">
            <span class="eyebrow">PHOTOS DU BIEN</span>
            <small><?= $listingImages ? 'Choisissez une ou plusieurs nouvelles images pour remplacer celles enregistrées. Les champs laissés vides sont ignorés ; sans nouvelle image, les images actuelles sont conservées.' : 'Chaque champ accepte une seule image JPG, PNG ou WebP (5 Mo maximum). La première image est obligatoire et servira de couverture.' ?></small>
            <?php for ($imageNumber = 1; $imageNumber <= 4; $imageNumber++): ?>
                <label><?= $imageNumber === 1 ? 'Image principale' : 'Image complémentaire ' . $imageNumber ?>
                    <input class="property-image-input" type="file" name="property_image_<?= $imageNumber ?>" accept="image/jpeg,image/png,image/webp" <?= !$listingImages && $imageNumber === 1 ? 'required' : '' ?>>
                </label>
            <?php endfor; ?>
        </div>
        <div id="property-image-preview" class="upload-preview" aria-live="polite"></div>
        <?php if ($listingImages): ?>
            <div class="current-listing-images">
                <span class="optional">Images actuellement enregistrées</span>
                <div class="upload-preview">
                    <?php foreach ($listingImages as $index => $image): ?>
                        <img src="<?= e($image) ?>" alt="<?= $index === 0 ? 'Image principale actuelle' : 'Image actuelle ' . ($index + 1) ?>" loading="lazy">
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="property-image-fields">
            <span class="eyebrow">VIDÉOS DE PRÉSENTATION</span>
            <small>Sélectionnez jusqu’à trois vidéos MP4, WebM ou OGG depuis votre appareil (100 Mo maximum chacune). Les vidéos actuelles restent si aucune nouvelle n’est choisie.</small>
            <label>Fichiers vidéo<input type="file" name="property_videos[]" accept="video/mp4,video/webm,video/ogg" multiple></label>
            <?php if ($listingVideos): ?>
                <div class="video-preview-grid">
                    <?php foreach ($listingVideos as $videoPath): ?><video controls preload="metadata"><source src="<?= e($videoPath) ?>" type="<?= e(property_video_mime_type($videoPath)) ?>"></video><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <button class="button button-dark" type="submit"><?= $listing ? 'Enregistrer les modifications' : 'Publier mon annonce' ?> <span aria-hidden="true">→</span></button>
    </form>
</main>
<script>
    const imagePreview = document.querySelector('#property-image-preview');
    const imageInputs = document.querySelectorAll('.property-image-input');
    const previewUrls = [];

    const updateMainImageRequirement = () => {
        if (imageInputs.length > 0) {
            imageInputs[0].required = <?= $listingImages ? 'false' : 'true' ?> || Array.from(imageInputs).slice(1).some((input) => input.files.length > 0);
        }
    };

    imageInputs.forEach((input) => {
        input.addEventListener('change', () => {
            previewUrls.forEach((url) => URL.revokeObjectURL(url));
            previewUrls.length = 0;
            imagePreview.replaceChildren();
            updateMainImageRequirement();

            imageInputs.forEach((selectedInput, selectedIndex) => {
                const file = selectedInput.files[0];
                if (!file) return;

                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                    selectedInput.setCustomValidity('Choisissez une image JPG, PNG ou WebP de 5 Mo maximum.');
                    selectedInput.reportValidity();
                    selectedInput.value = '';
                    selectedInput.setCustomValidity('');
                    return;
                }

                selectedInput.setCustomValidity('');
                const url = URL.createObjectURL(file);
                previewUrls.push(url);
                const preview = document.createElement('img');
                preview.src = url;
                preview.alt = selectedIndex === 0 ? 'Aperçu de l’image principale sélectionnée' : `Aperçu de l’image ${selectedIndex + 1}`;
                imagePreview.append(preview);
            });
        });
    });
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="js/location-map.js"></script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
