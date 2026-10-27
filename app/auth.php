<?php
// Admin sign-in: Google (for anyone on the admin list) or the backup
// username/password (admin / password until changed in Settings).

function current_admin(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['cvg'])) return null;
    start_session();
    return $_SESSION['admin'] ?? null;
}

function require_admin(): string
{
    $who = current_admin();
    if (!$who) {
        redirect('/admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    }
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex');
    return $who;
}

function sign_in_as(string $who): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin'] = $who;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function safe_next(?string $next): string
{
    return ($next && str_starts_with($next, '/admin/') && !str_contains($next, '//')) ? $next : '/admin/';
}

// ---------- backup username + password ----------

function password_login_ok(string $user, string $pass): bool
{
    $hash = setting('admin_password_hash');
    $pass_ok = $hash === '' ? hash_equals(CVG_DEFAULT_PASSWORD, $pass) : password_verify($pass, $hash);
    $user_ok = hash_equals(strtolower(setting('admin_username', CVG_DEFAULT_USER)), strtolower(trim($user)));
    return $pass_ok && $user_ok;
}

function using_default_password(): bool
{
    return setting('admin_password_hash') === '';
}

/** 10 wrong passwords from one address in 15 minutes locks that address out for a while. */
function too_many_failures(string $ip): bool
{
    db()->prepare('DELETE FROM login_failures WHERE at < ?')->execute([time() - 900]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_failures WHERE ip = ?');
    $st->execute([$ip]);
    return $st->fetchColumn() >= 10;
}

function note_failure(string $ip): void
{
    db()->prepare('INSERT INTO login_failures (ip, at) VALUES (?, ?)')->execute([$ip, time()]);
}

// ---------- Google sign-in ----------

function google_ready(): bool
{
    return setting('google_client_id') !== '' && setting('google_client_secret') !== '';
}

function google_redirect_uri(): string
{
    return site_url('/admin/google.php');
}

function google_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('google_client_id'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    ]);
}

/** Swap Google's one-time code for the signed-in person's verified email and name. */
function google_identity(string $code): array
{
    [$status, $data] = http_call('POST', 'https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => setting('google_client_id'),
        'client_secret' => setting('google_client_secret'),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    if ($status !== 200 || empty($data['id_token'])) {
        throw new RuntimeException('Google sign-in failed: ' . ($data['error_description'] ?? $data['error'] ?? "error $status"));
    }
    // The token came straight from Google over HTTPS, so its contents can be trusted as-is.
    $part = explode('.', $data['id_token'])[1] ?? '';
    $claims = json_decode(base64_decode(strtr($part, '-_', '+/')), true) ?: [];
    if (($claims['aud'] ?? '') !== setting('google_client_id') || empty($claims['email_verified'])) {
        throw new RuntimeException('Google did not confirm that email address.');
    }
    return [strtolower($claims['email']), $claims['name'] ?? ''];
}
