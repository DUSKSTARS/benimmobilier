<?php
require_once __DIR__ . '/includes/app.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$db = Database::connect();
$token = $_GET['token'] ?? '';

if (is_string($token) && $token !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        unset($_SESSION['agent_portal_id']);
        unset($_SESSION['agent_portal_last_activity']);
        http_response_code(403);
        exit('Ce lien d’accès agent est invalide.');
    }
    $agentQuery = $db->prepare(
        'SELECT id FROM real_estate_agents WHERE portal_token_hash = :token_hash AND active = 1'
    );
    $agentQuery->execute(['token_hash' => hash('sha256', $token)]);
    $agentId = $agentQuery->fetchColumn();
    if ($agentId === false) {
        unset($_SESSION['agent_portal_id']);
        unset($_SESSION['agent_portal_last_activity']);
        http_response_code(403);
        exit('Ce lien d’accès agent a été renouvelé ou désactivé.');
    }
    session_regenerate_id(true);
    $_SESSION['agent_portal_id'] = (int) $agentId;
}

$agentId = filter_var($_SESSION['agent_portal_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($agentId) || $agentId < 1) {
    http_response_code(403);
    exit('Accès réservé à un agent immobilier. Demandez votre lien personnel à l’administration.');
}

$lastActivity = (int) ($_SESSION['agent_portal_last_activity'] ?? time());
if (time() - $lastActivity >= 1800) {
    unset($_SESSION['agent_portal_id'], $_SESSION['agent_portal_last_activity']);
    http_response_code(403);
    exit('Votre session agent a expiré. Demandez un nouveau lien à l’administration.');
}
$_SESSION['agent_portal_last_activity'] = time();

$agentQuery = $db->prepare('SELECT id, first_name, last_name FROM real_estate_agents WHERE id = :id AND active = 1');
$agentQuery->execute(['id' => $agentId]);
$agent = $agentQuery->fetch();
if (!$agent) {
    unset($_SESSION['agent_portal_id']);
    unset($_SESSION['agent_portal_last_activity']);
    http_response_code(403);
    exit('Cet accès agent a été désactivé.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');
    if ($action === 'logout') {
        unset($_SESSION['agent_portal_id'], $_SESSION['agent_portal_last_activity']);
        redirect('agent.php');
    }

    $visitId = filter_var(post_string('visit_id'), FILTER_VALIDATE_INT);
    $status = post_string('status');
    $interested = post_string('interested');
    $report = trim(post_string('report'));
    $followUpNote = trim(post_string('follow_up_note'));
    if (!is_int($visitId) || $visitId < 1
        || !in_array($status, ['scheduled', 'confirmed', 'completed', 'cancelled'], true)
        || !in_array($interested, ['0', '1'], true)
        || strlen($report) > 5000 || strlen($followUpNote) > 500) {
        $error = 'Vérifiez le statut, le compte rendu et la note de suivi.';
    } else {
        $visitQuery = $db->prepare(
            'SELECT v.property_id, v.prospect_name FROM property_visits v
             WHERE v.id = :visit_id AND v.agent_id = :agent_id'
        );
        $visitQuery->execute(['visit_id' => $visitId, 'agent_id' => $agentId]);
        $visit = $visitQuery->fetch();
        if (!$visit) {
            http_response_code(404);
            exit('Cette visite ne vous est pas affectée.');
        }
        $db->prepare(
            'UPDATE property_visits SET status = :status, interested = :interested,
                report = :report, follow_up_note = :follow_up_note
             WHERE id = :id AND agent_id = :agent_id'
        )->execute([
            'status' => $status,
            'interested' => (int) $interested,
            'report' => $report !== '' ? $report : null,
            'follow_up_note' => $followUpNote !== '' ? $followUpNote : null,
            'id' => $visitId,
            'agent_id' => $agentId,
        ]);
        $db->prepare(
            'INSERT INTO property_activity (property_id, actor_id, activity)
             VALUES (:property_id, NULL, :activity)'
        )->execute([
            'property_id' => (int) $visit['property_id'],
            'activity' => 'Suivi agent mis à jour pour ' . $visit['prospect_name'],
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le suivi de la visite a été enregistré.'];
        redirect('agent.php#visites');
    }
}

$visitsQuery = $db->prepare(
    'SELECT v.*, p.title, p.city, p.district, p.address, p.location_latitude, p.location_longitude
     FROM property_visits v JOIN properties p ON p.id = v.property_id
     WHERE v.agent_id = :agent_id
     ORDER BY v.scheduled_at DESC LIMIT 200'
);
$visitsQuery->execute(['agent_id' => $agentId]);
$visits = $visitsQuery->fetchAll();
$flash = flash_message();
$statusLabels = [
    'scheduled' => 'Programmée', 'confirmed' => 'Confirmée',
    'completed' => 'Effectuée', 'cancelled' => 'Annulée',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Espace agent — Habita</title>
    <link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css?v=11">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>
<header class="site-header"><div class="nav-wrap"><a class="brand" href="index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="nav-logout" type="submit">Quitter mon espace</button></form></div></header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="section page-section portfolio-page">
    <div class="section-heading"><div><span class="eyebrow">ESPACE AGENT</span><h1>Bonjour <?= e($agent['first_name']) ?>.</h1><p>Consultez les visites qui vous sont affectées et mettez à jour leur suivi.</p></div></div>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="portfolio-panel" id="visites">
        <div class="panel-heading"><div><span class="eyebrow">VOTRE AGENDA</span><h2><?= count($visits) ?> visite<?= count($visits) === 1 ? '' : 's' ?></h2></div></div>
        <?php if (!$visits): ?><p class="portfolio-empty">Aucune visite ne vous est affectée pour le moment.</p><?php endif; ?>
        <div class="agent-visit-list">
            <?php foreach ($visits as $visit): ?>
                <article class="portfolio-panel agent-visit-card">
                    <div class="panel-heading"><div><span class="eyebrow"><?= e(date('d/m/Y à H:i', strtotime($visit['scheduled_at']))) ?></span><h2><?= e($visit['title']) ?></h2><p><?= e($visit['city']) ?><?= $visit['district'] ? ' · ' . e($visit['district']) : '' ?><?= $visit['address'] ? ' · ' . e($visit['address']) : '' ?></p></div><span class="status-pill status-<?= e($visit['status']) ?>"><?= e($statusLabels[$visit['status']] ?? $visit['status']) ?></span></div>
                    <?php if ($visit['location_latitude'] !== null && $visit['location_longitude'] !== null): ?><div class="property-map detail-map" data-property-map data-latitude="<?= e($visit['location_latitude']) ?>" data-longitude="<?= e($visit['location_longitude']) ?>"><div data-map-canvas aria-label="Localisation du bien à visiter"></div></div><?php endif; ?>
                    <p><strong>Prospect :</strong> <?= e($visit['prospect_name']) ?> · <?= e($visit['prospect_phone'] ?: 'Téléphone non renseigné') ?><?= $visit['prospect_email'] ? ' · ' . e($visit['prospect_email']) : '' ?></p>
                    <form class="portfolio-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>">
                        <div class="portfolio-form-grid">
                            <label>Statut<select name="status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $visit['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                            <label>Prospect intéressé<select name="interested"><option value="0" <?= (int) $visit['interested'] === 0 ? 'selected' : '' ?>>À confirmer / non</option><option value="1" <?= (int) $visit['interested'] === 1 ? 'selected' : '' ?>>Oui, à suivre</option></select></label>
                            <label class="portfolio-wide">Compte rendu<textarea name="report" rows="3" maxlength="5000"><?= e($visit['report'] ?? '') ?></textarea></label>
                            <label class="portfolio-wide">Note de suivi<textarea name="follow_up_note" rows="2" maxlength="500"><?= e($visit['follow_up_note'] ?? '') ?></textarea></label>
                        </div>
                        <input type="hidden" name="action" value="update_visit">
                        <button class="button button-dark" type="submit">Enregistrer le compte rendu</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="js/location-map.js"></script>
</body>
</html>
