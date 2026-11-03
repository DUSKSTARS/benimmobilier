<?php
require_once __DIR__ . '/../includes/app.php';
$admin = require_admin();
$db = Database::connect();

$counts = $db->query(
    "SELECT COUNT(*) AS all_count,
        SUM(status = 'published') AS published_count,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'rejected') AS rejected_count,
        SUM(status = 'archived') AS archived_count
     FROM properties"
)->fetch();
$statement = $db->query(
    'SELECT p.*, u.id AS owner_id, u.first_name, u.last_name, u.email AS owner_email,
        u.phone AS owner_phone, u.bio AS owner_bio, u.profile_photo AS owner_photo, u.created_at AS owner_created_at,
        reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name,
        (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM properties p
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
     ORDER BY FIELD(p.status, "pending", "rejected", "published", "archived"), p.created_at DESC'
);
$properties = $statement->fetchAll();
$paymentsStatement = $db->query(
    'SELECT mp.reference, mp.amount, mp.operator, mp.phone, mp.created_at, p.title
     FROM momo_payments mp
     JOIN properties p ON p.id = mp.property_id
     WHERE mp.status = "simulated"
     ORDER BY mp.created_at DESC'
);
$simulatedPayments = $paymentsStatement->fetchAll();
$usersStatement = $db->query(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.bio, u.profile_photo, u.role, u.created_at,
        COUNT(p.id) AS property_count
     FROM users u LEFT JOIN properties p ON p.user_id = u.id
     GROUP BY u.id, u.first_name, u.last_name, u.email, u.phone, u.bio, u.profile_photo, u.role, u.created_at
     ORDER BY u.created_at DESC'
);
$users = $usersStatement->fetchAll();
$flash = flash_message();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=9">
</head>
<body>
<header class="site-header">
    <div class="nav-wrap">
        <a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <nav class="nav-actions" aria-label="Administration">
            <a class="nav-admin" href="index.php">Administration</a>
            <a class="nav-account" href="../mon-espace.php">Mon espace</a>
            <form action="../deconnexion.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="nav-logout" type="submit">Déconnexion</button>
            </form>
            <a class="nav-login" href="../index.php">Voir le site</a>
        </nav>
    </div>
</header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="section page-section">
    <div class="section-heading">
        <div>
            <span class="eyebrow">ESPACE SÉCURISÉ · <?= e($admin['first_name']) ?></span>
            <h1>Administration.</h1>
            <p>Gérez les annonces et leur visibilité sur Habita.</p>
        </div>
        <a class="button button-outline" href="../index.php">Retour au site</a>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><span>Toutes les annonces</span><strong><?= (int) $counts['all_count'] ?></strong><small>biens enregistrés</small></div>
        <div class="stat-card"><span>En ligne</span><strong><?= (int) $counts['published_count'] ?></strong><small>visibles sur le site</small></div>
        <div class="stat-card"><span>En attente</span><strong><?= (int) $counts['pending_count'] ?></strong><small>à examiner</small></div>
        <div class="stat-card"><span>Refusées</span><strong><?= (int) $counts['rejected_count'] ?></strong><small>motif communiqué au vendeur</small></div>
    </div>
    <div class="panel-heading admin-section-heading"><div><span class="eyebrow">MODÉRATION</span><h2 class="admin-panel-title">Annonces des membres</h2></div></div>
    <?php if ($properties): ?>
        <div class="admin-listings">
            <?php foreach ($properties as $property): ?>
                <article class="admin-listing">
                    <?php $imageSource = preg_match('~^https?://~i', $property['image_url']) ? $property['image_url'] : '../' . ltrim($property['image_url'], '/'); ?>
                    <img src="<?= e($imageSource) ?>" alt="" loading="lazy">
                    <div class="admin-listing-info">
                        <strong><?= e($property['title']) ?></strong>
                        <span><?= e($property['city']) ?><?= $property['district'] ? ' · ' . e($property['district']) : '' ?> · <?= e(format_price($property['price'], $property['transaction_type'])) ?> · <?= e(ucfirst($property['property_type'])) ?> · <?= (int) $property['area'] ?> m² · <?= (int) $property['bedrooms'] ?> chambres</span>
                        <span class="admin-description"><?= nl2br(e($property['description'])) ?></span>
                        <span class="status-pill status-<?= e($property['status']) ?>"><?= $property['status'] === 'published' ? 'En ligne' : ($property['status'] === 'pending' ? 'À valider' : ($property['status'] === 'rejected' ? 'Refusée' : 'Archivée')) ?></span>
                        <span>Déposée le <?= e(date('d/m/Y à H:i', strtotime($property['created_at']))) ?> · <?= (int) $property['favorite_count'] ?> favori(s)</span>
                        <?php if ($property['reviewed_at']): ?><span>Examinée le <?= e(date('d/m/Y à H:i', strtotime($property['reviewed_at']))) ?> par <?= e(trim(($property['reviewer_first_name'] ?? '') . ' ' . ($property['reviewer_last_name'] ?? ''))) ?></span><?php endif; ?>
                        <?php if ($property['rejection_reason']): ?><span class="rejection-note"><strong>Motif de refus :</strong> <?= e($property['rejection_reason']) ?></span><?php endif; ?>
                        <div class="admin-owner">
                            <?php if ($property['owner_photo']): ?><img src="../<?= e($property['owner_photo']) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(strtoupper(substr($property['first_name'] ?? 'H', 0, 1))) ?></span><?php endif; ?>
                            <div>
                                <strong><?= e(trim(($property['first_name'] ?? '') . ' ' . ($property['last_name'] ?? '')) ?: 'Compte supprimé / annonce de démonstration') ?></strong>
                                <?php if ($property['owner_id']): ?>
                                    <a href="mailto:<?= e($property['owner_email']) ?>"><?= e($property['owner_email']) ?></a>
                                    <span><?= e($property['owner_phone'] ?: 'Téléphone non renseigné') ?> · Membre depuis <?= e(date('d/m/Y', strtotime($property['owner_created_at']))) ?></span>
                                    <?php if ($property['owner_bio']): ?><span>Présentation : <?= e($property['owner_bio']) ?></span><?php endif; ?>
                                    <a href="../profil.php?id=<?= (int) $property['owner_id'] ?>">Voir le profil public</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a class="small-action" href="../property.php?id=<?= (int) $property['id'] ?>">Voir la fiche</a>
                    <?php if ($property['status'] !== 'published' && $property['status'] !== 'archived'): ?>
                        <form action="property-action.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                            <input type="hidden" name="status" value="published">
                            <button class="small-action" type="submit">Valider et publier</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($property['status'] !== 'archived'): ?>
                        <form action="property-action.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                            <input type="hidden" name="status" value="rejected">
                            <label class="reject-label">Motif de refus<textarea name="rejection_reason" rows="2" minlength="3" maxlength="1000" required placeholder="Expliquez clairement au vendeur pourquoi l'annonce est refusée."></textarea></label>
                            <button class="small-action reject-action" type="submit">Refuser avec ce motif</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($property['status'] === 'published'): ?>
                        <form action="property-action.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                            <input type="hidden" name="status" value="archived">
                            <button class="small-action" type="submit">Archiver</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>Aucune annonce pour le moment.</h3><p>Les annonces publiées apparaîtront ici.</p></div>
    <?php endif; ?>
    <section class="admin-users-section">
        <div class="panel-heading"><div><span class="eyebrow">PAIEMENTS DE DÉMONSTRATION</span><h2 class="admin-panel-title">Achats simulés (<?= count($simulatedPayments) ?>)</h2></div></div>
        <?php if ($simulatedPayments): ?>
            <div class="admin-users-list">
                <?php foreach ($simulatedPayments as $payment): ?>
                    <article class="admin-user">
                        <div class="admin-user-info">
                            <strong><?= e($payment['title']) ?> · <?= e(number_format((float) $payment['amount'], 0, ',', ' ') . ' FCFA') ?></strong>
                            <span><?= e($payment['operator']) ?> · <?= e($payment['phone']) ?> · <?= e(date('d/m/Y à H:i', strtotime($payment['created_at']))) ?></span>
                            <span>Référence : <?= e($payment['reference']) ?> · Simulation uniquement, aucun paiement réel.</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><p>Aucun achat simulé pour le moment.</p></div>
        <?php endif; ?>
    </section>
    <section class="admin-users-section">
        <div class="panel-heading"><div><span class="eyebrow">COMPTES ET COORDONNÉES</span><h2 class="admin-panel-title">Tous les utilisateurs (<?= count($users) ?>)</h2></div></div>
        <?php if ($users): ?>
            <div class="admin-users-list">
                <?php foreach ($users as $member): ?>
                    <article class="admin-user">
                        <?php if ($member['profile_photo']): ?><img src="../<?= e($member['profile_photo']) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= e(strtoupper(substr($member['first_name'], 0, 1))) ?></span><?php endif; ?>
                        <div class="admin-user-info">
                            <strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?><?= $member['role'] === 'admin' ? ' · Administrateur' : '' ?></strong>
                            <a href="mailto:<?= e($member['email']) ?>"><?= e($member['email']) ?></a>
                            <span><?= e($member['phone'] ?: 'Téléphone non renseigné') ?> · <?= (int) $member['property_count'] ?> annonce(s) · Inscrit(e) le <?= e(date('d/m/Y à H:i', strtotime($member['created_at']))) ?></span>
                            <span><?= e($member['bio'] ?: 'Aucune présentation renseignée.') ?></span>
                            <a href="../profil.php?id=<?= (int) $member['id'] ?>">Voir le profil public</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><p>Aucun utilisateur inscrit.</p></div>
        <?php endif; ?>
    </section>
</main>
<?php $assetPrefix = '../'; require __DIR__ . '/../footer.php'; ?>
</body>
</html>
