<?php
// Turns the pay form into a Stripe Checkout Session and sends the visitor there.
require __DIR__ . '/../../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/pay/');

$product = product((int)($_POST['product_id'] ?? 0));
if (!$product || (!$product['visible'] && !current_admin())) redirect('/pay/');
$back = '/pay/?class=' . $product['id'];

$student = trim((string)($_POST['student_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
if ($student === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('Please fill in the student’s name and a valid email.', 'error');
    redirect($back);
}

// Work out what is being paid for: [period => month it counts toward]
$quantity = 1;
if ($product['kind'] === 'once') {
    $quantity = max(1, min(10, (int)($_POST['quantity'] ?? 1)));
    $periods = once_period($product);
} else {
    $allowed = period_options($product['kind']);
    $chosen = array_intersect(array_keys($allowed), (array)($_POST['periods'] ?? []));
    if (!$chosen) {
        flash($product['kind'] === 'monthly' ? 'Pick at least one month.' : 'Pick at least one week.', 'error');
        redirect($back);
    }
    $periods = [];
    foreach ($chosen as $period) $periods[$period] = substr($period, 0, 7);
}

if (setting('stripe_secret_key') === '') {
    flash('Online payment isn’t switched on yet. Please email ' . contact_email() . ' to pay another way.', 'error');
    redirect($back);
}

$price = (int)$product['price_cents'];
$count = $product['kind'] === 'once' ? $quantity : count($periods);
$payment_id = record_payment($product, $student, $email, $periods, $quantity, $price * $count, 'stripe');

// One receipt line per month/week, so the receipt shows exactly what was covered.
$lines = [];
if ($product['kind'] === 'once') {
    $name = $product['name'] . ($product['class_date'] ? ' — ' . period_label('once', $product['class_date']) : '');
    $lines[] = ['name' => $name, 'qty' => $quantity];
} else {
    foreach (array_keys($periods) as $period) {
        $lines[] = ['name' => $product['name'] . ' — ' . period_label($product['kind'], $period), 'qty' => 1];
    }
}
$params = [
    'mode' => 'payment',
    'customer_email' => $email,
    'client_reference_id' => (string)$payment_id,
    'success_url' => site_url('/pay/thanks.php?session_id={CHECKOUT_SESSION_ID}'),
    'cancel_url' => site_url('/pay/?cancelled=1&class=' . $product['id']),
    'metadata' => ['payment_id' => $payment_id, 'student' => $student],
    'payment_intent_data' => [
        'description' => $product['name'] . ' — ' . $student,
        'metadata' => ['payment_id' => $payment_id, 'student' => $student],
    ],
    'line_items' => [],
];
foreach ($lines as $line) {
    $params['line_items'][] = [
        'quantity' => $line['qty'],
        'price_data' => ['currency' => 'usd', 'unit_amount' => $price, 'product_data' => ['name' => $line['name']]],
    ];
}

try {
    $session = stripe_api('POST', 'checkout/sessions', $params);
} catch (RuntimeException $e) {
    error_log('cvilleglass checkout: ' . $e->getMessage());
    db()->prepare("UPDATE payments SET status = 'failed', note = ? WHERE id = ?")->execute([$e->getMessage(), $payment_id]);
    flash('Sorry — online payment isn’t working right now. Please email ' . contact_email() . ' and we’ll sort it out.', 'error');
    redirect($back);
}

db()->prepare('UPDATE payments SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $payment_id]);
redirect($session['url']);
