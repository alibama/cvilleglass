<?php
// Read-only list of paid months/weeks. Needs the token from Admin → Integrations.
require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');

$token = setting('api_token');
$sent = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($token === '' || !hash_equals($token, (string)$sent)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Send a valid token: Authorization: Bearer <token>']));
}

$sql = "SELECT p.id, p.student_name, p.email, p.product_id, p.product_name, p.kind, p.method, p.paid_at,
               pp.period, pp.month, pp.amount_cents
        FROM payment_periods pp JOIN payments p ON p.id = pp.payment_id WHERE p.status = 'paid'";
$args = [];
if (preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '')) {
    $sql .= ' AND pp.month = ?';
    $args[] = $_GET['month'];
}
$st = db()->prepare($sql . ' ORDER BY pp.month, p.paid_at');
$st->execute($args);

echo json_encode(['payments' => array_map(fn($r) => [
    'payment_id' => (int)$r['id'],
    'student' => $r['student_name'],
    'email' => $r['email'],
    'class_id' => $r['product_id'] === null ? null : (int)$r['product_id'],
    'class' => $r['product_name'],
    'kind' => $r['kind'],
    'covers' => period_label($r['kind'], $r['period']),
    'period' => $r['period'],
    'month' => $r['month'],
    'amount_cents' => (int)$r['amount_cents'],
    'method' => $r['method'],
    'paid_at' => $r['paid_at'],
], $st->fetchAll())], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
