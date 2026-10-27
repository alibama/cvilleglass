<?php
// Talking to Stripe. No Stripe library needed — just a few HTTPS calls.
// The secret key and webhook secret are set in Admin → Settings.

function http_call(string $method, string $url, array $fields = [], array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('Could not connect: ' . curl_error($ch));
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$body, true) ?: []];
}

function stripe_api(string $method, string $path, array $params = []): array
{
    $key = setting('stripe_secret_key');
    if ($key === '') throw new RuntimeException('Stripe is not set up yet. Add the secret key in Admin → Settings.');
    $url = 'https://api.stripe.com/v1/' . $path;
    if ($method === 'GET' && $params) $url .= '?' . http_build_query($params);
    [$status, $data] = http_call($method, $url, $method === 'GET' ? [] : $params, [CURLOPT_USERPWD => $key . ':']);
    if ($status >= 400) throw new RuntimeException('Stripe said: ' . ($data['error']['message'] ?? "error $status"));
    return $data;
}

function stripe_test_mode(): bool
{
    return (bool)preg_match('/^(sk|rk)_test_/', setting('stripe_secret_key'));
}

function stripe_dashboard_url(string $payment_intent): string
{
    return 'https://dashboard.stripe.com/' . (stripe_test_mode() ? 'test/' : '') . 'payments/' . rawurlencode($payment_intent);
}

/** Check the Stripe-Signature header on a webhook (see Stripe docs: "verify signatures manually"). */
function stripe_signature_ok(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $time = 0;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $time = (int)$v;
        if ($k === 'v1') $signatures[] = $v;
    }
    if (!$time || !$signatures || abs(time() - $time) > $tolerance) return false;
    $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

/** Update our record from a Stripe Checkout Session. Safe to call more than once. */
function apply_checkout_session(array $session): ?array
{
    $pdo = db();
    $find = $pdo->prepare('SELECT * FROM payments WHERE stripe_session_id = ?');
    $find->execute([$session['id'] ?? '']);
    $payment = $find->fetch();
    if (!$payment) return null; // not one of ours (e.g. a donation Payment Link)

    if (($session['payment_status'] ?? '') === 'paid' && $payment['status'] !== 'paid') {
        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = ?, stripe_payment_intent = ? WHERE id = ?")
            ->execute([now(), $session['payment_intent'] ?? null, $payment['id']]);
    } elseif (($session['status'] ?? '') === 'expired' && $payment['status'] === 'pending') {
        $pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([$payment['id']]);
    }
    $find->execute([$session['id']]);
    return $find->fetch() ?: null;
}
