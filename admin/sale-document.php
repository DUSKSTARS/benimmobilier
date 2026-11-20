<?php
require_once __DIR__ . '/../includes/app.php';
require_admin();

// Accept only the two printable document types supported by this page.
$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
if (!in_array($type, ['receipt', 'contract'], true)) {
    http_response_code(400);
    exit('Type de document invalide.');
}

$db = Database::connect();
$sale = null;
$payment = null;

// Load a completed sale and its buyer/property details for the printable sale document.
if ($type === 'contract') {
    $saleId = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);
    if (!$saleId || $saleId < 1) {
        http_response_code(404);
        exit('Vente introuvable.');
    }
    $statement = $db->prepare(
        'SELECT s.*, p.title, p.address, p.city, b.first_name, b.last_name, b.email, b.phone, b.address AS buyer_address,
            (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = s.id) AS paid_total
         FROM property_sales s JOIN properties p ON p.id = s.property_id
         JOIN buyers b ON b.id = s.buyer_id WHERE s.id = :id'
    );
    $statement->execute(['id' => $saleId]);
    $sale = $statement->fetch();
    if (!$sale) {
        http_response_code(404);
        exit('Vente introuvable.');
    }
} else {
    // Load the payment even when it was collected before a reservation became a sale.
    $paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);
    if (!$paymentId || $paymentId < 1) {
        http_response_code(404);
        exit('Paiement introuvable.');
    }
    $statement = $db->prepare(
        'SELECT sp.*, COALESCE(s.id, 0) AS sale_id, COALESCE(s.sale_price, r.agreed_price) AS sale_price,
            COALESCE(s.sale_date, DATE(sp.paid_at)) AS sale_date,
            COALESCE(s.contract_reference, CONCAT("Réservation #", r.id)) AS contract_reference,
            p.title, p.address, p.city, b.first_name, b.last_name, b.email, b.phone, b.address AS buyer_address
         FROM sale_payments sp
         LEFT JOIN property_sales s ON s.id = sp.sale_id
         LEFT JOIN property_reservations r ON r.id = sp.reservation_id
         JOIN properties p ON p.id = COALESCE(s.property_id, r.property_id)
         JOIN buyers b ON b.id = COALESCE(s.buyer_id, r.buyer_id)
         WHERE sp.id = :id'
    );
    $statement->execute(['id' => $paymentId]);
    $payment = $statement->fetch();
    if (!$payment) {
        http_response_code(404);
        exit('Paiement introuvable.');
    }
}

// Render an HTML document designed for printing or saving as PDF in the browser.
$documentTitle = $type === 'contract' ? 'Document de vente' : 'Reçu de paiement';
$buyerName = $type === 'contract'
    ? $sale['first_name'] . ' ' . $sale['last_name']
    : $payment['first_name'] . ' ' . $payment['last_name'];
