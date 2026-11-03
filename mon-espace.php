<?php
require_once __DIR__ . '/includes/app.php';
$user = require_auth();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $firstName = trim(post_string('first_name'));
    $lastName = trim(post_string('last_name'));
    $phone = trim(post_string('phone'));
    $bio = trim(post_string('bio'));

    if ($firstName === '' || $lastName === '') {
        $error = 'Le prénom et le nom sont obligatoires.';
    } elseif (strlen($phone) > 40 || strlen($bio) > 500) {
        $error = 'Le téléphone ou la présentation dépasse la longueur autorisée.';
    } else {
        $update = Database::connect()->prepare(
            'UPDATE users SET first_name = :first_name, last_name = :last_name, phone = :phone, bio = :bio WHERE id = :id'
        );
        $update->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone !== '' ? $phone : null,
            'bio' => $bio !== '' ? $bio : null,
            'id' => $user['id'],
        ]);
        $_SESSION['user']['first_name'] = $firstName;
        $_SESSION['user']['last_name'] = $lastName;
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Votre profil a bien été mis à jour.'];
        redirect('mon-espace.php');
    }
}

$profileQuery = Database::connect()->prepare('SELECT first_name, last_name, email, phone, bio, profile_photo FROM users WHERE id = :id');
$profileQuery->execute(['id' => $user['id']]);
$profile = $profileQuery->fetch();
$listingQuery = Database::connect()->prepare(
    'SELECT p.*, (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM properties p WHERE p.user_id = :user_id ORDER BY p.created_at DESC'
);
$listingQuery->execute(['user_id' => $user['id']]);
$listings = $listingQuery->fetchAll();
$favoritesQuery = Database::connect()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = :user_id');
$favoritesQuery->execute(['user_id' => $user['id']]);
$favoriteCount = (int) $favoritesQuery->fetchColumn();
$returnTo = 'mon-espace.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mon espace — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=9"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section">
    <div class="dashboard-welcome"><?php if ($profile['profile_photo']): ?><img class="dashboard-avatar dashboard-avatar-photo" src="<?= e($profile['profile_photo']) ?>" alt="Photo de profil de <?= e($profile['first_name']) ?>"><?php else: ?><div class="dashboard-avatar"><?= e(strtoupper(substr($profile['first_name'], 0, 1))) ?></div><?php endif; ?><div><span class="eyebrow">VOTRE ESPACE PERSONNEL</span><h1>Bonjour <?= e($profile['first_name']) ?>.</h1><p>Votre prochain projet prend forme ici.</p></div><a class="button button-dark" href="deposer.php">+ Déposer une annonce</a></div>
    <div class="stats-grid">
        <div class="stat-card"><span>Mes annonces</span><strong><?= count($listings) ?></strong><small>biens publiés ou archivés</small></div>
        <div class="stat-card"><span>Mes favoris</span><strong><?= $favoriteCount ?></strong><small>adresses à retrouver</small></div>
        <a class="stat-card stat-link" href="profil.php?id=<?= (int) $user['id'] ?>"><span>Mon profil public</span><strong>↗</strong><small>voir la page de mon profil</small></a>
    </div>
    <div class="dashboard-columns">
        <section class="dashboard-panel">
            <div class="panel-heading"><div><span class="eyebrow">VOTRE ACTIVITÉ</span><h2>Mes annonces</h2></div><a class="text-link" href="deposer.php">Ajouter un bien +</a></div>
            <?php if ($listings): ?>
                <div class="manage-list">
                    <?php foreach ($listings as $listing): ?>
                        <article class="manage-item">
                            <img src="<?= e($listing['image_url']) ?>" alt="" loading="lazy">
                            <div class="manage-info"><strong><?= e($listing['title']) ?></strong><span><?= e($listing['city']) ?> · <?= e(format_price($listing['price'], $listing['transaction_type'])) ?></span><span class="status-pill status-<?= e($listing['status']) ?>"><?= $listing['status'] === 'published' ? 'En ligne' : ($listing['status'] === 'pending' ? 'En attente de validation' : ($listing['status'] === 'rejected' ? 'Refusée' : 'Archivée')) ?></span><?php if ($listing['status'] === 'rejected' && $listing['rejection_reason']): ?><span class="rejection-note"><strong>Motif :</strong> <?= e($listing['rejection_reason']) ?></span><?php endif; ?></div>
                            <?php if ($listing['status'] !== 'archived'): ?><a class="small-action" href="deposer.php?id=<?= (int) $listing['id'] ?>">Modifier<?= $listing['status'] === 'rejected' ? ' et renvoyer' : '' ?></a><?php endif; ?>
                            <?php if ($listing['status'] === 'published'): ?><a class="small-action" href="property.php?id=<?= (int) $listing['id'] ?>">Voir</a><?php endif; ?>
                            <?php if ($listing['status'] === 'published'): ?>
                                <form action="annonce-action.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= (int) $listing['id'] ?>">
                                    <input type="hidden" name="status" value="archived">
                                    <button class="small-action" type="submit">Archiver</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?><div class="mini-empty"><p>Vous n'avez pas encore publié de bien.</p><a class="text-link" href="deposer.php">Créer ma première annonce →</a></div><?php endif; ?>
        </section>
        <section class="dashboard-panel profile-panel" id="profil">
            <div class="panel-heading"><div><span class="eyebrow">VOS INFORMATIONS</span><h2>Mon profil</h2></div></div>
            <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form class="stack-form compact-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="form-row"><label>Prénom<input name="first_name" required maxlength="80" value="<?= e($profile['first_name']) ?>"></label><label>Nom<input name="last_name" required maxlength="80" value="<?= e($profile['last_name']) ?>"></label></div>
                <label>E-mail<input type="email" value="<?= e($profile['email']) ?>" disabled><small>L'adresse e-mail ne peut pas être modifiée ici.</small></label>
                <label>Téléphone<input type="tel" name="phone" maxlength="40" value="<?= e($profile['phone']) ?>"></label>
                <label>Quelques mots sur vous<textarea name="bio" rows="3" maxlength="500"><?= e($profile['bio']) ?></textarea></label>
                <button class="button button-dark" type="submit">Enregistrer mon profil</button>
            </form>
        </section>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
