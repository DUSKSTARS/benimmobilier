<?php
require_once __DIR__ . '/includes/app.php';

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$type = $_GET['type'] ?? '';
$minPrice = filter_input(INPUT_GET, 'min_price', FILTER_VALIDATE_INT);
$minPrice = is_int($minPrice) ? max(5000, $minPrice) : 5000;
$conditions = ["p.status = 'published'"];
$parameters = [];

if ($search !== '') {
    $conditions[] = '(p.title LIKE :search_title OR p.city LIKE :search_city OR p.description LIKE :search_description)';
    $searchValue = '%' . $search . '%';
    $parameters['search_title'] = $searchValue;
    $parameters['search_city'] = $searchValue;
    $parameters['search_description'] = $searchValue;
}
if (in_array($type, ['sale', 'rent'], true)) {
    $conditions[] = 'p.transaction_type = :type';
    $parameters['type'] = $type;
}
$conditions[] = 'p.price >= :min_price';
$parameters['min_price'] = $minPrice;

$statement = Database::connect()->prepare(
    'SELECT p.*, u.first_name, u.last_name,
        (SELECT COUNT(*) FROM favorites f WHERE f.property_id = p.id) AS favorite_count
     FROM properties p LEFT JOIN users u ON u.id = p.user_id
     WHERE ' . implode(' AND ', $conditions) . '
     ORDER BY p.featured DESC, p.created_at DESC LIMIT 12'
);
$statement->execute($parameters);
$properties = $statement->fetchAll();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Trouvez votre prochain chez-vous parmi une sélection de biens immobiliers.">
    <title>Habita — Trouvez votre prochain chez-vous</title>
    <link rel="stylesheet" href="bootstrap-5.2.3-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css?v=9">
</head>
<body>
<?php require __DIR__ . '/nav.php'; ?>
<main>
    <section class="hero">
        <div class="hero-content">
            <span class="eyebrow">LE BON ENDROIT POUR COMMENCER</span>
            <h1>Un lieu à vous.<br><span>Une vie à inventer.</span></h1>
            <p>Des maisons qui ont une âme, des appartements où tout commence. Votre prochain chez-vous est ici.</p>
            <form class="search-panel" action="index.php" method="get">
                <label class="search-field">
                    <span>Où souhaitez-vous vivre ?</span>
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Ville, quartier, mot-clé">
                </label>
                <label class="search-field search-select">
                    <span>Votre projet</span>
                    <select name="type">
                        <option value="">Tous les biens</option>
                        <option value="sale" <?= $type === 'sale' ? 'selected' : '' ?>>À acheter</option>
                        <option value="rent" <?= $type === 'rent' ? 'selected' : '' ?>>À louer</option>
                    </select>
                </label>
                <label class="search-field search-budget">
                    <span>Budget minimum</span>
                    <input type="number" name="min_price" min="5000" step="5000" value="<?= $minPrice ?>" placeholder="5 000 FCFA ou plus">
                </label>
                <button class="button button-dark search-button" type="submit"><span aria-hidden="true">⌕</span> Rechercher</button>
            </form>
            <div class="hero-trust"><span class="trust-stars">★★★★★</span> Des milliers de projets trouvent leur adresse.</div>
        </div>
        <div class="hero-note"><span class="note-icon">⌂</span><span><strong>Le bon chez-vous</strong><br>se trouve parfois juste ici.</span></div>
    </section>

    <section class="section listing-section" id="annonces">
        <div class="section-heading">
            <div><span class="eyebrow">SÉLECTION HABITA</span><h2>Des lieux qui font du bien.</h2><p>Une sélection pensée pour votre prochain chapitre.</p></div>
            <a class="text-link" href="index.php#annonces">Explorer les annonces <span aria-hidden="true">→</span></a>
        </div>
        <?php if (!$properties): ?>
            <div class="empty-state"><span class="empty-icon">⌂</span><h3>Aucun bien ne correspond à votre recherche.</h3><p>Essayez une autre ville ou élargissez votre budget.</p><a class="button button-outline" href="index.php">Voir toutes les annonces</a></div>
        <?php else: ?>
            <div class="property-grid">
                <?php foreach ($properties as $property): ?>
                    <?php require __DIR__ . '/includes/property-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section owner-banner">
        <div class="owner-copy"><span class="eyebrow">VOUS AVEZ UN BIEN À PROPOSER ?</span><h2>Votre prochain acquéreur<br>est peut-être déjà là.</h2><p>Publiez votre annonce en quelques minutes et donnez vie à un nouveau projet.</p><a class="button button-light" href="<?= $user ? 'deposer.php' : 'inscription.php' ?>">Déposer une annonce <span aria-hidden="true">→</span></a></div>
        <div class="owner-image" role="img" aria-label="Façade lumineuse d'une maison contemporaine"></div>
    </section>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
