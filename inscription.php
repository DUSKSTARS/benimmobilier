<?php
require_once __DIR__ . '/includes/app.php';

if (current_user()) {
    redirect('mon-espace.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $firstName = trim(post_string('first_name'));
    $lastName = trim(post_string('last_name'));
    $email = strtolower(trim(post_string('email')));
    $password = post_string('password');
    $passwordConfirmation = post_string('password_confirmation');
    $photo = $_FILES['profile_photo'] ?? null;
    $photoPath = null;

    if ($firstName === '' || $lastName === '' || strlen($firstName) > 80 || strlen($lastName) > 80 || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vérifiez votre nom et votre adresse e-mail.';
    } elseif (strlen($password) < 10) {
        $error = 'Choisissez un mot de passe de 10 caractères minimum.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            if (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $photoPath = save_profile_photo($photo);
            }
            $statement = Database::connect()->prepare(
                'INSERT INTO users (first_name, last_name, email, password_hash, profile_photo) VALUES (:first_name, :last_name, :email, :password_hash, :profile_photo)'
            );
            $statement->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'profile_photo' => $photoPath,
            ]);
            session_regenerate_id(true);
            $_SESSION['last_activity'] = time();
            $_SESSION['user'] = [
                'id' => (int) Database::connect()->lastInsertId(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'role' => 'user',
                'profile_photo' => $photoPath,
            ];
            redirect('mon-espace.php');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($photoPath !== null && is_file(__DIR__ . '/' . $photoPath)) {
                unlink(__DIR__ . '/' . $photoPath);
            }
            if ($exception->getCode() === '23000') {
                $error = 'Un compte existe déjà avec cette adresse e-mail.';
            } else {
                throw $exception;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Créer un compte — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=6"></head>
<body class="auth-page">
<?php require __DIR__ . '/nav.php'; ?>
<main class="auth-layout">
    <div class="auth-visual auth-visual-register"><span class="eyebrow">TOUT COMMENCE PAR UN LIEU</span><h1>Un nouveau projet mérite un bel espace.</h1><p>Créez votre compte et gardez vos recherches, annonces et coups de cœur au même endroit.</p></div>
    <section class="auth-card"><span class="eyebrow">BIENVENUE CHEZ HABITA</span><h2>Créons votre espace.</h2><p>Quelques informations, et c'est parti.</p>
        <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="stack-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="form-row"><label>Prénom<input type="text" name="first_name" autocomplete="given-name" required maxlength="80" value="<?= e(post_string('first_name')) ?>"></label><label>Nom<input type="text" name="last_name" autocomplete="family-name" required maxlength="80" value="<?= e(post_string('last_name')) ?>"></label></div>
            <label>Adresse e-mail<input type="email" name="email" autocomplete="email" required maxlength="190" value="<?= e(post_string('email')) ?>"></label>
            <label>Photo de profil <span class="optional">(facultative)</span><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG ou WebP · 3 Mo maximum.</small></label>
            <label>Mot de passe<input type="password" name="password" autocomplete="new-password" minlength="10" required><small>10 caractères minimum.</small></label>
            <label>Confirmer le mot de passe<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
            <button class="button button-dark button-full" type="submit">Créer mon compte <span aria-hidden="true">→</span></button>
        </form>
        <p class="auth-switch">Déjà membre ? <a href="connexion.php">Me connecter</a></p>
    </section>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
