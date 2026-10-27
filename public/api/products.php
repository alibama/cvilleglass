<?php
// Public, read-only list of published classes. See Admin → Integrations.
require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode(['classes' => array_map(fn($p) => [
    'id' => (int)$p['id'],
    'name' => $p['name'],
    'description' => $p['description'],
    'kind' => $p['kind'],
    'price_cents' => (int)$p['price_cents'],
    'class_date' => $p['class_date'] ?: null,
    'pay_url' => site_url('/pay/?class=' . $p['id']),
], products())], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
