<?php
require_once __DIR__ . '/includes/app.php';
$user = require_auth();
$error = '';
$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$listing = null;
$listingImages = [];
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim(post_string('title'));
    $description = trim(post_string('description'));
    $city = trim(post_string('city'));
    $district = trim(post_string('district'));
    $transactionType = post_string('transaction_type');
    $propertyType = post_string('property_type');
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_INT);
    $area = filter_input(INPUT_POST, 'area', FILTER_VALIDATE_INT);
    $bedrooms = filter_input(INPUT_POST, 'bedrooms', FILTER_VALIDATE_INT);
    $images = $listingImages;
    $newImages = [];
    $validTypes = ['apartment', 'house', 'land', 'commercial'];

    if ($title === '' || $description === '' || $city === '' || !in_array($transactionType, ['sale', 'rent'], true) || !in_array($propertyType, $validTypes, true)) {
        $error = 'Complétez tous les champs obligatoires.';
    } elseif ($price === false || $price < 1 || $area === false || $area < 1 || $bedrooms === false || $bedrooms < 0) {
        $error = 'Le prix, la surface et le nombre de chambres doivent être valides.';
    } elseif (strlen($title) > 160 || strlen($city) > 100 || strlen($district) > 100 || strlen($description) > 5000) {
        $error = 'Certains champs dépassent la longueur autorisée.';
    } else {
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

        try {
            if ($error === '') {
                $newImages = save_property_photos($imageUploads);
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
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
                'district' => $district !== '' ? $district : null, 'transaction_type' => $transactionType,
                'property_type' => $propertyType, 'price' => $price, 'area' => $area, 'bedrooms' => $bedrooms,
                'image_url' => $images[0],
            ];
            $db->beginTransaction();
            try {
                if ($listing) {
                    $values['id'] = $listing['id'];
                    $values['user_id'] = $user['id'];
                    $statement = $db->prepare(
                        'UPDATE properties SET title = :title, description = :description, city = :city, district = :district,
                            transaction_type = :transaction_type, property_type = :property_type, price = :price, area = :area,
                            bedrooms = :bedrooms, image_url = :image_url, status = "pending",
                            rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL
                         WHERE id = :id AND user_id = :user_id'
                    );
                    $statement->execute($values);
                    $propertyId = (int) $listing['id'];
                } else {
                    $values['user_id'] = $user['id'];
                    $statement = $db->prepare(
                        'INSERT INTO properties (user_id, title, description, city, district, transaction_type, property_type, price, area, bedrooms, image_url, status)
                         VALUES (:user_id, :title, :description, :city, :district, :transaction_type, :property_type, :price, :area, :bedrooms, :image_url, "pending")'
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
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                delete_property_photos($newImages);
                throw $exception;
            }

            if ($newImages) {
                delete_property_photos($listingImages);
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Déposer une annonce — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=9"></head>
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
        <div class="form-row"><label>Type de transaction<select name="transaction_type" required><option value="sale" <?= $fieldValue('transaction_type', 'sale') === 'sale' ? 'selected' : '' ?>>À vendre</option><option value="rent" <?= $fieldValue('transaction_type') === 'rent' ? 'selected' : '' ?>>À louer</option></select></label><label>Type de bien<select name="property_type" required><option value="house" <?= $fieldValue('property_type', 'house') === 'house' ? 'selected' : '' ?>>Maison / villa</option><option value="apartment" <?= $fieldValue('property_type') === 'apartment' ? 'selected' : '' ?>>Appartement</option><option value="land" <?= $fieldValue('property_type') === 'land' ? 'selected' : '' ?>>Terrain</option><option value="commercial" <?= $fieldValue('property_type') === 'commercial' ? 'selected' : '' ?>>Local commercial</option></select></label></div>
        <div class="form-row"><label>Prix en FCFA<input type="number" name="price" min="1" step="1" required value="<?= e($fieldValue('price')) ?>"></label><label>Surface en m²<input type="number" name="area" min="1" step="1" required value="<?= e($fieldValue('area')) ?>"></label><label>Chambres<input type="number" name="bedrooms" min="0" step="1" required value="<?= e($fieldValue('bedrooms', '0')) ?>"></label></div>
        <div class="form-row"><label>Ville<input name="city" maxlength="100" required placeholder="Ex. Cotonou" value="<?= e($fieldValue('city')) ?>"></label><label>Quartier<input name="district" maxlength="100" placeholder="Ex. Haie Vive" value="<?= e($fieldValue('district')) ?>"></label></div>
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
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
