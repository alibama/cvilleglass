<?php
// Residency award pages: add a resident, publish their page, and give them a private
// edit link so they can keep the page up to date themselves.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
$pdo = db();

// Open a resident's editor as an admin.
if (isset($_GET['open']) && ($r = resident((int)$_GET['open']))) {
    start_session();
    $_SESSION['resident_id'] = (int)$r['id'];
    $_SESSION['resident_admin'] = 1;
    unset($_SESSION['resident_token']);
    redirect('/residency/edit.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $r = resident((int)($_POST['id'] ?? 0));
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('That email address doesn’t look right.', 'error');
        redirect('/admin/residents.php');
    }

    if ($action === 'add') {
        $name = header_safe(clip((string)($_POST['name'] ?? ''), 120));
        if ($name === '') {
            flash('Give the resident a name.', 'error');
            redirect('/admin/residents.php');
        }
        $pdo->prepare('INSERT INTO residents (slug, name, year, email, sort, created_at, updated_at) VALUES (?, ?, ?, ?, 10, ?, ?)')
            ->execute([unique_slug($name), $name, header_safe(clip((string)($_POST['year'] ?? date('Y')), 20)), $email, now(), now()]);
        $id = (int)$pdo->lastInsertId();
        start_session();
        $_SESSION['new_edit_link'] = [$id, new_edit_link($id)];
        flash('Added ' . $name . '. Their page is a draft until you publish it. Send them the edit link below.');
        redirect('/admin/residents.php#r' . $id);
    }

    if ($r && $action === 'save') {
        $pdo->prepare('UPDATE residents SET year = ?, email = ?, sort = ?, visible = ? WHERE id = ?')
            ->execute([header_safe(clip((string)($_POST['year'] ?? ''), 20)), $email, (int)($_POST['sort'] ?? 0),
                       isset($_POST['visible']) ? 1 : 0, $r['id']]);
        flash('Saved ' . $r['name'] . '.');
        redirect('/admin/residents.php#r' . $r['id']);
    }

    if ($r && ($action === 'link' || $action === 'send_link')) {
        $link = new_edit_link((int)$r['id']);
        start_session();
        $_SESSION['new_edit_link'] = [(int)$r['id'], $link];
        if ($action === 'send_link') {
            if ($r['email'] === '') {
                flash('Add ' . $r['name'] . '’s email first, or copy the link below and send it yourself.', 'error');
            } else {
                try {
                    send_mail($r['email'], 'Your Charlottesville Glass residency page',
                        "Hi " . strtok($r['name'], ' ') . ",\n\n"
                        . "Here's your private link for editing your residency page on cvilleglass.org:\n\n$link\n\n"
                        . "You can change your bio and the write-up of your residency, add photos and your CV, link your "
                        . "Glass Database profile, and record your glass lineage — who you've learned from and worked with.\n\n"
                        . "Keep this link to yourself: anyone who has it can edit your page. If you lose it, just ask us for a new one.\n\n"
                        . "Your page: " . site_url(resident_url($r)) . "\n\n— Charlottesville Glass\n",
                        contact_to());
                    flash('Sent a new edit link to ' . $r['email'] . '. Any older link has stopped working.');
                } catch (RuntimeException $e) {
                    flash('The email couldn’t be sent (' . $e->getMessage() . '). Copy the link below and send it yourself.', 'error');
                }
            }
        } else {
            flash('Made a new edit link for ' . $r['name'] . '. Any older link has stopped working.');
        }
        redirect('/admin/residents.php#r' . $r['id']);
    }

    if ($r && $action === 'delete') {
        delete_upload($r['headshot']);
        delete_upload($r['cv_file']);
        foreach (resident_photos((int)$r['id']) as $p) delete_upload($p['file']);
        $pdo->prepare('DELETE FROM residents WHERE id = ?')->execute([$r['id']]);
        flash('Deleted ' . $r['name'] . '’s page, photos and CV.');
        redirect('/admin/residents.php');
    }
    redirect('/admin/residents.php');
}

start_session();
[$link_for, $link] = $_SESSION['new_edit_link'] ?? [0, ''];
unset($_SESSION['new_edit_link']);
$list = residents(true);

