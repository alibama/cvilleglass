<?php
// Every key and password the site needs, in one place. Saved in the database,
// never in the code, so the whole thing can be handed to someone new.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

// Secret fields: blank keeps what's saved, the "remove" box clears it.
$sections = [
    'stripe' => ['stripe_secret_key' => true, 'stripe_webhook_secret' => true],
    'google' => ['google_client_id' => false, 'google_client_secret' => true],
    'site' => ['site_url' => false, 'contact_email' => false],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if (isset($sections[$action])) {
        foreach ($sections[$action] as $key => $secret) {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($secret && isset($_POST['clear_' . $key])) save_setting($key, '');
            elseif (!$secret || $value !== '') save_setting($key, $value);
        }
        flash('Saved.');
        redirect('/admin/settings.php#' . $action);
    }

    if ($action === 'test_stripe') {
        try {
            stripe_api('GET', 'checkout/sessions', ['limit' => 1]);
            flash('Stripe connection works (' . (stripe_test_mode() ? 'test mode' : 'live mode') . ').');
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect('/admin/settings.php#stripe');
    }

    if ($action === 'password') {
        $user = trim((string)($_POST['admin_username'] ?? ''));
        $pass = (string)($_POST['new_password'] ?? '');
        if ($user === '') {
            flash('The username can’t be blank.', 'error');
        } elseif ($pass !== '' && strlen($pass) < 10) {
            flash('Use a password of at least 10 characters.', 'error');
        } elseif ($pass !== ($_POST['confirm_password'] ?? '')) {
            flash('The two passwords didn’t match.', 'error');
        } else {
            save_setting('admin_username', $user);
            if ($pass !== '') save_setting('admin_password_hash', password_hash($pass, PASSWORD_DEFAULT));
            flash('Backup login updated.');
        }
        redirect('/admin/settings.php#password');
    }
}

function secret_input(string $key, string $placeholder): void
{
    $saved = setting($key);
    ?>
    <input name="<?= $key ?>" type="password" autocomplete="off" spellcheck="false"
           placeholder="<?= $saved !== '' ? 'Saved — ends in …' . h(substr($saved, -4)) . '. Leave blank to keep it.' : h($placeholder) ?>">
    <?php if ($saved !== ''): ?>
      <label class="check fine"><input type="checkbox" name="clear_<?= $key ?>"> remove saved value</label>
    <?php endif;
}

$key = setting('stripe_secret_key');
$mode = $key === '' ? 'not set up' : (stripe_test_mode() ? 'test mode' : 'LIVE');
$webhook_seen = setting('stripe_webhook_last_seen');

admin_header('Settings & keys', 'settings');
?>
<div class="admin-head"><h1>Settings &amp; keys</h1></div>
<p class="fine">Everything the site needs to run lives on this page. To hand the studio site to someone new: add their Google email under <a href="/admin/admins.php">Admins</a>, and give them the backup login below.</p>

<section class="panel" id="stripe">
  <div class="panel-head"><h2>Stripe (payments)</h2><span class="pill pill--<?= $key === '' ? 'draft' : (stripe_test_mode() ? 'pending' : 'paid') ?>"><?= h($mode) ?></span></div>
  <form method="post" class="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="stripe">
    <label class="field">Secret key
      <?php secret_input('stripe_secret_key', 'sk_live_… or sk_test_…') ?>
      <span class="help">Stripe Dashboard → <b>Developers → API keys</b> → Secret key. Start with the <b>test</b> key (sk_test_…) and pay with card 4242 4242 4242 4242 to try it; switch to the live key when you’re happy. Money goes to the bank account linked in Stripe.</span>
    </label>
    <label class="field">Webhook signing secret
      <?php secret_input('stripe_webhook_secret', 'whsec_…') ?>
      <span class="help">Stripe Dashboard → <b>Developers → Webhooks → Add endpoint</b>.
        Endpoint URL: <code><?= h(site_url('/pay/webhook.php')) ?></code><br>
        Events: <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code>, <code>checkout.session.expired</code>.
        Then copy the “Signing secret” here. Test and live mode each have their own webhook and secret.
        <?= $webhook_seen ? '<br>Last heard from Stripe: ' . h(date('M j, Y g:ia', strtotime($webhook_seen))) . '.' : '<br>Not heard from Stripe yet.' ?></span>
    </label>
    <div class="btn-row"><button class="btn btn--primary" type="submit">Save Stripe keys</button></div>
  </form>
  <?php if ($key !== ''): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_stripe"><button class="linkish" type="submit">Check the Stripe connection</button></form>
  <?php endif ?>
</section>

<section class="panel" id="google">
  <div class="panel-head"><h2>Google sign-in (admins)</h2><span class="pill pill--<?= google_ready() ? 'paid' : 'draft' ?>"><?= google_ready() ? 'on' : 'off' ?></span></div>
  <form method="post" class="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="google">
    <label class="field">Client ID
      <input name="google_client_id" value="<?= h(setting('google_client_id')) ?>" placeholder="…apps.googleusercontent.com" spellcheck="false">
    </label>
    <label class="field">Client secret
      <?php secret_input('google_client_secret', 'GOCSPX-…') ?>
    </label>
    <p class="help">At <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">console.cloud.google.com → APIs &amp; Services → Credentials</a>:
      set up the OAuth consent screen (External, app name “Charlottesville Glass admin”), then <b>Create credentials → OAuth client ID → Web application</b>.
      Under “Authorized redirect URIs” add <code><?= h(google_redirect_uri()) ?></code>. Copy the Client ID and secret here, then add people on the <a href="/admin/admins.php">Admins</a> page.</p>
    <div class="btn-row"><button class="btn btn--primary" type="submit">Save Google keys</button></div>
  </form>
</section>

<section class="panel" id="password">
  <h2>Backup login</h2>
  <p class="fine">Works even if Google sign-in is off or broken. Default is <b>admin / password</b> — change it.</p>
  <form method="post" class="edit-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label class="field field--short">Username <input name="admin_username" value="<?= h(setting('admin_username', CVG_DEFAULT_USER)) ?>" required></label>
    <label class="field field--short">New password <input name="new_password" type="password" autocomplete="new-password" placeholder="leave blank to keep"></label>
    <label class="field field--short">New password again <input name="confirm_password" type="password" autocomplete="new-password"></label>
    <div class="btn-row"><button class="btn btn--primary" type="submit">Update backup login</button></div>
  </form>
</section>

<section class="panel" id="site">
  <h2>Site</h2>
  <form method="post" class="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="site">
    <label class="field">Contact email <span class="fine">(shown on the pay pages)</span>
      <input name="contact_email" type="email" value="<?= h(setting('contact_email')) ?>" placeholder="hello@cvilleglass.org">
    </label>
    <label class="field">Site address <span class="fine">(leave blank unless links come out wrong)</span>
      <input name="site_url" value="<?= h(setting('site_url')) ?>" placeholder="<?= h(site_url()) ?>">
    </label>
    <div class="btn-row"><button class="btn btn--primary" type="submit">Save</button></div>
  </form>
</section>
<?php
admin_footer();
