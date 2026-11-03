<?php
require_once __DIR__ . '/../includes/app.php';
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = post_string('current_password');
    $newPassword = post_string('new_password');
    $confirmation = post_string('password_confirmation');
    $query = Database::connect()->prepare('SELECT password_hash FROM users WHERE id = :id AND role = "admin"');
    $query->execute(['id' => $user['id']]);
    $storedHash = $query->fetchColumn();

    if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
        $error = 'Le mot de passe actuel est incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Le nouveau mot de passe doit comporter au moins 12 caractères.';
    } elseif ($newPassword !== $confirmation) {
        $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
    } elseif ($newPassword === $currentPassword) {
        $error = 'Choisissez un mot de passe différent de l’ancien.';
    } else {
        $update = Database::connect()->prepare(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id AND role = "admin"'
        );
        $update->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);
        $_SESSION['must_change_password'] = false;
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Votre mot de passe administrateur a été modifié.'];
        redirect('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sécuriser le compte admin — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=9">
</head>
<body class="auth-page">
<header class="site-header"><div class="nav-wrap"><a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a></div></header>
<main class="auth-layout">
    <div class="auth-visual"><span class="eyebrow">PROTÉGEZ VOTRE ESPACE</span><h1>Un nouveau mot de passe pour commencer.</h1><p>Le mot de passe initial est temporaire. Choisissez un mot de passe personnel et unique.</p></div>
    <section class="auth-card">
        <span class="eyebrow">SÉCURITÉ ADMINISTRATEUR</span>
        <h2>Changez le mot de passe.</h2>
        <p>Cette étape est nécessaire avant de gérer les annonces.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="stack-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Mot de passe actuel<input type="password" name="current_password" autocomplete="current-password" required></label>
            <label>Nouveau mot de passe<input type="password" name="new_password" autocomplete="new-password" minlength="12" required><small>12 caractères minimum.</small></label>
            <label>Confirmer le nouveau mot de passe<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <button class="button button-dark button-full" type="submit">Enregistrer et continuer</button>
        </form>
    </section>
</main>
<?php $assetPrefix = '../'; require __DIR__ . '/../footer.php'; ?>
</body>
</html>
