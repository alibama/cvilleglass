<?php
// The admin list: Google accounts allowed to sign in.
require __DIR__ . '/../../app/bootstrap.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (($_POST['action'] ?? '') === 'remove') {
        $pdo->prepare('DELETE FROM admins WHERE email = ?')->execute([$email]);
        flash($email . ' removed. They can no longer sign in with Google.');
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare('INSERT OR IGNORE INTO admins (email, name, added_at) VALUES (?, ?, ?)')
            ->execute([$email, trim((string)($_POST['name'] ?? '')), now()]);
        flash($email . ' can now sign in with Google.');
    } else {
        flash('That doesn’t look like an email address.', 'error');
    }
    redirect('/admin/admins.php');
}

$admins = $pdo->query('SELECT * FROM admins ORDER BY email')->fetchAll();

admin_header('Admins', 'admins');
?>
<div class="admin-head"><h1>Admins</h1></div>

<?php if (!google_ready()): ?>
  <p class="flash flash--info">Google sign-in isn’t switched on yet. Add the Google keys in <a href="/admin/settings.php#google">Settings</a> — until then, only the backup login works.</p>
<?php endif ?>

<section class="panel">
  <p>People on this list can sign in with their Google account (Gmail or Google Workspace). Remove someone and they lose access right away the next time they sign in.</p>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <label class="field">Google email <input name="email" type="email" required placeholder="name@gmail.com"></label>
    <label class="field">Name <span class="fine">(optional)</span> <input name="name"></label>
    <button class="btn btn--primary" type="submit">Add admin</button>
  </form>

  <div class="table-wrap"><table>
    <thead><tr><th>Email</th><th>Name</th><th>Added</th><th>Last signed in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
      <tr>
        <td><?= h($a['email']) ?><?= $a['email'] === $me ? ' <span class="pill">you</span>' : '' ?></td>
        <td><?= h($a['name']) ?></td>
        <td><?= h(nice_date($a['added_at'])) ?></td>
        <td><?= $a['last_login'] ? h(nice_date($a['last_login'])) : '<span class="fine">never</span>' ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this admin?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="email" value="<?= h($a['email']) ?>">
            <button class="linkish" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$admins): ?><tr><td colspan="5" class="empty">No one yet — add your own Google email first.</td></tr><?php endif ?>
    </tbody>
  </table></div>
  <p class="fine">The backup login (username + password, set in <a href="/admin/settings.php#password">Settings</a>) always works, even if Google sign-in breaks.</p>
</section>
<?php
admin_footer();
