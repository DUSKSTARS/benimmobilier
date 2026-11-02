<?php
require_once __DIR__ . '/../includes/app.php';

$user = current_user();
if ($user && ($user['role'] ?? '') === 'admin') {
    redirect('index.php');
}

$flash = flash_message();
$error = '';
$showUnlockLink = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim(post_string('username'));
    $password = post_string('password');
    $accountEmail = $username === 'admin' ? 'admin@habita.local' : $username;
    $statement = Database::connect()->prepare(
        'SELECT id, first_name, last_name, email, password_hash, role, profile_photo, must_change_password, locked_at
         FROM users WHERE email = :email AND role = "admin" LIMIT 1'
    );
    $statement->bindValue(':email', $accountEmail, PDO::PARAM_STR);
    $statement->execute();
    $account = $statement->fetch();
    $defaultPasswordMatches = $account
        && (bool) $account['must_change_password']
        && hash_equals($account['password_hash'], hash('sha256', $password));

    $validUsername = $username === 'admin' || filter_var($username, FILTER_VALIDATE_EMAIL);
    if ($account && $account['locked_at'] !== null) {
        $showUnlockLink = true;
        $error = 'Ce compte administrateur est bloqué après trois tentatives. Utilisez le code envoyé à son adresse e-mail.';
    } elseif ($account && $validUsername
        && (password_verify($password, $account['password_hash']) || $defaultPasswordMatches)) {
        if ($defaultPasswordMatches) {
            $upgrade = Database::connect()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $upgrade->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $account['id']]);
        }
        $resetAttempts = Database::connect()->prepare(
            'UPDATE users SET failed_login_attempts = 0, unlock_code_hash = NULL,
                unlock_code_expires_at = NULL, unlock_code_attempts = 0, unlock_code_sent_at = NULL
             WHERE id = :id'
        );
        $resetAttempts->execute(['id' => $account['id']]);
        session_regenerate_id(true);
        $_SESSION['last_activity'] = time();
        $_SESSION['must_change_password'] = (bool) $account['must_change_password'];
        unset($account['password_hash'], $account['must_change_password'], $account['locked_at']);
        $_SESSION['user'] = $account;
        redirect(!empty($_SESSION['must_change_password']) ? 'change-password.php' : 'index.php');
    } else {
        if ($account && $validUsername) {
            $db = Database::connect();
            $recordFailure = $db->prepare(
                'UPDATE users
                 SET failed_login_attempts = failed_login_attempts + 1,
                     locked_at = CASE WHEN failed_login_attempts + 1 >= 3 THEN CURRENT_TIMESTAMP ELSE NULL END
                 WHERE id = :id AND locked_at IS NULL'
            );
            $recordFailure->execute(['id' => $account['id']]);
            $statusQuery = $db->prepare('SELECT locked_at FROM users WHERE id = :id');
            $statusQuery->execute(['id' => $account['id']]);
            if ($statusQuery->fetchColumn() !== null) {
                $showUnlockLink = true;
                try {
                    send_account_unlock_code($db, $account);
                    $error = 'Ce compte administrateur est bloqué après trois tentatives. Un code temporaire a été envoyé à son adresse e-mail.';
                } catch (RuntimeException $exception) {
                    error_log('Habita admin unlock email failed: ' . $exception->getMessage());
                    $error = 'Ce compte administrateur est bloqué, mais le code n’a pas pu être envoyé. Réessayez depuis la page de déblocage ou contactez l’administration.';
                }
            }
        }
        if (!$showUnlockLink) {
            $error = 'Identifiant ou mot de passe administrateur incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion administrateur — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=7">
</head>
<body class="auth-page">
<header class="site-header">
    <div class="nav-wrap">
        <a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <a class="nav-login" href="../index.php">Retour au site</a>
    </div>
</header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="auth-layout">
    <div class="auth-visual"><span class="eyebrow">ESPACE SÉCURISÉ</span><h1>La qualité des annonces, entre vos mains.</h1><p>Vérifiez les biens proposés, consultez les profils et accompagnez les vendeurs.</p></div>
    <section class="auth-card">
        <span class="eyebrow">ADMINISTRATION HABITA</span>
        <h2>Connexion admin.</h2>
        <p>Identifiez-vous pour gérer les annonces et les membres.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($showUnlockLink): ?><p class="auth-switch"><a href="../deverrouiller.php">Saisir un code ou en demander un nouveau</a></p><?php endif; ?>
        <form class="stack-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Identifiant<input name="username" autocomplete="username" required value="<?= e(post_string('username')) ?>"></label>
            <label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button button-dark button-full" type="submit">Accéder à l’administration →</button>
        </form>
        <p class="auth-switch">Compte initial : <strong>admin</strong><br>Un nouveau mot de passe sera exigé à la première connexion. Les autres comptes administrateurs peuvent aussi utiliser leur adresse e-mail.</p>
    </section>
</main>
<?php $assetPrefix = '../'; require __DIR__ . '/../footer.php'; ?>
</body>
</html>
