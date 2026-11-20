<?php
require_once __DIR__ . '/../includes/app.php';
$admin = require_admin();
$db = Database::connect();
$error = '';
$propertyTypes = [
    'house' => 'Maison', 'apartment' => 'Appartement', 'land' => 'Terrain',
    'villa' => 'Villa', 'studio' => 'Studio', 'office' => 'Bureau',
    'shop' => 'Magasin', 'building' => 'Immeuble', 'commercial' => 'Local commercial',
];
$operationalStatuses = [
    'available' => 'Disponible', 'reserved' => 'Réservé', 'rented' => 'Loué',
    'sold' => 'Vendu', 'maintenance' => 'En travaux',
];
$validDate = static function (string $date): bool {
    $parts = explode('-', $date);

    return count($parts) === 3 && strlen($parts[0]) === 4 && strlen($parts[1]) === 2
        && strlen($parts[2]) === 2 && ctype_digit(implode('', $parts))
        && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
};

// Record auditable actions in the property dashboard.
$recordActivity = static function (string $activity, ?int $propertyId = null) use ($db, $admin): void {
    $statement = $db->prepare(
        'INSERT INTO property_activity (property_id, actor_id, activity) VALUES (:property_id, :actor_id, :activity)'
    );
    $statement->execute([
        'property_id' => $propertyId,
        'actor_id' => $admin['id'],
        'activity' => $activity,
    ]);
};

// Validate and save owner documents outside the public web directory.
$saveOwnerDocument = static function (array $file, string $documentType, int $ownerId) use ($db): void {
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if ($uploadError !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name']) || !is_int($file['size'])
        || !is_uploaded_file($file['tmp_name']) || $file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Chaque document doit peser au maximum 5 Mo.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new RuntimeException('Les documents doivent être au format PDF, JPG ou PNG.');
    }

    $directory = dirname(__DIR__) . '/private/owner-documents';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Le dossier sécurisé des documents ne peut pas être créé.');
    }
    $storedName = bin2hex(random_bytes(32));
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $storedName)) {
        throw new RuntimeException('Impossible d’enregistrer le document du propriétaire.');
    }

    $clientName = is_string($file['name'] ?? null) ? $file['name'] : 'document';
    $originalName = basename($clientName);
    $originalName = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $originalName), 0, 255);
    $statement = $db->prepare(
        'INSERT INTO owner_documents (owner_id, document_type, original_name, stored_name, mime_type)
         VALUES (:owner_id, :document_type, :original_name, :stored_name, :mime_type)'
    );
    try {
        $statement->execute([
            'owner_id' => $ownerId,
            'document_type' => $documentType,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $mime,
        ]);
    } catch (Throwable $exception) {
        unlink($directory . '/' . $storedName);
        throw $exception;
    }
};

