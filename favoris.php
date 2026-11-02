<?php
require_once __DIR__ . '/includes/app.php';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $propertyId = filter_input(INPUT_POST, 'property_id', FILTER_VALIDATE_INT);
    if (!$propertyId || $propertyId < 1) {
        http_response_code(400);
        exit('Annonce invalide.');
    }

    $db = Database::connect();
    $exists = $db->prepare('SELECT id FROM properties WHERE id = :id AND status = "published"');
    $exists->execute(['id' => $propertyId]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        exit('Cette annonce n’est plus disponible.');
    }

    $visitorToken = $user ? null : guest_favorite_token();
    $db->beginTransaction();
    try {
    if ($user) {
        $check = $db->prepare('SELECT id FROM favorites WHERE user_id = :user_id AND property_id = :property_id FOR UPDATE');
        $check->execute(['user_id' => $user['id'], 'property_id' => $propertyId]);
    } else {
        $check = $db->prepare('SELECT id FROM favorites WHERE guest_token = :guest_token AND property_id = :property_id FOR UPDATE');
        $check->execute(['guest_token' => $visitorToken, 'property_id' => $propertyId]);
    }
    $favoriteId = $check->fetchColumn();
    if ($favoriteId) {
        if ($user) {
            $remove = $db->prepare('DELETE FROM favorites WHERE id = :id AND user_id = :user_id');
            $remove->execute(['id' => $favoriteId, 'user_id' => $user['id']]);
        } else {
            $remove = $db->prepare('DELETE FROM favorites WHERE id = :id AND guest_token = :guest_token');
            $remove->execute(['id' => $favoriteId, 'guest_token' => $visitorToken]);
        }
        $liked = false;
    } else {
        if ($user) {
            $add = $db->prepare('INSERT INTO favorites (user_id, property_id) VALUES (:user_id, :property_id)');
            $add->execute(['user_id' => $user['id'], 'property_id' => $propertyId]);
        } else {
            $add = $db->prepare('INSERT INTO favorites (guest_token, property_id) VALUES (:guest_token, :property_id)');
            $add->execute(['guest_token' => $visitorToken, 'property_id' => $propertyId]);
        }
        $liked = true;
    }

    $countQuery = $db->prepare('SELECT COUNT(*) FROM favorites WHERE property_id = :property_id');
    $countQuery->execute(['property_id' => $propertyId]);
    $favoriteCount = (int) $countQuery->fetchColumn();
    $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (is_string($accept) && strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'liked' => $liked,
            'count' => $favoriteCount,
            'property_id' => $propertyId,
        ]);
        exit;
    }

    redirect(safe_return_target(post_string('return_to')));
}

if (!$user) {
    require_auth();
}

$statement = Database::connect()->prepare(
    'SELECT p.*, u.first_name, u.last_name,
        (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM favorites mine
     JOIN properties p ON p.id = mine.property_id
     LEFT JOIN users u ON u.id = p.user_id
     WHERE mine.user_id = :user_id AND p.status = "published"
     ORDER BY mine.created_at DESC'
);
$statement->execute(['user_id' => $user['id']]);
$properties = $statement->fetchAll();
$returnTo = 'favoris.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mes favoris — Habita</title><link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/styles.css?v=6"></head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main class="section page-section">
    <div class="section-heading"><div><span class="eyebrow">VOS COUPS DE CŒUR</span><h1>Les lieux que vous aimez.</h1><p>Retrouvez ici les annonces enregistrées pour plus tard.</p></div><a class="text-link" href="index.php#annonces">Découvrir d'autres biens →</a></div>
    <?php if ($properties): ?><div class="property-grid"><?php foreach ($properties as $property): require __DIR__ . '/includes/property-card.php'; endforeach; ?></div>
    <?php else: ?><div class="empty-state"><span class="empty-icon">♡</span><h3>Vos prochains coups de cœur sont ici.</h3><p>Enregistrez une annonce pour la retrouver facilement.</p><a class="button button-dark" href="index.php#annonces">Explorer les annonces</a></div><?php endif; ?>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