admin_header('Residents', 'residents');
?>
<div class="admin-head"><h1>Residents</h1><a class="btn btn--ghost btn--sm" href="/residency/">Residency page ↗</a></div>
<p class="fine">Each resident edits their own page through a private link: words, photos, CV, their glassdatabase.org profile and their glass lineage. Their changes show up straight away, and you’ll get an email when someone updates their page. Untick “Published” to take a page down.</p>

<?php foreach ($list as $r): $count = count(resident_photos((int)$r['id'])); ?>
  <section class="panel" id="r<?= $r['id'] ?>">
    <div class="panel-head">
      <h2><?= h($r['name']) ?> <?= $r['project_title'] !== '' ? '<span class="fine">· ' . h($r['project_title']) . '</span>' : '' ?></h2>
      <p class="fine"><?= $r['visible'] ? '<span class="pill pill--paid">published</span>' : '<span class="pill pill--draft">draft</span>' ?> Updated <?= h(nice_date($r['updated_at'])) ?></p>
    </div>
    <p class="fine"><?= $r['headshot'] ? 'Headshot' : 'No headshot' ?> · <?= $count ?> photo<?= $count === 1 ? '' : 's' ?> · <?= $r['cv_file'] ? 'CV' : 'no CV' ?> · <?= $r['gdb_gid'] ? 'on glassdatabase.org' : 'not linked to glassdatabase.org' ?> · <?= count(resident_lineage((int)$r['id'])) ?> lineage connection<?= count(resident_lineage((int)$r['id'])) === 1 ? '' : 's' ?></p>

    <?php if ($link_for === (int)$r['id']): ?>
      <div class="flash flash--info">
        <p><b>Private edit link for <?= h($r['name']) ?></b>. Send it to them. Anyone with it can edit the page, so don’t post it publicly.</p>
        <input class="copy" readonly value="<?= h($link) ?>" onclick="this.select()" aria-label="Edit link">
      </div>
    <?php endif ?>

    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <label class="field">Resident’s email <span class="fine">(for sending the link; never shown)</span> <input name="email" type="email" value="<?= h($r['email']) ?>"></label>
      <label class="field field--tiny">Year <input name="year" value="<?= h($r['year']) ?>" maxlength="20"></label>
      <label class="field field--tiny">Order <input name="sort" type="number" value="<?= (int)$r['sort'] ?>"></label>
      <label class="check check--big"><input type="checkbox" name="visible"<?= $r['visible'] ? ' checked' : '' ?>> Published</label>
      <button class="btn btn--primary btn--sm" type="submit">Save</button>
    </form>
    <div class="btn-row">
      <a class="btn btn--ghost btn--sm" href="<?= h(resident_url($r)) ?>" target="_blank" rel="noopener">View page ↗</a>
      <a class="btn btn--ghost btn--sm" href="/admin/residents.php?open=<?= $r['id'] ?>">Edit page yourself</a>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send_link"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"<?= $r['email'] === '' ? ' disabled title="Add their email first"' : '' ?>>Email them an edit link</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $r['edit_token'] !== '' ? 'Make a new link to copy' : 'Make a link to copy' ?></button></form>
    </div>
    <div class="danger-zone">
      <form method="post" onsubmit="return confirm('Delete <?= h(addslashes($r['name'])) ?>’s page, photos and CV for good?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button class="linkish fine" type="submit">Delete this resident</button>
      </form>
    </div>
  </section>
<?php endforeach ?>

<details class="panel"<?= $list ? '' : ' open' ?>>
  <summary><h2>Add a resident</h2></summary>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="field">Name <input name="name" required maxlength="120"></label>
    <label class="field">Email <span class="fine">(optional)</span> <input name="email" type="email"></label>
    <label class="field field--tiny">Year <input name="year" value="<?= date('Y') ?>" maxlength="20"></label>
    <button class="btn btn--primary btn--sm" type="submit">Add resident</button>
  </form>
  <p class="fine">You’ll get a private edit link to send them. They fill in the rest.</p>
</details>
<?php
admin_footer();
