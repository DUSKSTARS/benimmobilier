<?php
require_once __DIR__ . '/../includes/app.php';
require_admin();

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    http_response_code(404);
    exit('Document introuvable.');
}

$statement = Database::connect()->prepare(
    'SELECT stored_name, original_name, mime_type FROM owner_documents WHERE id = :id'
);
$statement->execute(['id' => $documentId]);
$document = $statement->fetch();
if (!$document) {
    http_response_code(404);
    exit('Document introuvable.');
}

$directory = realpath(dirname(__DIR__) . '/private/owner-documents');
$file = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $document['stored_name']);
if ($file === false || strpos($file, $directory . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Le fichier du document est introuvable.');
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $document['original_name']) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
