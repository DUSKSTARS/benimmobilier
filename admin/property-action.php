<?php
require_once __DIR__ . '/../includes/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

verify_csrf();
$propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
$status = post_string('status');
$reason = trim(post_string('rejection_reason'));
if (!$propertyId || $propertyId < 1 || !in_array($status, ['published', 'rejected', 'archived'], true)) {
    http_response_code(400);
    exit('Action invalide.');
}
if ($status === 'rejected' && ($reason === '' || strlen($reason) > 1000)) {
    http_response_code(400);
    exit('Un motif de refus de 1 à 1 000 caractères est obligatoire.');
}

$statement = Database::connect()->prepare(
    'UPDATE properties SET status = :status, rejection_reason = :rejection_reason, reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP
     WHERE id = :id'
);
$statement->execute([
    'status' => $status,
    'rejection_reason' => $status === 'rejected' ? $reason : null,
    'reviewed_by' => current_user()['id'],
    'id' => $propertyId,
]);
if ($statement->rowCount() === 0) {
    $check = Database::connect()->prepare('SELECT 1 FROM properties WHERE id = :id');
    $check->execute(['id' => $propertyId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        exit('Annonce introuvable.');
    }
}

$_SESSION['flash'] = ['type' => 'success', 'message' => $status === 'rejected' ? 'L’annonce a été refusée et le motif a été communiqué au vendeur.' : ($status === 'published' ? 'L’annonce est maintenant publiée.' : 'L’annonce a été archivée.')];
redirect('index.php');
