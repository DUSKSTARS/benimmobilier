<?php
require_once __DIR__ . '/includes/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Méthode non autorisée.');
}

verify_csrf();
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Vous êtes déconnecté(e).'];
redirect('index.php');
