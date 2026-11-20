<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $lastActivity = (int) ($_SESSION['last_activity'] ?? time());
    if (time() - $lastActivity >= 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Votre session a expiré après 30 minutes d’inactivité.'];
    } else {
        $_SESSION['last_activity'] = time();
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

function save_profile_photo(array $file): string
{
    $maxBytes = 3 * 1024 * 1024;
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('La photo doit faire 3 Mo maximum.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Le téléversement de la photo a échoué. Réessayez.');
    }
    if (!isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name'])
        || !is_int($file['size'])
        || !is_uploaded_file($file['tmp_name'])
        || $file['size'] > $maxBytes) {
        throw new RuntimeException('La photo doit faire 3 Mo maximum.');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($imageInfo === false || !isset($extensions[$mimeType]) || $imageInfo['mime'] !== $mimeType) {
        throw new RuntimeException('Choisissez une image valide au format JPG, PNG ou WebP.');
    }
    if ($imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
        throw new RuntimeException('La photo ne peut pas dépasser 6 000 pixels de côté.');
    }

    $directory = __DIR__ . '/../images/profiles';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Le dossier de photos de profil ne peut pas être créé.');
    }

    $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Impossible d’enregistrer la photo. Vérifiez les permissions du dossier images.');
    }

    return 'images/profiles/' . $filename;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function guest_favorite_token(): string
{
    if (!isset($_SESSION['guest_favorite_token']) || !is_string($_SESSION['guest_favorite_token'])) {
        $_SESSION['guest_favorite_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['guest_favorite_token'];
}

function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Connectez-vous pour accéder à cette page.'];
        }
        header('Location: connexion.php');
        exit;
    }

    return $user;
}

function require_admin(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Accès réservé à l’administration.');
    }
    if (!empty($_SESSION['must_change_password'])) {
        redirect('change-password.php');
    }

    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Votre session a expiré. Rechargez la page et réessayez.');
    }
}

function flash_message(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

function format_price($price, string $transactionType): string
{
    return number_format((float) $price, 0, ',', ' ') . ' FCFA' . ($transactionType === 'rent' ? ' / mois' : '');
}

function save_property_photos(array $files): array
{
    if (!$files) {
        return [];
    }

    if (count($files) > 4) {
        throw new RuntimeException('Choisissez quatre images maximum.');
    }

    $uploads = [];
    foreach ($files as $file) {
        if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
            throw new RuntimeException('Les images sélectionnées sont invalides. Réessayez.');
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('Chaque image doit faire 5 Mo maximum.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le téléversement d’une image a échoué. Réessayez.');
        }
        $temporaryPath = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($temporaryPath) || !is_int($size) || !is_uploaded_file($temporaryPath)
            || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('Chaque image doit faire 5 Mo maximum.');
        }
        $imageInfo = getimagesize($temporaryPath);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($imageInfo === false || !isset($extensions[$mimeType]) || $imageInfo['mime'] !== $mimeType) {
            throw new RuntimeException('Choisissez des images valides au format JPG, PNG ou WebP.');
        }
        if ($imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
            throw new RuntimeException('Une image ne peut pas dépasser 6 000 pixels de côté.');
        }
        $uploads[] = ['temporary_path' => $temporaryPath, 'extension' => $extensions[$mimeType]];
    }

    // Keep uploaded files under the public images directory so listing image URLs work on the live site.
    $directory = __DIR__ . '/../images/properties';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Le dossier des images d’annonces ne peut pas être créé.');
    }

    $savedPaths = [];
    try {
        foreach ($uploads as $upload) {
            $filename = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
            $destination = $directory . '/' . $filename;
            if (!move_uploaded_file($upload['temporary_path'], $destination)) {
                throw new RuntimeException('Impossible d’enregistrer une image. Vérifiez les permissions du dossier images.');
            }
            $savedPaths[] = 'images/properties/' . $filename;
        }
    } catch (Throwable $exception) {
        delete_property_photos($savedPaths);
        throw $exception;
    }

    return $savedPaths;
}

