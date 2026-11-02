<?php
require_once __DIR__ . '/includes/app.php';

if (current_user()) {
    redirect('mon-espace.php');
}

$error = '';
$showUnlockLink = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim(post_string('email')));
    $password = post_string('password');

    $db = Database::connect();
    $statement = $db->prepare(
        'SELECT id, first_name, last_name, email, password_hash, role, profile_photo,
            must_change_password, locked_at
         FROM users WHERE email = :email'
    );
    $statement->execute(['email' => $email]);
    $account = $statement->fetch();

    if ($account && $account['locked_at'] !== null) {
        $showUnlockLink = true;
        $error = 'Votre compte est bloqué après trois tentatives. Utilisez le code envoyé à votre adresse e-mail.';
    } elseif ($account && password_verify($password, $account['password_hash'])) {
        $resetAttempts = $db->prepare(
            'UPDATE users SET failed_login_attempts = 0, unlock_code_hash = NULL,
                unlock_code_expires_at = NULL, unlock_code_attempts = 0, unlock_code_sent_at = NULL
             WHERE id = :id'
        );
        $resetAttempts->execute(['id' => $account['id']]);
        session_regenerate_id(true);
        $_SESSION['last_activity'] = time();
        unset($account['password_hash']);
        $_SESSION['must_change_password'] = (bool) $account['must_change_password'];
        unset($account['must_change_password'], $account['locked_at']);
        $_SESSION['user'] = $account;
        redirect('mon-espace.php');
    } else {
        if ($account) {
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
                    $error = 'Votre compte est bloqué après trois tentatives. Un code temporaire a été envoyé à votre adresse e-mail.';
                } catch (RuntimeException $exception) {
                    error_log('Habita account unlock email failed: ' . $exception->getMessage());
                    $error = 'Votre compte est bloqué après trois tentatives, mais le code n’a pas pu être envoyé. Réessayez depuis la page de déblocage ou contactez l’administration.';
                }
            }
        }
        if (!$showUnlockLink) {
            $error = 'Adresse e-mail ou mot de passe incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Connexion — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=7"></head>
<body class="auth-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="auth-layout">
    <div class="auth-visual"><span class="eyebrow">BON RETOUR CHEZ VOUS</span><h1>Votre prochain chapitre vous attend.</h1><p>Retrouvez vos annonces et les lieux que vous avez aimés.</p></div>
    <section class="auth-card"><span class="eyebrow">ESPACE HABITA</span><h2>Ravi de vous revoir.</h2><p>Connectez-vous pour continuer votre projet.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($showUnlockLink): ?><p class="auth-switch"><a href="deverrouiller.php">Saisir un code ou en demander un nouveau</a></p><?php endif; ?>
        <form class="stack-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Adresse e-mail<input type="email" name="email" autocomplete="email" required value="<?= e(post_string('email')) ?>"></label>
            <label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button button-dark button-full" type="submit">Me connecter <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-switch">Pas encore de compte ? <a href="inscription.php">Créer mon espace</a></p>
    </section>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
