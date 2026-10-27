<?php
// Stripe calls this when a checkout finishes, even if the buyer closes the tab.
// Set it up in Stripe → Developers → Webhooks (see Admin → Settings for the exact URL).
require __DIR__ . '/../../app/bootstrap.php';

$payload = (string)file_get_contents('php://input');
$secret = setting('stripe_webhook_secret');
if ($secret === '' || !stripe_signature_ok($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret)) {
    http_response_code(400);
    exit('bad signature');
}

$event = json_decode($payload, true) ?: [];
$type = $event['type'] ?? '';
$session = $event['data']['object'] ?? [];

if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired'], true)) {
    apply_checkout_session($session);
} elseif ($type === 'checkout.session.async_payment_failed') {
    db()->prepare("UPDATE payments SET status = 'failed' WHERE stripe_session_id = ? AND status = 'pending'")
        ->execute([$session['id'] ?? '']);
}
save_setting('stripe_webhook_last_seen', now());

header('Content-Type: application/json');
echo '{"received":true}';