function delete_property_photos(array $paths): void
{
    $directory = realpath(__DIR__ . '/../images/properties');
    if ($directory === false) {
        return;
    }
    $prefix = 'images/properties/';
    foreach ($paths as $path) {
        if (!is_string($path) || strpos($path, $prefix) !== 0) {
            continue;
        }
        $file = realpath(__DIR__ . '/../' . $path);
        if ($file !== false && strpos($file, $directory . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
            unlink($file);
        }
    }
}

function save_property_videos(array $files): array
{
    if (!$files || !isset($files['error']) || !is_array($files['error'])) {
        return [];
    }

    $extensions = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv'];
    $uploads = [];
    foreach ($files['error'] as $index => $error) {
        if (!is_int($error)) {
            throw new RuntimeException('Les vidéos sélectionnées sont invalides. Réessayez.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('Chaque vidéo doit peser 100 Mo maximum.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le téléversement d’une vidéo a échoué. Réessayez.');
        }

        $temporaryPath = $files['tmp_name'][$index] ?? null;
        $size = $files['size'][$index] ?? null;
        if (!is_string($temporaryPath) || !is_int($size) || !is_uploaded_file($temporaryPath)
            || $size < 1 || $size > 100 * 1024 * 1024) {
            throw new RuntimeException('Chaque vidéo doit peser 100 Mo maximum.');
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            throw new RuntimeException('Choisissez une vidéo valide au format MP4, WebM ou OGG.');
        }
        $uploads[] = ['temporary_path' => $temporaryPath, 'extension' => $extensions[$mimeType]];
        if (count($uploads) > 3) {
            throw new RuntimeException('Choisissez trois vidéos maximum par bien.');
        }
    }

    $directory = __DIR__ . '/../videos/properties';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Le dossier des vidéos ne peut pas être créé.');
    }

    $savedPaths = [];
    try {
        foreach ($uploads as $upload) {
            $filename = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
            if (!move_uploaded_file($upload['temporary_path'], $directory . '/' . $filename)) {
                throw new RuntimeException('Impossible d’enregistrer une vidéo. Vérifiez les permissions du dossier videos.');
            }
            $savedPaths[] = 'videos/properties/' . $filename;
        }
    } catch (Throwable $exception) {
        delete_property_videos($savedPaths);
        throw $exception;
    }

    return $savedPaths;
}

function delete_property_videos(array $paths): void
{
    $directory = realpath(__DIR__ . '/../videos/properties');
    if ($directory === false) {
        return;
    }
    foreach ($paths as $path) {
        if (!is_string($path)
            || !preg_match('~^videos/properties/[a-f0-9]{48}\.(mp4|webm|ogv)$~', $path)) {
            continue;
        }
        $file = realpath(__DIR__ . '/../' . $path);
        if ($file !== false && strpos($file, $directory . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
            unlink($file);
        }
    }
}

function property_video_mime_type(string $path): ?string
{
    if (!preg_match('~^videos/properties/[a-f0-9]{48}\.(mp4|webm|ogv)$~', $path, $matches)) {
        return null;
    }

    return ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg'][$matches[1]];
}

function parse_location_coordinates(string $latitudeInput, string $longitudeInput): array
{
    if ($latitudeInput === '' && $longitudeInput === '') {
        return [null, null];
    }
    if ($latitudeInput === '' || $longitudeInput === '') {
        throw new RuntimeException('Choisissez un point complet sur la carte ou utilisez votre position actuelle.');
    }

    $latitude = filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
    if ((!is_float($latitude) && !is_int($latitude)) || (!is_float($longitude) && !is_int($longitude))
        || !is_finite((float) $latitude) || !is_finite((float) $longitude)
        || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        throw new RuntimeException('Le point choisi sur la carte est invalide.');
    }

    return [(float) $latitude, (float) $longitude];
}

function send_account_unlock_code(PDO $db, array $account): void
{
    $host = getenv('MAIL_HOST');
    $username = getenv('MAIL_USERNAME');
    $password = getenv('MAIL_PASSWORD');
    $fromAddress = getenv('MAIL_FROM_ADDRESS');
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Habita';
    $port = (int) (getenv('MAIL_PORT') ?: 587);
    $encryption = getenv('MAIL_ENCRYPTION') ?: 'tls';

    if (!$host || !$username || !$password || !$fromAddress
        || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
        || !in_array($encryption, ['tls', 'ssl'], true)) {
        throw new RuntimeException('Le service e-mail de déblocage n’est pas configuré.');
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    $code = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 1800);
    $statement = $db->prepare(
        'UPDATE users
         SET unlock_code_hash = :code_hash, unlock_code_expires_at = :expires_at,
             unlock_code_attempts = 0, unlock_code_sent_at = CURRENT_TIMESTAMP
         WHERE id = :id AND locked_at IS NOT NULL'
    );
    $statement->execute([
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => $expiresAt,
        'id' => $account['id'],
    ]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Ce compte n’est pas bloqué.');
    }

    $transport = new Swift_SmtpTransport($host, $port, $encryption);
    $transport->setUsername($username);
    $transport->setPassword($password);
    $mailer = new Swift_Mailer($transport);
    $message = (new Swift_Message('Code temporaire de déblocage — Habita'))
        ->setFrom([$fromAddress => $fromName])
        ->setTo([$account['email']])
        ->setBody(
            "Bonjour " . ($account['first_name'] ?? '') . ",\n\n"
            . "Votre identifiant de connexion est : " . $account['email'] . "\n"
            . "Votre code temporaire de déblocage est : " . $code . "\n\n"
            . "Ce code expire dans 30 minutes. Il ne contient pas votre mot de passe.\n"
            . "Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.",
            'text/plain',
            'UTF-8'
        );

    try {
        if ($mailer->send($message) < 1) {
            throw new Swift_TransportException('Le serveur e-mail n’a accepté aucun destinataire.');
        }
    } catch (Swift_TransportException $exception) {
        $clearCode = $db->prepare(
            'UPDATE users SET unlock_code_hash = NULL, unlock_code_expires_at = NULL,
                unlock_code_sent_at = NULL WHERE id = :id'
        );
        $clearCode->execute(['id' => $account['id']]);
        throw new RuntimeException('Le code n’a pas pu être envoyé. Vérifiez la configuration e-mail et réessayez.', 0, $exception);
    }
}

function safe_return_target($target): string
{
    if (is_string($target) && preg_match('/^(index\.php|favoris\.php|mon-espace\.php|property\.php\?id=\d+)$/', $target)) {
        return $target;
    }

    return 'index.php';
}
