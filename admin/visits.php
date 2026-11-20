<?php
require_once __DIR__ . '/../includes/app.php';
$admin = require_admin();
$db = Database::connect();
$error = '';

// Validate the local date/time submitted by the browser's datetime-local input.
$validDateTime = static function (string $dateTime): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dateTime)) {
        return false;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d\TH:i', $dateTime);

    return $parsed !== false && $parsed->format('Y-m-d\TH:i') === $dateTime;
};

// Write a property-linked audit event for created and completed visit actions.
$recordActivity = static function (string $activity, int $propertyId) use ($db, $admin): void {
    $statement = $db->prepare(
        'INSERT INTO property_activity (property_id, actor_id, activity) VALUES (:property_id, :actor_id, :activity)'
    );
    $statement->execute([
        'property_id' => $propertyId,
        'actor_id' => $admin['id'],
        'activity' => $activity,
    ]);
};

// Rotate an agent's bearer token and retain only its one-way hash in the database.
$createAgentPortalLink = static function (int $agentId) use ($db): string {
    $token = bin2hex(random_bytes(32));
    $statement = $db->prepare(
        'UPDATE real_estate_agents SET portal_token_hash = :token_hash WHERE id = :id AND active = 1'
    );
    $statement->execute(['token_hash' => hash('sha256', $token), 'id' => $agentId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Impossible de créer l’accès de cet agent.');
    }

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!is_string($host) || !preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/visits.php')));
    $basePath = preg_replace('~/admin$~', '', rtrim($scriptDirectory, '/'));

    return $scheme . '://' . $host . $basePath . '/agent.php?token=' . $token;
};

// Process visit scheduling, agent registration, and visit follow-up forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'save_agent') {
            $firstName = trim(post_string('first_name'));
            $lastName = trim(post_string('last_name'));
            $email = trim(post_string('email'));
            $phone = trim(post_string('phone'));
            if ($firstName === '' || $lastName === '' || strlen($firstName) > 80 || strlen($lastName) > 80
                || $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 190 || strlen($phone) > 40) {
                throw new RuntimeException('Vérifiez les coordonnées de l’agent immobilier.');
            }
            $db->prepare(
                'INSERT INTO real_estate_agents (first_name, last_name, email, phone)
                 VALUES (:first_name, :last_name, :email, :phone)'
            )->execute([
                'first_name' => $firstName, 'last_name' => $lastName,
                'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null,
            ]);
            $agentId = (int) $db->lastInsertId();
            $_SESSION['agent_invite_link'] = $createAgentPortalLink($agentId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'L’agent immobilier a été enregistré.'];
            redirect('visits.php#agents');
        }

        if ($action === 'regenerate_agent_access') {
            $agentId = filter_var(post_string('agent_id'), FILTER_VALIDATE_INT);
            if (!is_int($agentId) || $agentId < 1) {
                throw new RuntimeException('Sélectionnez un agent valide.');
            }
            $_SESSION['agent_invite_link'] = $createAgentPortalLink($agentId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Un nouvel accès sécurisé a été créé pour cet agent.'];
            redirect('visits.php#agents');
        }

        if ($action === 'schedule_visit') {
            $propertyId = filter_var(post_string('property_id'), FILTER_VALIDATE_INT);
            $agentInput = post_string('agent_id');
            $agentId = $agentInput === '' ? null : filter_var($agentInput, FILTER_VALIDATE_INT);
            $prospectName = trim(post_string('prospect_name'));
            $prospectEmail = trim(post_string('prospect_email'));
            $prospectPhone = trim(post_string('prospect_phone'));
            $scheduledAt = post_string('scheduled_at');
            $followUpNote = trim(post_string('follow_up_note'));
            if (!$propertyId || ($agentInput !== '' && (!is_int($agentId) || $agentId < 1))
                || $prospectName === '' || strlen($prospectName) > 160
                || $prospectEmail !== '' && !filter_var($prospectEmail, FILTER_VALIDATE_EMAIL)
                || strlen($prospectEmail) > 190 || strlen($prospectPhone) > 40
                || !$validDateTime($scheduledAt) || strlen($followUpNote) > 500) {
                throw new RuntimeException('Vérifiez le bien, le prospect, l’agent et la date de visite.');
            }
            $propertyQuery = $db->prepare(
                'SELECT title FROM properties WHERE id = :id AND status = "published"
                 AND operational_status IN ("available", "reserved")'
            );
            $propertyQuery->execute(['id' => $propertyId]);
            $property = $propertyQuery->fetch();
            $agentExists = true;
            if ($agentId !== null) {
                $agentQuery = $db->prepare('SELECT id FROM real_estate_agents WHERE id = :id AND active = 1');
                $agentQuery->execute(['id' => $agentId]);
                $agentExists = (bool) $agentQuery->fetchColumn();
            }
            if (!$property || !$agentExists) {
                throw new RuntimeException('Le bien ou l’agent sélectionné n’est pas disponible.');
            }
            $db->prepare(
                'INSERT INTO property_visits
                    (property_id, agent_id, prospect_name, prospect_email, prospect_phone, scheduled_at, follow_up_note)
                 VALUES (:property_id, :agent_id, :prospect_name, :prospect_email, :prospect_phone, :scheduled_at, :follow_up_note)'
            )->execute([
                'property_id' => $propertyId, 'agent_id' => $agentId,
                'prospect_name' => $prospectName,
                'prospect_email' => $prospectEmail !== '' ? $prospectEmail : null,
                'prospect_phone' => $prospectPhone !== '' ? $prospectPhone : null,
                'scheduled_at' => str_replace('T', ' ', $scheduledAt) . ':00',
                'follow_up_note' => $followUpNote !== '' ? $followUpNote : null,
            ]);
            $recordActivity('Visite programmée pour ' . $prospectName . ' — ' . $property['title'], $propertyId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La visite a été programmée.'];
            redirect('visits.php#visits');
        }

        if ($action === 'assign_visit') {
            $visitId = filter_var(post_string('visit_id'), FILTER_VALIDATE_INT);
            $agentId = filter_var(post_string('agent_id'), FILTER_VALIDATE_INT);
            if (!is_int($visitId) || $visitId < 1 || !is_int($agentId) || $agentId < 1) {
                throw new RuntimeException('Choisissez une demande et un agent valides.');
            }
            $agentQuery = $db->prepare('SELECT id FROM real_estate_agents WHERE id = :id AND active = 1');
            $agentQuery->execute(['id' => $agentId]);
            $visitQuery = $db->prepare('SELECT property_id, prospect_name FROM property_visits WHERE id = :id');
            $visitQuery->execute(['id' => $visitId]);
            $visit = $visitQuery->fetch();
            if (!$agentQuery->fetchColumn() || !$visit) {
                throw new RuntimeException('La demande ou l’agent sélectionné est introuvable.');
            }
            $db->prepare('UPDATE property_visits SET agent_id = :agent_id WHERE id = :id')
                ->execute(['agent_id' => $agentId, 'id' => $visitId]);
            $recordActivity('Agent affecté à la visite de ' . $visit['prospect_name'], (int) $visit['property_id']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La demande a été affectée à l’agent.'];
            redirect('visits.php#visits');
        }

        if ($action === 'update_visit') {
            $visitId = filter_var(post_string('visit_id'), FILTER_VALIDATE_INT);
            $status = post_string('status');
            $interested = post_string('interested');
            $report = trim(post_string('report'));
            $followUpNote = trim(post_string('follow_up_note'));
            if (!$visitId || !in_array($status, ['scheduled', 'confirmed', 'completed', 'cancelled'], true)
                || !in_array($interested, ['0', '1'], true)
                || strlen($report) > 5000 || strlen($followUpNote) > 500) {
                throw new RuntimeException('Vérifiez le statut, le compte rendu et le suivi du prospect.');
            }
            $visitQuery = $db->prepare('SELECT property_id, prospect_name FROM property_visits WHERE id = :id');
            $visitQuery->execute(['id' => $visitId]);
            $visit = $visitQuery->fetch();
            if (!$visit) {
                throw new RuntimeException('La visite à modifier est introuvable.');
            }
            $db->prepare(
                'UPDATE property_visits SET status = :status, interested = :interested,
                    report = :report, follow_up_note = :follow_up_note WHERE id = :id'
            )->execute([
                'status' => $status, 'interested' => (int) $interested,
                'report' => $report !== '' ? $report : null,
                'follow_up_note' => $followUpNote !== '' ? $followUpNote : null,
                'id' => $visitId,
            ]);
            $recordActivity('Visite ' . $status . ' — prospect ' . $visit['prospect_name'], (int) $visit['property_id']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Le suivi de la visite a été mis à jour.'];
            redirect('visits.php#visits');
        }

        throw new RuntimeException('Action de visite inconnue.');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

// Load selectable properties, agents, all visits, and prospects requiring follow-up.
$properties = $db->query(
    'SELECT id, title, city FROM properties WHERE status = "published"
     AND operational_status IN ("available", "reserved") ORDER BY title'
)->fetchAll();
$agents = $db->query('SELECT * FROM real_estate_agents WHERE active = 1 ORDER BY last_name, first_name')->fetchAll();
$visits = $db->query(
    'SELECT v.*, p.title, p.city, a.first_name AS agent_first_name, a.last_name AS agent_last_name
     FROM property_visits v JOIN properties p ON p.id = v.property_id
     LEFT JOIN real_estate_agents a ON a.id = v.agent_id
     ORDER BY v.scheduled_at DESC LIMIT 300'
)->fetchAll();
$unassignedVisitCount = (int) $db->query(
    'SELECT COUNT(*) FROM property_visits WHERE agent_id IS NULL AND status = "scheduled"'
)->fetchColumn();
$pendingVisits = $db->query(
    'SELECT v.id, v.prospect_name, v.prospect_phone, v.prospect_email, v.scheduled_at,
        p.title, p.city
     FROM property_visits v JOIN properties p ON p.id = v.property_id
     WHERE v.agent_id IS NULL AND v.status = "scheduled"
     ORDER BY v.created_at ASC LIMIT 300'
)->fetchAll();
$agentInviteLink = $_SESSION['agent_invite_link'] ?? null;
unset($_SESSION['agent_invite_link']);
$interestedProspects = array_values(array_filter($visits, static function (array $visit): bool {
    return (int) $visit['interested'] === 1 && $visit['status'] !== 'cancelled';
}));
$visitStatusLabels = [
    'scheduled' => 'Programmée', 'confirmed' => 'Confirmée',
    'completed' => 'Effectuée', 'cancelled' => 'Annulée',
];
$flash = flash_message();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visites immobilières — Habita</title>
    <link rel="stylesheet" href="../bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/styles.css?v=11">
</head>
<body>
<header class="site-header">
    <div class="nav-wrap">
        <a class="brand" href="../index.php"><span class="brand-mark">h</span>habita<span class="brand-dot">.</span></a>
        <nav class="portfolio-nav" aria-label="Gestion des visites">
            <a href="<?= $unassignedVisitCount ? '#pending-visits' : '#visits' ?>">Visites<?= $unassignedVisitCount ? ' · ' . $unassignedVisitCount . ' à affecter' : '' ?></a><a href="#prospects">Prospects intéressés</a><a href="#agents">Agents</a>
            <a href="sales.php">Ventes</a><a href="portfolio.php">Patrimoine</a>
        </nav>
    </div>
</header>
<?php if ($flash): ?><div class="flash-message flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<main class="section page-section portfolio-page">
    <div class="section-heading">
        <div><span class="eyebrow">ESPACE SÉCURISÉ · <?= e($admin['first_name']) ?></span><h1>Visites immobilières.</h1><p>Planifiez les rendez-vous et assurez le suivi de chaque prospect.</p></div>
        <a class="button button-outline" href="portfolio.php">← Gestion immobilière</a>
    </div>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($unassignedVisitCount): ?><div class="visit-notification" role="status"><strong><?= $unassignedVisitCount ?> demande<?= $unassignedVisitCount > 1 ? 's' : '' ?> de visite à traiter.</strong> Affectez un agent pour lui transmettre les informations du rendez-vous.</div><?php endif; ?>
    <?php if (is_string($agentInviteLink)): ?><div class="visit-notification"><strong>Accès individuel de l’agent :</strong> partagez ce lien uniquement avec la personne concernée. <a href="<?= e($agentInviteLink) ?>" target="_blank" rel="noopener noreferrer"><?= e($agentInviteLink) ?></a></div><?php endif; ?>

    <?php if ($pendingVisits): ?>
        <section class="portfolio-panel" id="pending-visits">
            <div class="panel-heading"><div><span class="eyebrow">NOUVELLES DEMANDES</span><h2>À affecter (<?= $unassignedVisitCount ?>)</h2></div></div>
            <div class="portfolio-list">
                <?php foreach ($pendingVisits as $visit): ?>
                    <article class="portfolio-list-item">
                        <div><strong><?= e($visit['prospect_name']) ?> · <?= e($visit['title']) ?></strong><span><?= e($visit['city']) ?> · souhaitée le <?= e(date('d/m/Y à H:i', strtotime($visit['scheduled_at']))) ?></span><span><?= e($visit['prospect_phone'] ?: 'Téléphone non renseigné') ?><?= $visit['prospect_email'] ? ' · ' . e($visit['prospect_email']) : '' ?></span></div>
                        <?php if ($agents): ?><form class="visit-assign-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign_visit"><input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>"><label class="visually-hidden" for="pending-agent-<?= (int) $visit['id'] ?>">Agent à affecter</label><select id="pending-agent-<?= (int) $visit['id'] ?>" name="agent_id" required><option value="">Choisir un agent</option><?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['id'] ?>"><?= e($agent['first_name'] . ' ' . $agent['last_name']) ?></option><?php endforeach; ?></select><button class="small-action" type="submit">Affecter</button></form><?php else: ?><span>Créez un agent pour affecter cette demande.</span><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="portfolio-columns">
        <section class="portfolio-panel" id="agents">
            <div class="panel-heading"><div><span class="eyebrow">ÉQUIPE COMMERCIALE</span><h2>Ajouter un agent immobilier</h2></div></div>
            <form class="portfolio-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_agent">
                <div class="portfolio-form-grid">
                    <label>Prénom<input name="first_name" maxlength="80" required></label><label>Nom<input name="last_name" maxlength="80" required></label>
                    <label>E-mail<input type="email" name="email" maxlength="190"></label><label>Téléphone<input type="tel" name="phone" maxlength="40"></label>
                </div>
                <button class="button button-dark" type="submit">Enregistrer l’agent</button>
            </form>
            <div class="portfolio-list"><?php foreach ($agents as $agent): ?><article class="portfolio-list-item"><div><strong><?= e($agent['first_name'] . ' ' . $agent['last_name']) ?></strong><span><?= e($agent['phone'] ?: 'Téléphone non renseigné') ?><?= $agent['email'] ? ' · ' . e($agent['email']) : '' ?></span></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="regenerate_agent_access"><input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>"><button class="small-action" type="submit">Créer / renouveler son accès</button></form></article><?php endforeach; ?></div>
        </section>

        <section class="portfolio-panel" id="visits">
            <div class="panel-heading"><div><span class="eyebrow">NOUVEAU RENDEZ-VOUS</span><h2>Programmer une visite</h2></div></div>
            <form class="portfolio-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="schedule_visit">
                <div class="portfolio-form-grid">
                    <label>Bien à visiter<select name="property_id" required><option value="">Sélectionner</option><?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"><?= e($property['title'] . ' — ' . $property['city']) ?></option><?php endforeach; ?></select></label>
                    <label>Agent immobilier<select name="agent_id"><option value="">À affecter plus tard</option><?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['id'] ?>"><?= e($agent['first_name'] . ' ' . $agent['last_name']) ?></option><?php endforeach; ?></select></label>
                    <label>Nom du prospect<input name="prospect_name" maxlength="160" required></label>
                    <label>Téléphone<input type="tel" name="prospect_phone" maxlength="40"></label>
                    <label>E-mail<input type="email" name="prospect_email" maxlength="190"></label>
                    <label>Date et heure<input type="datetime-local" name="scheduled_at" required></label>
                    <label class="portfolio-wide">Note de suivi<textarea name="follow_up_note" rows="2" maxlength="500"></textarea></label>
                </div>
                <button class="button button-dark" type="submit">Programmer la visite</button>
            </form>
        </section>
    </div>

    <section class="portfolio-panel" id="prospects">
        <div class="panel-heading"><div><span class="eyebrow">À RELANCER</span><h2>Prospects intéressés (<?= count($interestedProspects) ?>)</h2></div></div>
        <?php if ($interestedProspects): ?><div class="portfolio-list"><?php foreach ($interestedProspects as $visit): ?><article class="portfolio-list-item"><div><strong><?= e($visit['prospect_name']) ?> · <?= e($visit['title']) ?></strong><span><?= e($visit['prospect_phone'] ?: 'Téléphone non renseigné') ?><?= $visit['prospect_email'] ? ' · ' . e($visit['prospect_email']) : '' ?></span><span>Agent : <?= e($visit['agent_first_name'] . ' ' . $visit['agent_last_name']) ?> · visite du <?= e(date('d/m/Y à H:i', strtotime($visit['scheduled_at']))) ?></span><?php if ($visit['follow_up_note']): ?><span>Suivi : <?= e($visit['follow_up_note']) ?></span><?php endif; ?></div></article><?php endforeach; ?></div><?php else: ?><p class="portfolio-empty">Aucun prospect n’a encore été marqué comme intéressé.</p><?php endif; ?>
    </section>

    <section class="portfolio-panel">
        <div class="panel-heading"><div><span class="eyebrow">HISTORIQUE ET COMPTE RENDU</span><h2>Toutes les visites</h2></div></div>
        <div class="portfolio-table-wrap"><table class="portfolio-table"><thead><tr><th>Date et bien</th><th>Prospect</th><th>Agent</th><th>Compte rendu et suivi</th><th>Mise à jour</th></tr></thead><tbody>
            <?php foreach ($visits as $visit): ?><tr>
                <td><?= e(date('d/m/Y à H:i', strtotime($visit['scheduled_at']))) ?><small><?= e($visit['title']) ?> · <?= e($visit['city']) ?></small></td>
                <td><?= e($visit['prospect_name']) ?><small><?= e($visit['prospect_phone'] ?: 'Téléphone non renseigné') ?><?= $visit['prospect_email'] ? ' · ' . e($visit['prospect_email']) : '' ?></small></td>
                <td>
                    <?= $visit['agent_id'] ? e($visit['agent_first_name'] . ' ' . $visit['agent_last_name']) : '<span class="status-pill status-pending">À affecter</span>' ?>
                </td>
                <td><?= e($visit['report'] ?: 'Aucun compte rendu') ?><small><?= e($visit['follow_up_note'] ?: 'Aucune note de suivi') ?></small></td>
                <td>
                    <form class="portfolio-form visit-update-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_visit"><input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>">
                        <label>Statut<select name="status"><?php foreach ($visitStatusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $visit['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label>Prospect intéressé<select name="interested"><option value="0" <?= (int) $visit['interested'] === 0 ? 'selected' : '' ?>>Non / à confirmer</option><option value="1" <?= (int) $visit['interested'] === 1 ? 'selected' : '' ?>>Oui, à suivre</option></select></label>
                        <label>Compte rendu<textarea name="report" rows="2" maxlength="5000"><?= e($visit['report'] ?? '') ?></textarea></label>
                        <label>Note de relance<input name="follow_up_note" maxlength="500" value="<?= e($visit['follow_up_note'] ?? '') ?>"></label>
                        <button class="small-action" type="submit">Enregistrer</button>
                    </form>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
