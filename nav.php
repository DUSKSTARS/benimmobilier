<?php
$navUser = current_user();
$navFlash = flash_message();
?>
<header class="site-header">
    <div class="nav-wrap navbar navbar-expand-lg">
        <a class="brand" href="index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <button class="navbar-toggler nav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNavigation" aria-controls="primaryNavigation" aria-expanded="false" aria-label="Ouvrir le menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse nav-menu" id="primaryNavigation">
            <nav class="main-nav" aria-label="Navigation principale">
                <a href="index.php#annonces">Acheter</a>
                <a href="index.php?type=rent#annonces">Louer</a>
                <a href="<?= $navUser ? 'deposer.php' : 'inscription.php' ?>">Déposer une annonce</a>
                <?php if (!$navUser): ?><a href="admin/login.php">Administration</a><?php endif; ?>
            </nav>
            <div class="nav-actions">
                <?php if ($navUser): ?>
                    <a class="nav-favorites" href="favoris.php">♡ <span>Favoris</span></a>
                    <?php if (($navUser['role'] ?? '') === 'admin'): ?><a class="nav-admin" href="admin/index.php">Administration</a><?php endif; ?>
                    <a class="nav-account" href="mon-espace.php"><?php if (!empty($navUser['profile_photo'])): ?><img class="avatar avatar-photo" src="<?= e($navUser['profile_photo']) ?>" alt=""><?php else: ?><span class="avatar"><?= e(strtoupper(substr($navUser['first_name'], 0, 1))) ?></span><?php endif; ?><span>Mon espace</span></a>
                    <form class="logout-form" action="deconnexion.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="nav-logout" type="submit">Quitter</button>
                    </form>
                <?php else: ?>
                    <a class="nav-login" href="connexion.php">Connexion</a>
                    <a class="button button-dark nav-register" href="inscription.php">Créer un compte</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
<?php if ($navFlash): ?>
    <div class="flash-message flash-<?= e($navFlash['type'] ?? 'info') ?>" role="status"><?= e($navFlash['message'] ?? '') ?></div>
<?php endif; ?>