// Handle authenticated management forms with CSRF protection and server-side validation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'save_property') {
            $propertyIdInput = post_string('property_id');
            $id = $propertyIdInput === '' ? null : filter_var($propertyIdInput, FILTER_VALIDATE_INT);
            $title = trim(post_string('title'));
            $description = trim(post_string('description'));
            $city = trim(post_string('city'));
            $district = trim(post_string('district'));
            $address = trim(post_string('address'));
            [$latitude, $longitude] = parse_location_coordinates(
                post_string('location_latitude'),
                post_string('location_longitude')
            );
            if ($latitude === null) {
                throw new RuntimeException('Choisissez le point du bien sur la carte ou utilisez votre position actuelle.');
            }
            $transactionType = post_string('transaction_type');
            $propertyType = post_string('property_type');
            $operationalStatus = post_string('operational_status');
            $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_INT);
            $area = filter_input(INPUT_POST, 'area', FILTER_VALIDATE_INT);
            $bedrooms = filter_input(INPUT_POST, 'bedrooms', FILTER_VALIDATE_INT);
            $livingRooms = filter_input(INPUT_POST, 'living_rooms', FILTER_VALIDATE_INT);
            $bathrooms = filter_input(INPUT_POST, 'bathrooms', FILTER_VALIDATE_INT);
            $ownerIdInput = post_string('owner_id');
            $ownerId = $ownerIdInput === '' ? null : filter_var($ownerIdInput, FILTER_VALIDATE_INT);
            if ($title === '' || $description === '' || $city === '' || $address === ''
                || !in_array($transactionType, ['sale', 'rent'], true)
                || !isset($propertyTypes[$propertyType]) || !isset($operationalStatuses[$operationalStatus])
                || ($propertyIdInput !== '' && (!is_int($id) || $id < 1))
                || $price === false || $price < 1 || $area === false || $area < 1
                || $bedrooms === false || $bedrooms < 0 || $livingRooms === false || $livingRooms < 0
                || $bathrooms === false || $bathrooms < 0
                || ($ownerId !== null && (!is_int($ownerId) || $ownerId < 1))) {
                throw new RuntimeException('Vérifiez les champs obligatoires, les montants et l’adresse de localisation.');
            }
            if (strlen($title) > 160 || strlen($city) > 100 || strlen($district) > 100
                || strlen($address) > 255 || strlen($description) > 5000) {
                throw new RuntimeException('Un des champs du bien dépasse la longueur autorisée.');
            }

            $imageUploads = [];
            for ($imageNumber = 1; $imageNumber <= 4; $imageNumber++) {
                $fieldName = 'property_image_' . $imageNumber;
                if (isset($_FILES[$fieldName]) && is_array($_FILES[$fieldName])) {
                    $imageUploads[$imageNumber] = $_FILES[$fieldName];
                }
            }
            $existing = null;
            if ($id !== null && $id > 0) {
                $existingQuery = $db->prepare('SELECT * FROM properties WHERE id = :id');
                $existingQuery->execute(['id' => $id]);
                $existing = $existingQuery->fetch();
                if (!$existing) {
                    throw new RuntimeException('Le bien à modifier est introuvable.');
                }
            }

            $newImages = [];
            $newVideos = [];
            try {
                $newImages = save_property_photos($imageUploads);
                $newVideos = save_property_videos($_FILES['property_videos'] ?? []);
            } catch (RuntimeException $exception) {
                delete_property_photos($newImages);
                delete_property_videos($newVideos);
                throw $exception;
            }
            $images = $newImages;
            $oldImages = [];
            $oldVideos = [];
            if (!$images && $existing) {
                $imageQuery = $db->prepare('SELECT image_url FROM property_images WHERE property_id = :id ORDER BY position');
                $imageQuery->execute(['id' => $id]);
                $images = array_column($imageQuery->fetchAll(), 'image_url');
                if (!$images) {
                    $images = [$existing['image_url']];
                }
            } elseif ($existing) {
                $imageQuery = $db->prepare('SELECT image_url FROM property_images WHERE property_id = :id ORDER BY position');
                $imageQuery->execute(['id' => $id]);
                $oldImages = array_column($imageQuery->fetchAll(), 'image_url');
                if (!$oldImages) {
                    $oldImages = [$existing['image_url']];
                }
            }
            if ($existing) {
                $oldVideoQuery = $db->prepare('SELECT video_url FROM property_videos WHERE property_id = :id');
                $oldVideoQuery->execute(['id' => $id]);
                $oldVideos = array_column($oldVideoQuery->fetchAll(), 'video_url');
            }
            if (!$images) {
                delete_property_photos($newImages);
                delete_property_videos($newVideos);
                throw new RuntimeException('Ajoutez au moins une photo au bien.');
            }

            $values = [
                'title' => $title, 'description' => $description, 'city' => $city,
                'district' => $district !== '' ? $district : null, 'address' => $address,
                'location_latitude' => $latitude, 'location_longitude' => $longitude,
                'transaction_type' => $transactionType, 'property_type' => $propertyType,
                'price' => $price, 'area' => $area, 'bedrooms' => $bedrooms,
                'living_rooms' => $livingRooms, 'bathrooms' => $bathrooms,
                'operational_status' => $operationalStatus, 'owner_id' => $ownerId ?: null,
                'image_url' => $images[0],
            ];
            $db->beginTransaction();
            try {
                if ($existing) {
                    $values['id'] = $id;
                    $statement = $db->prepare(
                        'UPDATE properties SET title = :title, description = :description, city = :city, district = :district,
                            address = :address, location_latitude = :location_latitude,
                            location_longitude = :location_longitude, transaction_type = :transaction_type,
                            property_type = :property_type, price = :price, area = :area, bedrooms = :bedrooms,
                            living_rooms = :living_rooms, bathrooms = :bathrooms, operational_status = :operational_status,
                            owner_id = :owner_id, image_url = :image_url WHERE id = :id'
                    );
                    $statement->execute($values);
                    $propertyId = (int) $id;
                    $activity = 'Informations du bien modifiées : ' . $title;
                } else {
                    $values['user_id'] = null;
                    $statement = $db->prepare(
                        'INSERT INTO properties
                            (user_id, owner_id, title, description, city, district, address, location_url,
                             location_latitude, location_longitude, transaction_type,
                             property_type, price, area, bedrooms, living_rooms, bathrooms, operational_status, image_url, status)
                         VALUES
                            (:user_id, :owner_id, :title, :description, :city, :district, :address, NULL,
                             :location_latitude, :location_longitude, :transaction_type,
                             :property_type, :price, :area, :bedrooms, :living_rooms, :bathrooms, :operational_status, :image_url, "published")'
                    );
                    $statement->execute($values);
                    $propertyId = (int) $db->lastInsertId();
                    $activity = 'Bien ajouté : ' . $title;
                }

                $db->prepare('DELETE FROM property_images WHERE property_id = :id')->execute(['id' => $propertyId]);
                $insertImage = $db->prepare(
                    'INSERT INTO property_images (property_id, image_url, position) VALUES (:property_id, :image_url, :position)'
                );
                foreach ($images as $index => $image) {
                    $insertImage->execute(['property_id' => $propertyId, 'image_url' => $image, 'position' => $index + 1]);
                }
                if ($newVideos) {
                    $db->prepare('DELETE FROM property_videos WHERE property_id = :id')->execute(['id' => $propertyId]);
                    $insertVideo = $db->prepare('INSERT INTO property_videos (property_id, video_url) VALUES (:property_id, :video_url)');
                    foreach ($newVideos as $videoPath) {
                        $insertVideo->execute(['property_id' => $propertyId, 'video_url' => $videoPath]);
                    }
                }
                $recordActivity($activity, $propertyId);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                delete_property_photos($newImages);
                delete_property_videos($newVideos);
                throw $exception;
            }
            if ($newImages && $existing) {
                delete_property_photos($oldImages);
            }
            if ($newVideos) {
                delete_property_videos($oldVideos);
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le bien a été enregistré.'];
            redirect('portfolio.php#properties');
        }

        if ($action === 'save_owner') {
            $ownerIdInput = post_string('owner_id');
            $id = $ownerIdInput === '' ? null : filter_var($ownerIdInput, FILTER_VALIDATE_INT);
            $firstName = trim(post_string('first_name'));
            $lastName = trim(post_string('last_name'));
            $email = trim(post_string('email'));
            $phone = trim(post_string('phone'));
            $residence = trim(post_string('residence_address'));
            $identityNumber = trim(post_string('identity_number'));
            if (($ownerIdInput !== '' && (!is_int($id) || $id < 1))
                || $firstName === '' || $lastName === '' || strlen($firstName) > 80 || strlen($lastName) > 80
                || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
                || strlen($phone) > 40 || strlen($residence) > 255 || strlen($identityNumber) > 100) {
                throw new RuntimeException('Vérifiez les coordonnées et les informations du propriétaire.');
            }
            $ownerValues = [
                'first_name' => $firstName, 'last_name' => $lastName,
                'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null,
                'residence_address' => $residence !== '' ? $residence : null,
                'identity_number' => $identityNumber !== '' ? $identityNumber : null,
            ];
            if ($id !== null && $id > 0) {
                $ownerValues['id'] = $id;
                $ownerUpdate = $db->prepare(
                    'UPDATE property_owners SET first_name = :first_name, last_name = :last_name, email = :email,
                        phone = :phone, residence_address = :residence_address, identity_number = :identity_number WHERE id = :id'
                );
                $ownerUpdate->execute($ownerValues);
                $ownerId = (int) $id;
                if ($ownerUpdate->rowCount() === 0) {
                    $exists = $db->prepare('SELECT 1 FROM property_owners WHERE id = :id');
                    $exists->execute(['id' => $ownerId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Le propriétaire à modifier est introuvable.');
                    }
                }
            } else {
                $db->prepare(
                    'INSERT INTO property_owners (first_name, last_name, email, phone, residence_address, identity_number)
                     VALUES (:first_name, :last_name, :email, :phone, :residence_address, :identity_number)'
                )->execute($ownerValues);
                $ownerId = (int) $db->lastInsertId();
            }
            $saveOwnerDocument($_FILES['identity_document'] ?? [], 'Pièce d’identité', $ownerId);
            $saveOwnerDocument($_FILES['owner_document'] ?? [], 'Document associé', $ownerId);
            $recordActivity(($id ? 'Fiche propriétaire modifiée : ' : 'Propriétaire ajouté : ') . $firstName . ' ' . $lastName);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le propriétaire a été enregistré.'];
            redirect('portfolio.php#owners');
        }

        if ($action === 'save_tenant') {
            $firstName = trim(post_string('first_name'));
            $lastName = trim(post_string('last_name'));
            $email = trim(post_string('email'));
            $phone = trim(post_string('phone'));
            $residence = trim(post_string('residence_address'));
            if ($firstName === '' || $lastName === '' || strlen($firstName) > 80 || strlen($lastName) > 80
                || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
                || strlen($phone) > 40 || strlen($residence) > 255) {
                throw new RuntimeException('Vérifiez les coordonnées du locataire.');
            }
            $db->prepare(
                'INSERT INTO tenants (first_name, last_name, email, phone, residence_address)
                 VALUES (:first_name, :last_name, :email, :phone, :residence_address)'
            )->execute([
                'first_name' => $firstName, 'last_name' => $lastName,
                'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null,
                'residence_address' => $residence !== '' ? $residence : null,
            ]);
            $recordActivity('Locataire ajouté : ' . $firstName . ' ' . $lastName);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le locataire a été enregistré.'];
            redirect('portfolio.php#tenants');
        }

        if ($action === 'save_lease') {
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            $tenantId = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
            $startDate = post_string('start_date');
            $endDate = post_string('end_date');
            $rent = filter_input(INPUT_POST, 'monthly_rent', FILTER_VALIDATE_INT);
            $reference = trim(post_string('contract_reference'));
            if (!$propertyId || !$tenantId || $rent === false || $rent < 1
                || !$validDate($startDate) || !$validDate($endDate) || $endDate <= $startDate
                || strlen($reference) > 100) {
                throw new RuntimeException('Vérifiez le bien, le locataire, les dates et le montant du contrat.');
            }
            $db->beginTransaction();
            try {
                $propertyCheck = $db->prepare('SELECT title, operational_status FROM properties WHERE id = :id FOR UPDATE');
                $propertyCheck->execute(['id' => $propertyId]);
                $property = $propertyCheck->fetch();
                $tenantCheck = $db->prepare('SELECT first_name, last_name FROM tenants WHERE id = :id');
                $tenantCheck->execute(['id' => $tenantId]);
                if (!$property || !$tenantCheck->fetch()) {
                    throw new RuntimeException('Le bien ou le locataire sélectionné est introuvable.');
                }
                if ($property['operational_status'] === 'rented') {
                    throw new RuntimeException('Ce bien a déjà un statut loué.');
                }
                $db->prepare(
                    'INSERT INTO leases (property_id, tenant_id, start_date, end_date, monthly_rent, contract_reference)
                     VALUES (:property_id, :tenant_id, :start_date, :end_date, :monthly_rent, :contract_reference)'
                )->execute([
                    'property_id' => $propertyId, 'tenant_id' => $tenantId, 'start_date' => $startDate,
                    'end_date' => $endDate, 'monthly_rent' => $rent,
                    'contract_reference' => $reference !== '' ? $reference : null,
                ]);
                $db->prepare('UPDATE properties SET operational_status = "rented" WHERE id = :id')->execute(['id' => $propertyId]);
                $recordActivity('Contrat de location créé pour ' . $property['title'], $propertyId);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le contrat de location a été enregistré.'];
            redirect('portfolio.php#leases');
        }

        if ($action === 'save_payment') {
            $leaseId = filter_input(INPUT_POST, 'lease_id', FILTER_VALIDATE_INT);
            $dueDate = post_string('due_date');
            $amountDue = filter_input(INPUT_POST, 'amount_due', FILTER_VALIDATE_INT);
            $amountPaid = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_INT);
            $paidAt = post_string('paid_at');
            $note = trim(post_string('note'));
            if (!$leaseId || $amountDue === false || $amountDue < 1 || $amountPaid === false
                || $amountPaid < 0 || $amountPaid > $amountDue
                || !$validDate($dueDate)
                || ($amountPaid > 0 && !$validDate($paidAt))
                || strlen($note) > 500) {
                throw new RuntimeException('Vérifiez l’échéance, le montant dû et le montant encaissé.');
            }
            $db->beginTransaction();
            try {
                $db->prepare(
                    'INSERT INTO rent_payments (lease_id, due_date, amount_due, amount_paid, paid_at, note)
                     VALUES (:lease_id, :due_date, :amount_due, :amount_paid, :paid_at, :note)'
                )->execute([
                    'lease_id' => $leaseId, 'due_date' => $dueDate, 'amount_due' => $amountDue,
                    'amount_paid' => $amountPaid, 'paid_at' => $amountPaid > 0 ? $paidAt : null,
                    'note' => $note !== '' ? $note : null,
                ]);
                if ($amountPaid > 0) {
                    $db->prepare(
                        'INSERT INTO rent_collections (rent_payment_id, amount, collected_at) VALUES (:payment_id, :amount, :collected_at)'
                    )->execute([
                        'payment_id' => $db->lastInsertId(), 'amount' => $amountPaid, 'collected_at' => $paidAt,
                    ]);
                }
                $recordActivity('Échéance de loyer enregistrée');
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'L’échéance de loyer a été enregistrée.'];
            redirect('portfolio.php#finance');
        }

        if ($action === 'collect_payment') {
            $paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'collection_amount', FILTER_VALIDATE_INT);
            $collectedAt = post_string('collected_at');
            if (!$paymentId || $amount === false || $amount < 1 || !$validDate($collectedAt)) {
                throw new RuntimeException('Vérifiez le montant encaissé et sa date.');
            }
            $db->beginTransaction();
            try {
                $paymentQuery = $db->prepare(
                    'SELECT rp.amount_due, rp.amount_paid, l.property_id, p.title
                     FROM rent_payments rp JOIN leases l ON l.id = rp.lease_id
                     JOIN properties p ON p.id = l.property_id WHERE rp.id = :id FOR UPDATE'
                );
                $paymentQuery->execute(['id' => $paymentId]);
                $payment = $paymentQuery->fetch();
                if (!$payment || $amount > (int) $payment['amount_due'] - (int) $payment['amount_paid']) {
                    throw new RuntimeException('Le montant dépasse le solde de cette échéance.');
                }
                $db->prepare(
                    'UPDATE rent_payments SET amount_paid = amount_paid + :amount, paid_at = :paid_at WHERE id = :id'
                )->execute(['amount' => $amount, 'paid_at' => $collectedAt, 'id' => $paymentId]);
                $db->prepare(
                    'INSERT INTO rent_collections (rent_payment_id, amount, collected_at) VALUES (:payment_id, :amount, :collected_at)'
                )->execute(['payment_id' => $paymentId, 'amount' => $amount, 'collected_at' => $collectedAt]);
                $recordActivity('Encaissement de loyer enregistré pour ' . $payment['title'], (int) $payment['property_id']);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'L’encaissement a été enregistré.'];
            redirect('portfolio.php#finance');
        }

        if ($action === 'save_expense' || $action === 'save_income') {
            $propertyIdInput = post_string('property_id');
            $propertyId = $propertyIdInput === '' ? null : filter_var($propertyIdInput, FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_INT);
            $entryDate = post_string('entry_date');
            $note = trim(post_string('note'));
            if ($amount === false || $amount < 1 || !$validDate($entryDate)
                || strlen($note) > 500 || ($propertyId !== null && (!is_int($propertyId) || $propertyId < 1))) {
                throw new RuntimeException('Vérifiez le montant, la date et les informations de l’opération.');
            }
            if ($action === 'save_expense') {
                $category = trim(post_string('category'));
                if ($category === '' || strlen($category) > 100) {
                    throw new RuntimeException('Indiquez une catégorie de dépense valide.');
                }
                $db->prepare(
                    'INSERT INTO property_expenses (property_id, category, amount, expense_date, note)
                     VALUES (:property_id, :category, :amount, :expense_date, :note)'
                )->execute([
                    'property_id' => $propertyId ?: null, 'category' => $category, 'amount' => $amount,
                    'expense_date' => $entryDate, 'note' => $note !== '' ? $note : null,
                ]);
                $recordActivity('Dépense immobilière enregistrée' . ($propertyId ? ' pour un bien' : ''));
                $message = 'La dépense a été enregistrée.';
            } else {
                $incomeType = post_string('income_type');
                if ($incomeType !== 'other') {
                    throw new RuntimeException('Choisissez un type de revenu valide.');
                }
                $db->prepare(
                    'INSERT INTO income_entries (property_id, income_type, amount, income_date, note)
                     VALUES (:property_id, :income_type, :amount, :income_date, :note)'
                )->execute([
                    'property_id' => $propertyId ?: null, 'income_type' => $incomeType,
                    'amount' => $amount, 'income_date' => $entryDate, 'note' => $note !== '' ? $note : null,
                ]);
                $recordActivity('Revenu immobilier enregistré');
                $message = 'Le revenu a été enregistré.';
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
            redirect('portfolio.php#finance');
        }

        if ($action === 'archive_property') {
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            if (!$propertyId || $propertyId < 1) {
                throw new RuntimeException('Bien invalide.');
            }
            $archive = $db->prepare('UPDATE properties SET status = "archived" WHERE id = :id');
            $archive->execute(['id' => $propertyId]);
            if ($archive->rowCount() !== 1) {
                throw new RuntimeException('Le bien à archiver est introuvable ou déjà archivé.');
            }
            $recordActivity('Bien archivé', $propertyId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le bien a été archivé et retiré des annonces publiques.'];
            redirect('portfolio.php#properties');
        }

        throw new RuntimeException('Action de gestion inconnue.');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

// Load dashboard totals, monthly chart series, alerts, and recent activity.
$counts = $db->query(
    "SELECT COUNT(*) AS all_count,
        SUM(operational_status = 'available' AND status = 'published') AS available_count,
        SUM(operational_status = 'rented' AND status = 'published') AS rented_count,
        SUM(operational_status = 'sold' AND status = 'published') AS sold_count,
        SUM(operational_status = 'reserved' AND transaction_type = 'rent' AND status = 'published') AS reserved_count
     FROM properties WHERE status <> 'archived'"
)->fetch();
$ownerCount = (int) $db->query('SELECT COUNT(*) FROM property_owners')->fetchColumn();
$tenantCount = (int) $db->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
$monthRevenue = (float) $db->query(
    'SELECT (SELECT COALESCE(SUM(amount), 0) FROM rent_collections
             WHERE collected_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01"))
          + (SELECT COALESCE(SUM(amount), 0) FROM income_entries
             WHERE income_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01"))
          + (SELECT COALESCE(SUM(sale_price), 0) FROM property_sales
             WHERE sale_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01"))'
)->fetchColumn();
$yearRevenue = (float) $db->query(
    'SELECT (SELECT COALESCE(SUM(amount), 0) FROM rent_collections
             WHERE collected_at >= MAKEDATE(YEAR(CURDATE()), 1))
          + (SELECT COALESCE(SUM(amount), 0) FROM income_entries
             WHERE income_date >= MAKEDATE(YEAR(CURDATE()), 1))
          + (SELECT COALESCE(SUM(sale_price), 0) FROM property_sales
             WHERE sale_date >= MAKEDATE(YEAR(CURDATE()), 1))'
)->fetchColumn();
$monthExpenses = (float) $db->query(
    'SELECT COALESCE(SUM(amount), 0) FROM property_expenses
     WHERE expense_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
)->fetchColumn();
$arrears = (float) $db->query(
    'SELECT COALESCE(SUM(amount_due - amount_paid), 0) FROM rent_payments
     WHERE amount_due > amount_paid AND due_date <= CURDATE()'
)->fetchColumn();
$chartStatement = $db->query(
    'SELECT month_key, SUM(revenue) AS revenue, SUM(expenses) AS expenses FROM (
        SELECT DATE_FORMAT(collected_at, "%Y-%m") AS month_key, SUM(amount) AS revenue, 0 AS expenses
        FROM rent_collections WHERE collected_at >= DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(collected_at, "%Y-%m")
        UNION ALL
        SELECT DATE_FORMAT(income_date, "%Y-%m"), SUM(amount), 0
        FROM income_entries WHERE income_date >= DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(income_date, "%Y-%m")
        UNION ALL
        SELECT DATE_FORMAT(sale_date, "%Y-%m"), SUM(sale_price), 0
        FROM property_sales WHERE sale_date >= DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(sale_date, "%Y-%m")
        UNION ALL
        SELECT DATE_FORMAT(expense_date, "%Y-%m"), 0, SUM(amount)
        FROM property_expenses WHERE expense_date >= DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(expense_date, "%Y-%m")
    ) monthly GROUP BY month_key ORDER BY month_key'
);
$chartData = [];
foreach ($chartStatement->fetchAll() as $row) {
    $chartData[$row['month_key']] = ['revenue' => (float) $row['revenue'], 'expenses' => (float) $row['expenses']];
}
$chartMonths = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $monthKey = date('Y-m', strtotime('first day of -' . $offset . ' months'));
    $chartMonths[] = [
        'label' => (int) date('n', strtotime($monthKey . '-01')),
        'revenue' => $chartData[$monthKey]['revenue'] ?? 0,
        'expenses' => $chartData[$monthKey]['expenses'] ?? 0,
    ];
}
$chartMax = 1;
foreach ($chartMonths as $month) {
    $chartMax = max($chartMax, $month['revenue'], $month['expenses']);
}
$expiringLeases = $db->query(
    'SELECT l.id, l.end_date, l.monthly_rent, p.id AS property_id, p.title, t.first_name, t.last_name
     FROM leases l JOIN properties p ON p.id = l.property_id JOIN tenants t ON t.id = l.tenant_id
     WHERE l.status = "active" AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
     ORDER BY l.end_date ASC'
)->fetchAll();
$recentActivity = $db->query(
    'SELECT a.activity, a.created_at, p.title FROM property_activity a
     LEFT JOIN properties p ON p.id = a.property_id ORDER BY a.created_at DESC LIMIT 10'
)->fetchAll();
$properties = $db->query(
    'SELECT p.id, p.title, p.city, p.address, p.price, p.transaction_type, p.property_type,
        p.operational_status, p.status, p.image_url, p.owner_id, o.first_name AS owner_first, o.last_name AS owner_last
     FROM properties p LEFT JOIN property_owners o ON o.id = p.owner_id
     ORDER BY p.created_at DESC'
)->fetchAll();
$owners = $db->query(
    'SELECT o.*, COUNT(DISTINCT p.id) AS property_count,
        (SELECT COUNT(*) FROM leases l JOIN properties lp ON lp.id = l.property_id WHERE lp.owner_id = o.id) AS lease_count,
        (SELECT COALESCE(SUM(rc.amount), 0) FROM rent_collections rc
         JOIN rent_payments rp ON rp.id = rc.rent_payment_id JOIN leases rl ON rl.id = rp.lease_id
         JOIN properties rp_property ON rp_property.id = rl.property_id WHERE rp_property.owner_id = o.id)
        + (SELECT COALESCE(SUM(ie.amount), 0) FROM income_entries ie
           JOIN properties ip ON ip.id = ie.property_id WHERE ip.owner_id = o.id)
        + (SELECT COALESCE(SUM(ps.sale_price), 0) FROM property_sales ps
           JOIN properties sp ON sp.id = ps.property_id WHERE sp.owner_id = o.id) AS income_total
     FROM property_owners o LEFT JOIN properties p ON p.owner_id = o.id
     GROUP BY o.id ORDER BY o.last_name, o.first_name'
)->fetchAll();
$tenants = $db->query('SELECT * FROM tenants ORDER BY last_name, first_name')->fetchAll();
$leases = $db->query(
    'SELECT l.*, p.title, p.owner_id, t.first_name, t.last_name FROM leases l
     JOIN properties p ON p.id = l.property_id JOIN tenants t ON t.id = l.tenant_id
     ORDER BY l.end_date DESC LIMIT 100'
)->fetchAll();
$payments = $db->query(
    'SELECT rp.*, p.title, t.first_name, t.last_name FROM rent_payments rp
     JOIN leases l ON l.id = rp.lease_id JOIN properties p ON p.id = l.property_id
     JOIN tenants t ON t.id = l.tenant_id ORDER BY rp.due_date DESC LIMIT 50'
)->fetchAll();
$documents = $db->query(
    'SELECT d.*, o.first_name, o.last_name FROM owner_documents d
     JOIN property_owners o ON o.id = d.owner_id ORDER BY d.created_at DESC'
)->fetchAll();
$ownerIncomeHistory = $db->query(
    'SELECT owner_id, property_title, entry_type, amount, entry_date FROM (
        SELECT p.owner_id, p.title AS property_title, "Loyer" AS entry_type, rc.amount, rc.collected_at AS entry_date
        FROM rent_collections rc JOIN rent_payments rp ON rp.id = rc.rent_payment_id
        JOIN leases l ON l.id = rp.lease_id JOIN properties p ON p.id = l.property_id
        WHERE p.owner_id IS NOT NULL
        UNION ALL
        SELECT p.owner_id, p.title, IF(ie.income_type = "sale", "Vente", "Autre revenu"),
            ie.amount, ie.income_date
        FROM income_entries ie JOIN properties p ON p.id = ie.property_id
        WHERE p.owner_id IS NOT NULL
        UNION ALL
        SELECT p.owner_id, p.title, "Vente", ps.sale_price, ps.sale_date
        FROM property_sales ps JOIN properties p ON p.id = ps.property_id
        WHERE p.owner_id IS NOT NULL
    ) owner_income ORDER BY entry_date DESC LIMIT 500'
)->fetchAll();
$activeLeases = array_values(array_filter($leases, static function (array $lease): bool {
    return $lease['status'] === 'active';
}));
$editId = filter_input(INPUT_GET, 'edit_property', FILTER_VALIDATE_INT);
$editProperty = null;
$editVideos = [];
if ($editId) {
    $editQuery = $db->prepare('SELECT * FROM properties WHERE id = :id');
    $editQuery->execute(['id' => $editId]);
    $editProperty = $editQuery->fetch();
    if (!$editProperty) {
        http_response_code(404);
        exit('Bien introuvable.');
    }
    $videosQuery = $db->prepare('SELECT video_url FROM property_videos WHERE property_id = :id ORDER BY id');
    $videosQuery->execute(['id' => $editId]);
    $editVideos = array_values(array_filter(
        array_column($videosQuery->fetchAll(), 'video_url'),
        static fn (string $path): bool => property_video_mime_type($path) !== null
    ));
}
$editOwnerId = filter_input(INPUT_GET, 'edit_owner', FILTER_VALIDATE_INT);
$editOwner = null;
if ($editOwnerId) {
    $editOwnerQuery = $db->prepare('SELECT * FROM property_owners WHERE id = :id');
    $editOwnerQuery->execute(['id' => $editOwnerId]);
    $editOwner = $editOwnerQuery->fetch();
    if (!$editOwner) {
        http_response_code(404);
        exit('Propriétaire introuvable.');
    }
}
$flash = flash_message();
$monthNames = [1 => 'Janv.', 2 => 'Févr.', 3 => 'Mars', 4 => 'Avr.', 5 => 'Mai', 6 => 'Juin', 7 => 'Juil.', 8 => 'Août', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Déc.'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion immobilière — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=11">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>
<header class="site-header">
    <div class="nav-wrap">
        <a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <nav class="portfolio-nav" aria-label="Navigation de gestion">
            <a href="#properties">Biens</a><a href="#owners">Propriétaires</a><a href="#tenants">Locataires</a>
            <a href="#leases">Contrats</a><a href="#finance">Finances</a>
            <a href="sales.php">Ventes</a><a href="visits.php">Visites</a>
            <a href="index.php">Modération</a><a href="../index.php">Voir le site</a>
        </nav>
    </div>
</header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="section page-section portfolio-page">
    <div class="section-heading">
        <div><span class="eyebrow">ESPACE SÉCURISÉ · <?= e($admin['first_name']) ?></span><h1>Gestion immobilière.</h1><p>Patrimoine, contrats, encaissements et dépenses au même endroit.</p></div>
        <a class="button button-outline" href="index.php">← Modération des annonces</a>
    </div>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <section class="portfolio-stats" aria-label="Indicateurs du patrimoine">
        <article class="portfolio-stat"><span>Biens immobiliers</span><strong><?= (int) $counts['all_count'] ?></strong><small>hors biens archivés</small></article>
        <article class="portfolio-stat"><span>Disponibles</span><strong><?= (int) $counts['available_count'] ?></strong><small>à vendre ou à louer</small></article>
        <article class="portfolio-stat"><span>Loués</span><strong><?= (int) $counts['rented_count'] ?></strong><small>occupés avec un contrat</small></article>
        <article class="portfolio-stat"><span>Vendus</span><strong><?= (int) $counts['sold_count'] ?></strong><small>transactions clôturées</small></article>
        <article class="portfolio-stat"><span>En attente de location</span><strong><?= (int) $counts['reserved_count'] ?></strong><small>réservés pour une location</small></article>
        <article class="portfolio-stat"><span>Propriétaires</span><strong><?= $ownerCount ?></strong><small>fiches enregistrées</small></article>
        <article class="portfolio-stat"><span>Locataires</span><strong><?= $tenantCount ?></strong><small>fiches enregistrées</small></article>
        <article class="portfolio-stat"><span>Revenus du mois</span><strong><?= e(number_format($monthRevenue, 0, ',', ' ')) ?> <small>FCFA</small></strong><small>loyers encaissés et autres revenus</small></article>
        <article class="portfolio-stat"><span>Revenus de l’année</span><strong><?= e(number_format($yearRevenue, 0, ',', ' ')) ?> <small>FCFA</small></strong><small>depuis le 1er janvier</small></article>
        <article class="portfolio-stat portfolio-stat-alert"><span>Loyers impayés échus</span><strong><?= e(number_format($arrears, 0, ',', ' ')) ?> <small>FCFA</small></strong><small>montants restant dus à date</small></article>
        <article class="portfolio-stat"><span>Dépenses du mois</span><strong><?= e(number_format($monthExpenses, 0, ',', ' ')) ?> <small>FCFA</small></strong><small>dépenses enregistrées</small></article>
    </section>

    <div class="portfolio-columns">
        <section class="portfolio-panel">
            <div class="panel-heading"><div><span class="eyebrow">ÉVOLUTION SUR 6 MOIS</span><h2>Revenus et dépenses</h2></div></div>
            <div class="portfolio-chart" role="img" aria-label="Graphique comparant revenus et dépenses des six derniers mois">
                <?php foreach ($chartMonths as $month): ?>
                    <div class="chart-month">
                        <div class="chart-bars">
                            <span class="chart-bar chart-revenue" title="Revenus : <?= e(number_format($month['revenue'], 0, ',', ' ')) ?> FCFA" style="height: <?= max(2, (int) round($month['revenue'] / $chartMax * 100)) ?>%"></span>
                            <span class="chart-bar chart-expense" title="Dépenses : <?= e(number_format($month['expenses'], 0, ',', ' ')) ?> FCFA" style="height: <?= max(2, (int) round($month['expenses'] / $chartMax * 100)) ?>%"></span>
                        </div>
                        <span><?= e($monthNames[$month['label']] ?? $month['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-legend"><span><i class="legend-revenue"></i> Revenus</span><span><i class="legend-expense"></i> Dépenses</span></div>
        </section>
        <section class="portfolio-panel">
            <div class="panel-heading"><div><span class="eyebrow">À SURVEILLER</span><h2>Contrats bientôt échus</h2></div></div>
            <?php if ($expiringLeases): ?>
                <div class="portfolio-list">
                    <?php foreach ($expiringLeases as $lease): ?>
                        <article class="portfolio-list-item"><div><strong><?= e($lease['title']) ?></strong><span><?= e($lease['first_name'] . ' ' . $lease['last_name']) ?> · fin le <?= e(date('d/m/Y', strtotime($lease['end_date']))) ?></span></div><span class="status-pill status-pending"><?= (int) ceil((strtotime($lease['end_date']) - strtotime(date('Y-m-d'))) / 86400) ?> j</span></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?><p class="portfolio-empty">Aucun contrat n’arrive à échéance dans les 60 prochains jours.</p><?php endif; ?>
        </section>
    </div>

    <section class="portfolio-panel" id="properties">
        <div class="panel-heading"><div><span class="eyebrow">PATRIMOINE</span><h2><?= $editProperty ? 'Modifier le bien' : 'Ajouter un bien immobilier' ?></h2></div></div>
        <form class="portfolio-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_property">
            <?php if ($editProperty): ?><input type="hidden" name="property_id" value="<?= (int) $editProperty['id'] ?>"><?php endif; ?>
            <div class="portfolio-form-grid">
                <label>Titre<input name="title" maxlength="160" required value="<?= e($editProperty['title'] ?? '') ?>"></label>
                <label>Propriétaire<select name="owner_id"><option value="">Non renseigné</option><?php foreach ($owners as $owner): ?><option value="<?= (int) $owner['id'] ?>" <?= (int) ($editProperty['owner_id'] ?? 0) === (int) $owner['id'] ? 'selected' : '' ?>><?= e($owner['first_name'] . ' ' . $owner['last_name']) ?></option><?php endforeach; ?></select></label>
                <label>Type de transaction<select name="transaction_type"><option value="sale" <?= ($editProperty['transaction_type'] ?? 'sale') === 'sale' ? 'selected' : '' ?>>Vente</option><option value="rent" <?= ($editProperty['transaction_type'] ?? '') === 'rent' ? 'selected' : '' ?>>Location</option></select></label>
                <label>Type de bien<select name="property_type"><?php foreach ($propertyTypes as $key => $label): ?><option value="<?= e($key) ?>" <?= ($editProperty['property_type'] ?? 'house') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label>Prix en FCFA<input type="number" name="price" min="1" required value="<?= e($editProperty['price'] ?? '') ?>"></label>
                <label>Statut de gestion<select name="operational_status"><?php foreach ($operationalStatuses as $key => $label): ?><option value="<?= e($key) ?>" <?= ($editProperty['operational_status'] ?? 'available') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label>Surface (m²)<input type="number" name="area" min="1" required value="<?= e($editProperty['area'] ?? '') ?>"></label>
                <label>Chambres<input type="number" name="bedrooms" min="0" required value="<?= e($editProperty['bedrooms'] ?? '0') ?>"></label>
                <label>Salons<input type="number" name="living_rooms" min="0" required value="<?= e($editProperty['living_rooms'] ?? '0') ?>"></label>
                <label>Salles de bains<input type="number" name="bathrooms" min="0" required value="<?= e($editProperty['bathrooms'] ?? '0') ?>"></label>
                <label>Ville<input name="city" maxlength="100" required value="<?= e($editProperty['city'] ?? '') ?>"></label>
                <label>Quartier<input name="district" maxlength="100" value="<?= e($editProperty['district'] ?? '') ?>"></label>
                <label class="portfolio-wide">Adresse complète<input name="address" maxlength="255" required value="<?= e($editProperty['address'] ?? '') ?>"></label>
                <div class="portfolio-wide location-picker" data-location-picker>
                    <span class="optional">Localisation du bien</span>
                    <p class="map-help">Cliquez sur la carte pour choisir un point, ou utilisez votre position actuelle.</p>
                    <button class="button button-outline" type="button" data-use-current-location>Utiliser ma position actuelle</button>
                    <div class="property-map" data-map-canvas aria-label="Carte pour choisir la localisation"></div>
                    <p class="map-status" data-map-status aria-live="polite">Aucun point choisi.</p>
                    <input type="hidden" name="location_latitude" value="<?= e($editProperty['location_latitude'] ?? '') ?>">
                    <input type="hidden" name="location_longitude" value="<?= e($editProperty['location_longitude'] ?? '') ?>">
                </div>
                <label class="portfolio-wide">Description<textarea name="description" rows="4" maxlength="5000" required><?= e($editProperty['description'] ?? '') ?></textarea></label>
                <div class="portfolio-wide property-image-fields">
                    <span class="optional">Vidéos de présentation — MP4, WebM ou OGG, jusqu’à trois fichiers de 100 Mo chacun</span>
                    <label>Choisir depuis votre appareil<input type="file" name="property_videos[]" accept="video/mp4,video/webm,video/ogg" multiple></label>
                    <?php if ($editVideos): ?><div class="video-preview-grid"><?php foreach ($editVideos as $videoPath): ?><video controls preload="metadata"><source src="../<?= e($videoPath) ?>" type="<?= e(property_video_mime_type($videoPath)) ?>"></video><?php endforeach; ?></div><?php endif; ?>
                    <small>Les vidéos actuelles restent si aucune nouvelle n’est sélectionnée.</small>
                </div>
                <div class="portfolio-wide">
                    <span class="optional"><?= $editProperty ? 'Photos : sélectionnez de nouvelles images pour remplacer la galerie actuelle.' : 'Photos : JPG, PNG ou WebP, 5 Mo maximum chacune. La première photo est obligatoire.' ?></span>
                    <div class="portfolio-form-grid">
                        <?php for ($imageNumber = 1; $imageNumber <= 4; $imageNumber++): ?><label><?= $imageNumber === 1 ? 'Photo principale' : 'Photo ' . $imageNumber ?><input type="file" name="property_image_<?= $imageNumber ?>" accept="image/jpeg,image/png,image/webp" <?= !$editProperty && $imageNumber === 1 ? 'required' : '' ?>></label><?php endfor; ?>
                    </div>
                </div>
            </div>
            <button class="button button-dark" type="submit"><?= $editProperty ? 'Enregistrer les modifications' : 'Ajouter le bien' ?></button>
            <?php if ($editProperty): ?><a class="button button-outline" href="portfolio.php#properties">Annuler</a><?php endif; ?>
        </form>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Bien</th><th>Propriétaire</th><th>Prix</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($properties as $property): ?><tr>
            <td><strong><?= e($property['title']) ?></strong><small><?= e($property['address'] ?: $property['city']) ?> · <?= e($propertyTypes[$property['property_type']] ?? $property['property_type']) ?></small></td>
            <td><?= e(trim(($property['owner_first'] ?? '') . ' ' . ($property['owner_last'] ?? '')) ?: 'Non renseigné') ?></td>
            <td><?= e(format_price($property['price'], $property['transaction_type'])) ?></td>
            <td><?= e($operationalStatuses[$property['operational_status']] ?? $property['operational_status']) ?><?= $property['status'] === 'archived' ? ' · Archivé' : '' ?></td>
            <td class="portfolio-actions"><a class="small-action" href="portfolio.php?edit_property=<?= (int) $property['id'] ?>#properties">Modifier</a><a class="small-action" href="../property.php?id=<?= (int) $property['id'] ?>">Détails</a>
                <?php if ($property['status'] !== 'archived'): ?><form method="post" onsubmit="return confirm('Archiver ce bien et le retirer des annonces publiques ?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="archive_property"><input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>"><button class="small-action" type="submit">Archiver</button></form><?php endif; ?>
            </td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <div class="portfolio-columns">
        <section class="portfolio-panel" id="owners">
            <div class="panel-heading"><div><span class="eyebrow">CONTACTS ET DOCUMENTS</span><h2>Propriétaires</h2></div></div>
            <form class="portfolio-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_owner">
                <?php if ($editOwner): ?><input type="hidden" name="owner_id" value="<?= (int) $editOwner['id'] ?>"><?php endif; ?>
                <div class="portfolio-form-grid">
                    <label>Prénom<input name="first_name" maxlength="80" required value="<?= e($editOwner['first_name'] ?? '') ?>"></label><label>Nom<input name="last_name" maxlength="80" required value="<?= e($editOwner['last_name'] ?? '') ?>"></label>
                    <label>Téléphone<input type="tel" name="phone" maxlength="40" value="<?= e($editOwner['phone'] ?? '') ?>"></label><label>E-mail<input type="email" name="email" maxlength="190" value="<?= e($editOwner['email'] ?? '') ?>"></label>
                    <label class="portfolio-wide">Adresse de résidence<input name="residence_address" maxlength="255" value="<?= e($editOwner['residence_address'] ?? '') ?>"></label>
                    <label>Numéro de pièce d’identité<input name="identity_number" maxlength="100" value="<?= e($editOwner['identity_number'] ?? '') ?>"></label>
                    <label>Copie de pièce d’identité (PDF/JPG/PNG)<input type="file" name="identity_document" accept="application/pdf,image/jpeg,image/png"></label>
                    <label class="portfolio-wide">Document complémentaire privé<input type="file" name="owner_document" accept="application/pdf,image/jpeg,image/png"></label>
                </div>
                <button class="button button-dark" type="submit"><?= $editOwner ? 'Modifier le propriétaire' : 'Enregistrer le propriétaire' ?></button>
                <?php if ($editOwner): ?><a class="button button-outline" href="portfolio.php#owners">Annuler</a><?php endif; ?>
            </form>
            <div class="portfolio-list">
                <?php foreach ($owners as $owner): ?>
                    <article class="portfolio-list-item"><div><strong><?= e($owner['first_name'] . ' ' . $owner['last_name']) ?></strong><span><?= e($owner['phone'] ?: 'Téléphone non renseigné') ?><?= $owner['email'] ? ' · ' . e($owner['email']) : '' ?></span><span><?= e($owner['residence_address'] ?: 'Adresse non renseignée') ?> · <?= (int) $owner['property_count'] ?> bien(s)</span><span>Pièce d’identité : <?= e($owner['identity_number'] ?: 'non renseignée') ?></span><a href="portfolio.php?edit_owner=<?= (int) $owner['id'] ?>#owners">Modifier cette fiche</a>
                        <?php foreach ($documents as $document): if ((int) $document['owner_id'] === (int) $owner['id']): ?><a href="document.php?id=<?= (int) $document['id'] ?>"><?= e($document['document_type'] . ' — ' . $document['original_name']) ?></a><?php endif; endforeach; ?>
                    </div></article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="portfolio-panel" id="tenants">
            <div class="panel-heading"><div><span class="eyebrow">OCCUPANTS</span><h2>Locataires</h2></div></div>
            <form class="portfolio-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_tenant">
                <div class="portfolio-form-grid">
                    <label>Prénom<input name="first_name" maxlength="80" required></label><label>Nom<input name="last_name" maxlength="80" required></label>
                    <label>Téléphone<input type="tel" name="phone" maxlength="40"></label><label>E-mail<input type="email" name="email" maxlength="190"></label>
                    <label class="portfolio-wide">Adresse de résidence<input name="residence_address" maxlength="255"></label>
                </div>
                <button class="button button-dark" type="submit">Enregistrer le locataire</button>
            </form>
            <div class="portfolio-list"><?php foreach ($tenants as $tenant): ?><article class="portfolio-list-item"><div><strong><?= e($tenant['first_name'] . ' ' . $tenant['last_name']) ?></strong><span><?= e($tenant['phone'] ?: 'Téléphone non renseigné') ?><?= $tenant['email'] ? ' · ' . e($tenant['email']) : '' ?></span></div></article><?php endforeach; ?></div>
        </section>
    </div>

    <section class="portfolio-panel" id="leases">
        <div class="panel-heading"><div><span class="eyebrow">LOCATIONS</span><h2>Contrats et échéances</h2></div></div>
        <form class="portfolio-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_lease">
            <div class="portfolio-form-grid">
                <label>Bien<select name="property_id" required><option value="">Sélectionner</option><?php foreach ($properties as $property): if ($property['status'] !== 'archived' && $property['operational_status'] !== 'rented'): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title']) ?></option><?php endif; endforeach; ?></select></label>
                <label>Locataire<select name="tenant_id" required><option value="">Sélectionner</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>"><?= e($tenant['first_name'] . ' ' . $tenant['last_name']) ?></option><?php endforeach; ?></select></label>
                <label>Date de début<input type="date" name="start_date" required></label><label>Date de fin<input type="date" name="end_date" required></label>
                <label>Loyer mensuel (FCFA)<input type="number" name="monthly_rent" min="1" required></label><label>Référence du contrat<input name="contract_reference" maxlength="100"></label>
            </div>
            <button class="button button-dark" type="submit">Enregistrer le contrat</button>
        </form>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Bien</th><th>Locataire</th><th>Loyer</th><th>Période</th><th>Statut</th></tr></thead><tbody>
        <?php foreach ($leases as $lease): ?><tr><td><?= e($lease['title']) ?><small><?= e($lease['contract_reference'] ?: 'Sans référence') ?></small></td><td><?= e($lease['first_name'] . ' ' . $lease['last_name']) ?></td><td><?= e(number_format((float) $lease['monthly_rent'], 0, ',', ' ')) ?> FCFA</td><td><?= e(date('d/m/Y', strtotime($lease['start_date']))) ?> – <?= e(date('d/m/Y', strtotime($lease['end_date']))) ?></td><td><?= e($lease['status'] === 'active' ? 'Actif' : ($lease['status'] === 'ended' ? 'Terminé' : 'Annulé')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="portfolio-panel" id="finance">
        <div class="panel-heading"><div><span class="eyebrow">SUIVI FINANCIER</span><h2>Loyers, revenus et dépenses</h2></div></div>
        <div class="portfolio-columns">
            <form class="portfolio-form" method="post">
                <h3>Échéance de loyer</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_payment">
                <label>Contrat<select name="lease_id" required><option value="">Sélectionner un contrat actif</option><?php foreach ($activeLeases as $lease): ?><option value="<?= (int) $lease['id'] ?>"><?= e($lease['title'] . ' — ' . $lease['first_name'] . ' ' . $lease['last_name']) ?></option><?php endforeach; ?></select></label>
                <div class="portfolio-form-grid"><label>Échéance<input type="date" name="due_date" required></label><label>Montant dû<input type="number" name="amount_due" min="1" required></label><label>Montant encaissé<input type="number" name="amount_paid" min="0" value="0" required></label><label>Date d’encaissement<input type="date" name="paid_at"></label></div>
                <label>Note<input name="note" maxlength="500"></label><button class="button button-dark" type="submit">Enregistrer le loyer</button>
            </form>
            <form class="portfolio-form" method="post">
                <h3>Enregistrer un encaissement</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="collect_payment">
                <label>Échéance à régler<select name="payment_id" required><option value="">Sélectionner un loyer dû</option><?php foreach ($payments as $payment): if ((int) $payment['amount_paid'] < (int) $payment['amount_due']): ?><option value="<?= (int) $payment['id'] ?>"><?= e($payment['title'] . ' — ' . $payment['first_name'] . ' ' . $payment['last_name'] . ' · solde ' . number_format((float) ($payment['amount_due'] - $payment['amount_paid']), 0, ',', ' ') . ' FCFA') ?></option><?php endif; endforeach; ?></select></label>
                <div class="portfolio-form-grid"><label>Montant encaissé<input type="number" name="collection_amount" min="1" required></label><label>Date d’encaissement<input type="date" name="collected_at" required value="<?= e(date('Y-m-d')) ?>"></label></div>
                <button class="button button-dark" type="submit">Ajouter l’encaissement</button>
            </form>
            <form class="portfolio-form" method="post">
                <h3>Ajouter une dépense</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_expense">
                <label>Bien<select name="property_id"><option value="">Dépense générale</option><?php foreach ($properties as $property): if ($property['status'] !== 'archived'): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title']) ?></option><?php endif; endforeach; ?></select></label>
                <div class="portfolio-form-grid"><label>Catégorie<input name="category" maxlength="100" required placeholder="Entretien, taxe…"></label><label>Montant FCFA<input type="number" name="amount" min="1" required></label><label>Date<input type="date" name="entry_date" required value="<?= e(date('Y-m-d')) ?>"></label></div>
                <label>Note<input name="note" maxlength="500"></label><button class="button button-dark" type="submit">Enregistrer la dépense</button>
            </form>
            <form class="portfolio-form" method="post">
                <h3>Ajouter un revenu de vente ou autre</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_income">
                <label>Bien<select name="property_id"><option value="">Revenu général</option><?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title']) ?></option><?php endforeach; ?></select></label>
                <div class="portfolio-form-grid"><label>Type<select name="income_type"><option value="other">Autre revenu</option></select></label><label>Montant FCFA<input type="number" name="amount" min="1" required></label><label>Date<input type="date" name="entry_date" required value="<?= e(date('Y-m-d')) ?>"></label></div>
                <label>Note<input name="note" maxlength="500"></label><button class="button button-dark" type="submit">Enregistrer le revenu</button>
            </form>
        </div>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Échéance</th><th>Bien / locataire</th><th>Dû</th><th>Encaissé</th><th>Reste</th><th>État</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): $remaining = (int) $payment['amount_due'] - (int) $payment['amount_paid']; ?><tr><td><?= e(date('d/m/Y', strtotime($payment['due_date']))) ?></td><td><?= e($payment['title']) ?><small><?= e($payment['first_name'] . ' ' . $payment['last_name']) ?></small></td><td><?= e(number_format((float) $payment['amount_due'], 0, ',', ' ')) ?></td><td><?= e(number_format((float) $payment['amount_paid'], 0, ',', ' ')) ?></td><td><?= e(number_format((float) $remaining, 0, ',', ' ')) ?> FCFA</td><td><?= $remaining === 0 ? 'Payé' : (strtotime($payment['due_date']) <= strtotime(date('Y-m-d')) ? 'Impayé / partiel' : 'À venir') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <div class="portfolio-columns">
        <section class="portfolio-panel"><div class="panel-heading"><div><span class="eyebrow">JOURNAL</span><h2>Activités récentes</h2></div></div>
            <?php if ($recentActivity): ?><div class="portfolio-list"><?php foreach ($recentActivity as $activity): ?><article class="portfolio-list-item"><div><strong><?= e($activity['activity']) ?></strong><span><?= $activity['title'] ? e($activity['title']) . ' · ' : '' ?><?= e(date('d/m/Y à H:i', strtotime($activity['created_at']))) ?></span></div></article><?php endforeach; ?></div><?php else: ?><p class="portfolio-empty">Aucune activité enregistrée.</p><?php endif; ?>
        </section>
        <section class="portfolio-panel"><div class="panel-heading"><div><span class="eyebrow">HISTORIQUE</span><h2>Locations et ventes par propriétaire</h2></div></div>
            <?php foreach ($owners as $owner): $ownerProperties = array_values(array_filter($properties, static function (array $property) use ($owner): bool { return (int) ($property['owner_id'] ?? 0) === (int) $owner['id']; })); $ownerLeases = array_values(array_filter($leases, static function (array $lease) use ($owner): bool { return (int) ($lease['owner_id'] ?? 0) === (int) $owner['id']; })); $ownerIncome = array_values(array_filter($ownerIncomeHistory, static function (array $income) use ($owner): bool { return (int) $income['owner_id'] === (int) $owner['id']; })); ?>
                <article class="portfolio-list-item"><div><strong><?= e($owner['first_name'] . ' ' . $owner['last_name']) ?></strong><span>Revenus historiques enregistrés : <?= e(number_format((float) $owner['income_total'], 0, ',', ' ')) ?> FCFA · <?= (int) $owner['lease_count'] ?> contrat(s)</span><?php foreach ($ownerProperties as $ownerProperty): ?><span><?= e($ownerProperty['title']) ?> — <?= e($operationalStatuses[$ownerProperty['operational_status']] ?? $ownerProperty['operational_status']) ?> · <?= e(format_price($ownerProperty['price'], $ownerProperty['transaction_type'])) ?></span><?php endforeach; ?><?php foreach ($ownerLeases as $ownerLease): ?><span>Location : <?= e($ownerLease['title']) ?> — <?= e($ownerLease['first_name'] . ' ' . $ownerLease['last_name']) ?> (<?= e(date('d/m/Y', strtotime($ownerLease['start_date']))) ?> au <?= e(date('d/m/Y', strtotime($ownerLease['end_date']))) ?>)</span><?php endforeach; ?><?php foreach (array_slice($ownerIncome, 0, 5) as $income): ?><span><?= e($income['entry_type']) ?> — <?= e($income['property_title']) ?> · <?= e(number_format((float) $income['amount'], 0, ',', ' ')) ?> FCFA · <?= e(date('d/m/Y', strtotime($income['entry_date']))) ?></span><?php endforeach; ?></div></article>
            <?php endforeach; ?>
        </section>
    </div>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../js/location-map.js"></script>
</body>
</html>
