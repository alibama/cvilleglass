<?php
require __DIR__ . '/../../app/bootstrap.php';

$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
if (current_admin()) redirect($next);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    if (too_many_failures($ip)) {
        $error = 'Too many tries. Wait 15 minutes and try again.';
    } elseif (password_login_ok((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        sign_in_as(setting('admin_username', CVG_DEFAULT_USER));
        redirect($next);
    } else {
        note_failure($ip);
        sleep(1);
        $error = 'That username and password didn’t match.';
    }
}

admin_header('Sign in');
?>
<div class="login-box">
  <h1>Studio admin</h1>
  <?php if (isset($_GET['denied'])): ?>
    <p class="flash flash--error"><?= h($_GET['denied']) ?> isn’t on the admin list. Ask an existing admin to add you under Admins.</p>
  <?php endif ?>
  <?php if ($error): ?><p class="flash flash--error"><?= h($error) ?></p><?php endif ?>

  <?php if (google_ready()): ?>
    <a class="btn btn--primary btn--wide" href="/admin/google.php?next=<?= urlencode($next) ?>">Sign in with Google</a>
    <p class="or">or use the backup login</p>
  <?php else: ?>
    <p class="fine">Google sign-in isn’t set up yet — use the backup login, then add Google keys in Settings.</p>
  <?php endif ?>

  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label class="field">Username <input name="username" autocomplete="username" required></label>
    <label class="field">Password <input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn btn--ghost" type="submit">Sign in</button>
  </form>
</div>
<?php
admin_footer();
