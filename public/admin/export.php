<?php
// Every paid month/week as a CSV, one row per period covered.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cvilleglass-payments-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Paid on', 'Student', 'Email', 'Class', 'Covers', 'Counts toward month', 'Amount', 'Method', 'Note', 'Stripe payment'], ',', '"', '');
$rows = db()->query("SELECT p.*, pp.period, pp.month, pp.amount_cents AS period_cents
    FROM payment_periods pp JOIN payments p ON p.id = pp.payment_id
    WHERE p.status = 'paid' ORDER BY pp.month, lower(p.student_name)");
foreach ($rows as $r) {
    fputcsv($out, [
        $r['paid_at'], $r['student_name'], $r['email'], $r['product_name'],
        period_label($r['kind'], $r['period']), $r['month'], number_format($r['period_cents'] / 100, 2, '.', ''),
        $r['method'], $r['note'], $r['stripe_payment_intent'],
    ], ',', '"', '');
}
