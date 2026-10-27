<?php
// Google sign-in: first visit sends you to Google, Google sends you back here.
require __DIR__ . '/../../app/bootstrap.php';

if (!google_ready()) redirect('/admin/login.php');
start_session();

if (!isset($_GET['code'])) {
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    $_SESSION['google_next'] = safe_next($_GET['next'] ?? null);
    redirect(google_auth_url($_SESSION['google_state']));
}

if (!hash_equals($_SESSION['google_state'] ?? 'x', (string)($_GET['state'] ?? ''))) {
    flash('Google sign-in timed out. Please try again.', 'error');
    redirect('/admin/login.php');
}
unset($_SESSION['google_state']);

try {
    [$email, $name] = google_identity((string)$_GET['code']);
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'error');
    redirect('/admin/login.php');
}

$st = db()->prepare('SELECT * FROM admins WHERE email = ?');
$st->execute([$email]);
$row = $st->fetch();
if (!$row) redirect('/admin/login.php?denied=' . urlencode($email));

db()->prepare('UPDATE admins SET last_login = ?, name = CASE WHEN name = \'\' THEN ? ELSE name END WHERE email = ?')
    ->execute([now(), $name, $email]);
$next = $_SESSION['google_next'] ?? '/admin/';
sign_in_as($email);
redirect($next);