$propertyTitle = $type === 'contract' ? $sale['title'] : $payment['title'];
$documentDate = $type === 'contract' ? $sale['sale_date'] : $payment['paid_at'];
$amount = $type === 'contract' ? $sale['sale_price'] : $payment['amount'];
$reference = $type === 'contract'
    ? ($sale['contract_reference'] ?: 'VENTE-' . $sale['id'])
    : 'RECU-' . $payment['id'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($documentTitle) ?> — Habita</title>
    <style>
        body { margin: 0; color: #1d2825; font: 15px/1.6 Arial, sans-serif; background: #f5f6f3; }
        main { width: min(760px, calc(100% - 36px)); margin: 32px auto; padding: 48px; background: #fff; box-shadow: 0 10px 40px #1d28251a; }
        header { display: flex; justify-content: space-between; gap: 20px; padding-bottom: 20px; border-bottom: 2px solid #245b46; }
        h1 { margin: 35px 0 8px; color: #245b46; font-size: 30px; }
        h2 { margin-top: 30px; font-size: 17px; }
        p { margin: 8px 0; }
        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        dl div { padding: 12px; background: #f7f7f2; }
        dt { color: #74807a; font-size: 12px; }
        dd { margin: 4px 0 0; font-weight: 700; }
        .amount { color: #245b46; font-size: 23px; font-weight: 700; }
        .notice { margin-top: 28px; padding: 12px; border: 1px solid #e8ebe6; color: #59645d; font-size: 12px; }
        .actions { width: min(760px, calc(100% - 36px)); margin: 16px auto; }
        button { padding: 11px 18px; border: 0; border-radius: 5px; color: #fff; background: #245b46; font-weight: 700; cursor: pointer; }
        @media print { body { background: white; } main { width: auto; margin: 0; padding: 20px; box-shadow: none; } .actions { display: none; } }
        @media (max-width: 550px) { main { padding: 22px; } dl { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button></div>
<main>
    <header><strong>HABITA · Transactions immobilières</strong><span>Référence : <?= e($reference) ?></span></header>
    <h1><?= e($documentTitle) ?></h1>
    <p>Émis le <?= e(date('d/m/Y', strtotime($documentDate))) ?></p>
    <?php if ($type === 'contract'): ?>
        <h2>Parties et bien vendu</h2>
        <p><strong>Acquéreur :</strong> <?= e($buyerName) ?></p>
        <p><strong>Contact :</strong> <?= e($sale['phone'] ?: 'Non renseigné') ?><?= $sale['email'] ? ' · ' . e($sale['email']) : '' ?></p>
        <p><strong>Bien :</strong> <?= e($sale['title']) ?></p>
        <p><strong>Adresse :</strong> <?= e(trim(($sale['address'] ?? '') . ' ' . $sale['city'])) ?></p>
        <dl>
            <div><dt>Prix convenu de vente</dt><dd><?= e(number_format((float) $sale['sale_price'], 0, ',', ' ')) ?> FCFA</dd></div>
            <div><dt>Date de transaction</dt><dd><?= e(date('d/m/Y', strtotime($sale['sale_date']))) ?></dd></div>
            <div><dt>Montant encaissé enregistré</dt><dd><?= e(number_format((float) $sale['paid_total'], 0, ',', ' ')) ?> FCFA</dd></div>
            <div><dt>Solde restant</dt><dd><?= e(number_format(max(0, (float) $sale['sale_price'] - (float) $sale['paid_total']), 0, ',', ' ')) ?> FCFA</dd></div>
        </dl>
        <p class="notice">Document récapitulatif généré par Habita. Il ne remplace pas un acte authentique ni les formalités légales de transfert de propriété.</p>
        <h2>Signatures</h2><dl><div><dt>Vendeur / représentant</dt><dd><br><br>Signature : ____________________</dd></div><div><dt>Acquéreur</dt><dd><br><br>Signature : ____________________</dd></div></dl>
    <?php else: ?>
        <h2>Reçu de règlement</h2>
        <p>Nous reconnaissons avoir enregistré le paiement ci-dessous.</p>
        <p><strong>Payeur :</strong> <?= e($buyerName) ?></p>
        <p><strong>Bien :</strong> <?= e($payment['title']) ?></p>
        <dl>
            <div><dt>Montant reçu</dt><dd class="amount"><?= e(number_format((float) $payment['amount'], 0, ',', ' ')) ?> FCFA</dd></div>
            <div><dt>Date de paiement</dt><dd><?= e(date('d/m/Y H:i', strtotime($payment['paid_at']))) ?></dd></div>
            <div><dt>Type de règlement</dt><dd><?= e($payment['payment_type'] === 'deposit' ? 'Acompte / réservation' : ($payment['payment_type'] === 'balance' ? 'Solde de vente' : 'Paiement échelonné')) ?></dd></div>
            <div><dt>Mode et référence</dt><dd><?= e($payment['payment_method'] ?: 'Non précisé') ?><?= $payment['payment_reference'] ? ' · ' . e($payment['payment_reference']) : '' ?></dd></div>
        </dl>
        <p class="notice">Reçu généré à partir du registre Habita. À conserver avec la preuve de paiement correspondante.</p>
    <?php endif; ?>
</main>
</body>
</html>
