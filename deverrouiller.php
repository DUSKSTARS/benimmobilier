<?php
require_once __DIR__ . '/includes/app.php';

if (current_user()) {
    redirect('mon-espace.php');
}

$error = '';
$message = '';
$email = strtolower(trim(post_string('email')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Saisissez une adresse e-mail valide.';
    } elseif ($action === 'send') {
        $db = Database::connect();
        $query = $db->prepare(
            'SELECT id, first_name, email, locked_at, unlock_code_sent_at
             FROM users WHERE email = :email AND locked_at IS NOT NULL'
        );
        $query->execute(['email' => $email]);
        $account = $query->fetch();

        if ($account) {
            $sentAt = $account['unlock_code_sent_at'] ? strtotime($account['unlock_code_sent_at']) : false;
            if ($sentAt !== false && time() - $sentAt < 60) {
                $error = 'Un code vient d’être envoyé. Attendez une minute avant d’en demander un autre.';
            } else {
                try {
                    send_account_unlock_code($db, $account);
                    $message = 'Si ce compte est bloqué, un code temporaire vient d’être envoyé à son adresse e-mail.';
                } catch (RuntimeException $exception) {
                    error_log('Habita account unlock email failed: ' . $exception->getMessage());
                    $error = 'Le code n’a pas pu être envoyé. Vérifiez la configuration e-mail ou contactez l’administration.';
                }
            }
        } else {
            $message = 'Si ce compte est bloqué, un code temporaire vient d’être envoyé à son adresse e-mail.';
        }
    } elseif ($action === 'verify') {
        $code = trim(post_string('code'));
        $db = Database::connect();
        $query = $db->prepare(
            'SELECT id, unlock_code_hash, unlock_code_expires_at, unlock_code_attempts
             FROM users WHERE email = :email AND locked_at IS NOT NULL'
        );
        $query->execute(['email' => $email]);
        $account = $query->fetch();
        $expiresAt = $account && $account['unlock_code_expires_at']
            ? strtotime($account['unlock_code_expires_at'])
            : false;

        if (!$account || !$account['unlock_code_hash'] || $expiresAt === false || $expiresAt < time()) {
            $error = 'Le code est invalide ou expiré. Demandez un nouveau code.';
        } elseif (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $account['unlock_code_hash'])) {
            if ((int) $account['unlock_code_attempts'] < 5) {
                $recordAttempt = $db->prepare(
                    'UPDATE users
                     SET unlock_code_hash = IF(unlock_code_attempts >= 4, NULL, unlock_code_hash),
                         unlock_code_expires_at = IF(unlock_code_attempts >= 4, NULL, unlock_code_expires_at),
                         unlock_code_sent_at = IF(unlock_code_attempts >= 4, NULL, unlock_code_sent_at),
                         unlock_code_attempts = unlock_code_attempts + 1
                     WHERE id = :id AND locked_at IS NOT NULL'
                );
                $recordAttempt->execute(['id' => $account['id']]);
            }
            $error = 'Le code est incorrect. Après cinq erreurs, demandez un nouveau code.';
        } else {
            $unlock = $db->prepare(
                'UPDATE users SET locked_at = NULL, failed_login_attempts = 0,
                    unlock_code_hash = NULL, unlock_code_expires_at = NULL,
                    unlock_code_attempts = 0, unlock_code_sent_at = NULL
                 WHERE id = :id AND locked_at IS NOT NULL'
            );
            $unlock->execute(['id' => $account['id']]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Votre compte est débloqué. Vous pouvez vous connecter.'];
            redirect('connexion.php');
        }
    } else {
        http_response_code(400);
        exit('Action non autorisée.');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Débloquer mon compte — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=7"></head>
<body class="auth-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="auth-layout">
    <div class="auth-visual"><span class="eyebrow">ACCÈS SÉCURISÉ</span><h1>Retrouvez votre espace.</h1><p>Un code temporaire envoyé à l’adresse de votre compte permet de le débloquer.</p></div>
    <section class="auth-card">
        <span class="eyebrow">DÉBLOCAGE DU COMPTE</span><h2>Un code, et vous repartez.</h2>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($message): ?><div class="flash-message flash-success" role="status"><?= e($message) ?></div><?php endif; ?>
        <form class="stack-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify">
            <label>Adresse e-mail du compte<input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
            <label>Code temporaire à 6 chiffres<input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
            <button class="button button-dark button-full" type="submit">Débloquer mon compte</button>
        </form>
        <form class="stack-form unlock-resend-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send">
            <label>Adresse e-mail du compte<input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
            <button class="button button-outline button-full" type="submit">Renvoyer un code</button>
        </form>
        <p class="auth-switch"><a href="connexion.php">Retour à la connexion</a></p>
    </section>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
