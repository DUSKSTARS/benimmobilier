<?php
require_once __DIR__ . '/includes/app.php';
$user = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

verify_csrf();
$propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
$requestedStatus = post_string('status');
if (!$propertyId || $propertyId < 1 || $requestedStatus !== 'archived') {
    http_response_code(400);
    exit('Action invalide.');
}

$statement = Database::connect()->prepare(
    'UPDATE properties SET status = "archived" WHERE id = :id AND user_id = :user_id AND status = "published"'
);
$statement->execute(['id' => $propertyId, 'user_id' => $user['id']]);
if ($statement->rowCount() === 0) {
    $check = Database::connect()->prepare('SELECT status FROM properties WHERE id = :id AND user_id = :user_id');
    $check->execute(['id' => $propertyId, 'user_id' => $user['id']]);
    $currentStatus = $check->fetchColumn();
    if ($currentStatus === false) {
        http_response_code(404);
        exit('Annonce introuvable.');
    }
    if ($currentStatus !== 'archived') {
        http_response_code(409);
        exit('Seules les annonces publiées peuvent être archivées.');
    }
}

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Le statut de votre annonce a été mis à jour.'];
redirect('mon-espace.php');
