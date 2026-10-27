<?php
// How other sites and tools plug in: a small read-only API, plus a spot
// for the glassdatabase.org keys once ornament time slots are built.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'new_token') {
        save_setting('api_token', 'cvg_' . bin2hex(random_bytes(20)));
        flash('New API token made. Anything using the old one will stop working.');
    } elseif ($action === 'revoke_token') {
        save_setting('api_token', '');
        flash('API token turned off.');
    } elseif ($action === 'glassdatabase') {
        save_setting('glassdatabase_url', trim((string)($_POST['glassdatabase_url'] ?? '')));
        if (trim((string)($_POST['glassdatabase_key'] ?? '')) !== '') save_setting('glassdatabase_key', trim((string)$_POST['glassdatabase_key']));
        flash('Saved.');
    }
    redirect('/admin/integrations.php');
}

$token = setting('api_token');
$base = site_url();

admin_header('Integrations', 'integrations');
?>
<div class="admin-head"><h1>Integrations</h1></div>
<p class="fine">For developers and partner sites. Everything here is read-only: other systems can look up classes and payments, but can’t change anything.</p>

<section class="panel">
  <h2>Class list <span class="pill pill--paid">public</span></h2>
  <p>Published classes and prices as JSON — no key needed. Partner sites can use this to show current classes.</p>
  <pre><code>GET <?= h($base) ?>/api/products.php</code></pre>
  <p class="fine">Each class has <code>id</code>, <code>name</code>, <code>description</code>, <code>kind</code> (monthly / weekly / once), <code>price_cents</code>, <code>class_date</code> and <code>pay_url</code> (a link straight to its payment form).</p>
</section>

<section class="panel">
  <h2>Payments <span class="pill pill--pending">needs token</span></h2>
  <p>Who has paid, one row per month or week covered. Send the token in a header:</p>
  <pre><code>curl -H "Authorization: Bearer <?= $token !== '' ? h($token) : 'YOUR_TOKEN' ?>" \
     "<?= h($base) ?>/api/payments.php?month=<?= date('Y-m') ?>"</code></pre>
  <p class="fine"><code>month</code> is optional (YYYY-MM). Leave it off to get everything.</p>
  <?php if ($token !== ''): ?>
    <label class="field">API token <input class="copy" readonly value="<?= h($token) ?>" onclick="this.select()"></label>
  <?php else: ?>
    <p class="flash flash--info">The payments API is off until you make a token.</p>
  <?php endif ?>
  <div class="btn-row">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new_token"><button class="btn btn--primary btn--sm" type="submit"><?= $token !== '' ? 'Make a new token' : 'Make a token' ?></button></form>
    <?php if ($token !== ''): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_token"><button class="btn btn--ghost btn--sm" type="submit">Turn off</button></form>
    <?php endif ?>
  </div>
</section>

<section class="panel">
  <h2>glassdatabase.org <span class="pill pill--draft">coming later</span></h2>
  <p>For ornament time-slot registrations: people book a slot here, and the finished ornament gets registered on glassdatabase.org. Nothing uses these yet — save the details here when that service is ready so they’re all in one place.</p>
  <form method="post" class="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="glassdatabase">
    <label class="field">glassdatabase.org API address <input name="glassdatabase_url" value="<?= h(setting('glassdatabase_url')) ?>" placeholder="https://glassdatabase.org/api/…"></label>
    <label class="field">glassdatabase.org API key
      <input name="glassdatabase_key" type="password" autocomplete="off" placeholder="<?= setting('glassdatabase_key') !== '' ? 'Saved — ends in …' . h(substr(setting('glassdatabase_key'), -4)) : '' ?>">
    </label>
    <div class="btn-row"><button class="btn btn--primary" type="submit">Save</button></div>
  </form>
</section>
<?php
admin_footer();
