<?php
// Messages sent through the public contact form, newest first.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('Message deleted.');
    }
    redirect('/admin/messages.php');
}

$messages = $pdo->query('SELECT * FROM messages ORDER BY id DESC LIMIT 200')->fetchAll();
$unsent = count(array_filter($messages, fn($m) => !$m['emailed']));

admin_header('Messages', 'messages');
?>
<div class="admin-head"><h1>Messages</h1></div>
<p class="fine">Everything sent through the <a href="/contact.php">contact form</a>. Each one is also emailed to <b><?= h(contact_to()) ?></b> (change that in <a href="/admin/settings.php#contact">Settings</a>).</p>
<?php if ($unsent): ?>
  <p class="flash flash--warn"><?= $unsent ?> message<?= $unsent === 1 ? '' : 's' ?> below couldn’t be emailed, so check here for now. To fix it, add a sending login under <a href="/admin/settings.php#contact">Settings → Contact form</a>.</p>
<?php endif ?>

<section class="panel">
  <?php if (!$messages): ?>
    <p class="empty">No messages yet.</p>
  <?php endif ?>
  <?php foreach ($messages as $m): ?>
    <article class="message">
      <div class="panel-head">
        <h2><?= h($m['subject'] !== '' ? $m['subject'] : 'Message from ' . $m['name']) ?></h2>
        <p class="fine"><?= h(date('M j, Y g:ia', strtotime($m['created_at']))) ?>
          <?= $m['emailed'] ? '<span class="pill pill--paid">emailed</span>' : '<span class="pill pill--pending" title="' . h($m['email_error']) . '">not emailed</span>' ?></p>
      </div>
      <p><b><?= h($m['name']) ?></b> · <a href="mailto:<?= h($m['email']) ?>?subject=<?= rawurlencode('Re: ' . ($m['subject'] !== '' ? $m['subject'] : 'your message to Charlottesville Glass')) ?>"><?= h($m['email']) ?></a></p>
      <p class="message-body"><?= nl2br(h($m['message'])) ?></p>
      <form method="post" onsubmit="return confirm('Delete this message?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $m['id'] ?>">
        <button class="linkish fine" type="submit">Delete</button>
      </form>
    </article>
  <?php endforeach ?>
</section>
<?php
admin_footer();
