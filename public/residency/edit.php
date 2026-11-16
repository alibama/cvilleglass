<?php
// A resident edits their own residency page here. They arrive with the private link
// from Admin → Residents (/residency/edit.php?t=…); after that a session keeps them in.
require __DIR__ . '/../../app/bootstrap.php';

if (isset($_GET['t'])) {
    $r = resident_from_token((string)$_GET['t']);
    if (!$r) {
        public_header('Edit link expired');
        echo '<section class="band"><div class="wrap"><div class="notice"><h3>That edit link doesn’t work any more.</h3>'
            . '<p>It may have been replaced with a new one. <a class="tlink" href="/contact.php?subject=Residency%20page%20edit%20link">Ask us for a fresh link</a>.</p></div></div></section>';
        public_footer();
        exit;
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['resident_id'] = (int)$r['id'];
    $_SESSION['resident_token'] = $r['edit_token'];
    unset($_SESSION['resident_admin']);
    redirect('/residency/edit.php');   // drop the secret from the address bar and history
}

$r = editing_resident();
if (!$r) {
    public_header('Edit your residency page');
    echo '<section class="band"><div class="wrap"><div class="notice"><h3>Use the link we sent you.</h3>'
        . '<p>Residents edit their page through a private link. Lost it? <a class="tlink" href="/contact.php?subject=Residency%20page%20edit%20link">Ask us for a new one</a>.</p></div></div></section>';
    public_footer();
    exit;
}
$id = (int)$r['id'];
$pdo = db();

/** Let the studio know a page changed, at most once an hour per resident. */
function note_change(array $r, string $what): void
{
    $pdo = db();
    $pdo->prepare('UPDATE residents SET updated_at = ? WHERE id = ?')->execute([now(), $r['id']]);
    if (!empty($_SESSION['resident_admin'])) return;
    $key = 'resident_notified_' . $r['id'];
    if ((int)setting($key) > time() - 3600) return;
    save_setting($key, (string)time());
    try {
        send_mail(contact_to(), 'Residency page updated: ' . $r['name'],
            $r['name'] . " just updated their residency page ($what).\n\n"
            . 'See it: ' . site_url(resident_url($r)) . "\n"
            . 'Manage residents: ' . site_url('/admin/residents.php') . "\n");
    } catch (RuntimeException $e) {
        error_log('cvilleglass resident notice: ' . $e->getMessage());
    }
}

function clean_url(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    return filter_var($u, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $u) ? clip($u, 300) : '';
}

// ---------- saving ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A form bigger than post_max_size arrives empty, with no CSRF token to check.
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('That upload was too big for the server. Try fewer or smaller files at a time.', 'error');
        redirect('/residency/edit.php');
    }
    check_csrf();
    $action = $_POST['action'] ?? '';
    $back = '/residency/edit.php#' . preg_replace('/[^a-z]/', '', (string)($_POST['section'] ?? ''));
    $text = fn(string $k, int $max) => trim(clip((string)($_POST[$k] ?? ''), $max));

    try {
        switch ($action) {
            case 'basics':
                $name = header_safe($text('name', 120));
                if ($name === '') throw new RuntimeException('Your name can’t be blank.');
                $insta = trim(preg_replace('~^(https?://)?(www\.)?instagram\.com/|^@|/.*$~i', '', $text('instagram', 200)));
                $pdo->prepare('UPDATE residents SET name = ?, pronouns = ?, project_title = ?, summary = ?, website = ?, instagram = ? WHERE id = ?')
                    ->execute([$name, header_safe($text('pronouns', 40)), header_safe($text('project_title', 150)),
                               preg_replace('/\s+/', ' ', $text('summary', 400)), clean_url($text('website', 300)),
                               preg_replace('/[^A-Za-z0-9._]/', '', $insta), $id]);
                note_change($r, 'the basics');
                flash('Saved.');
                break;

            case 'bio':
            case 'project':
                $pdo->prepare("UPDATE residents SET {$action}_html = ? WHERE id = ?")->execute([clean_html((string)($_POST[$action . '_html'] ?? '')), $id]);
                note_change($r, $action === 'bio' ? 'the bio' : 'the residency write-up');
                flash('Saved.');
                break;

            case 'headshot':
                [$file] = store_image($_FILES['headshot'] ?? null);
                delete_upload($r['headshot']);
                $pdo->prepare('UPDATE residents SET headshot = ?, headshot_alt = ? WHERE id = ?')
                    ->execute([$file, $text('headshot_alt', 300) ?: $r['name'], $id]);
                note_change($r, 'a new headshot');
                flash('Headshot updated.');
                break;

            case 'headshot_remove':
                delete_upload($r['headshot']);
                $pdo->prepare("UPDATE residents SET headshot = '' WHERE id = ?")->execute([$id]);
                flash('Headshot removed.');
                break;

            case 'photos_add':
                $files = $_FILES['photos'] ?? null;
                $count = $files ? count(array_filter((array)$files['error'], fn($e) => $e !== UPLOAD_ERR_NO_FILE)) : 0;
                if (!$count) throw new RuntimeException('Pick one or more photos first.');
                $have = count(resident_photos($id));
                $sort = $have ? (int)$pdo->query("SELECT MAX(sort) FROM resident_photos WHERE resident_id = $id")->fetchColumn() : 0;
                $added = 0;
                $problems = [];
                foreach ((array)$files['error'] as $i => $err) {
                    if ($err === UPLOAD_ERR_NO_FILE) continue;
                    if ($have + $added >= CVG_MAX_PHOTOS) { $problems[] = 'You can have up to ' . CVG_MAX_PHOTOS . ' photos.'; break; }
                    $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $err, 'size' => $files['size'][$i]];
                    try {
                        [$file, $w, $hgt] = store_image($one);
                        $pdo->prepare('INSERT INTO resident_photos (resident_id, file, width, height, sort) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$id, $file, $w, $hgt, ++$sort * 10]);
                        $added++;
                    } catch (RuntimeException $e) {
                        $problems[] = $files['name'][$i] . ': ' . $e->getMessage();
                    }
                }
                if ($added) {
                    note_change($r, "$added new photo" . ($added === 1 ? '' : 's'));
                    flash("Added $added photo" . ($added === 1 ? '' : 's') . '. Add a caption and a short description to each one below.');
                }
                foreach (array_unique($problems) as $p) flash($p, 'error');
                break;

            case 'photos_save':
                $upd = $pdo->prepare('UPDATE resident_photos SET caption = ?, alt = ?, sort = ? WHERE id = ? AND resident_id = ?');
                foreach ((array)($_POST['photo'] ?? []) as $pid => $p) {
                    $upd->execute([trim(clip((string)($p['caption'] ?? ''), 300)), trim(clip((string)($p['alt'] ?? ''), 300)),
                                   (int)($p['sort'] ?? 0), (int)$pid, $id]);
                }
                note_change($r, 'photo captions');
                flash('Photo details saved.');
                break;

            case 'photo_delete':
                $st = $pdo->prepare('SELECT file FROM resident_photos WHERE id = ? AND resident_id = ?');
                $st->execute([(int)($_POST['photo_id'] ?? 0), $id]);
                if ($file = $st->fetchColumn()) {
                    delete_upload($file);
                    $pdo->prepare('DELETE FROM resident_photos WHERE id = ? AND resident_id = ?')->execute([(int)$_POST['photo_id'], $id]);
                    flash('Photo removed.');
                }
                break;

            case 'cv':
                $file = store_pdf($_FILES['cv'] ?? null);
                delete_upload($r['cv_file']);
                $pdo->prepare('UPDATE residents SET cv_file = ?, cv_name = ? WHERE id = ?')
                    ->execute([$file, clip(basename((string)$_FILES['cv']['name']), 150), $id]);
                note_change($r, 'a new CV');
                flash('CV uploaded.');
                break;

            case 'cv_remove':
                delete_upload($r['cv_file']);
                $pdo->prepare("UPDATE residents SET cv_file = '', cv_name = '' WHERE id = ?")->execute([$id]);
                flash('CV removed.');
                break;

            case 'gdb':
                $gid = trim((string)($_POST['gdb_gid'] ?? ''));
                if ($gid !== '' && !valid_gid($gid)) throw new RuntimeException('Pick your profile from the search results.');
                $pdo->prepare('UPDATE residents SET gdb_gid = ?, gdb_name = ? WHERE id = ?')
                    ->execute([$gid, $gid === '' ? '' : header_safe($text('gdb_name', 200)), $id]);
                note_change($r, 'the glassdatabase.org link');
                flash($gid === '' ? 'Unlinked from glassdatabase.org.' : 'Linked to your glassdatabase.org profile.');
                break;

            case 'lineage_add':
                $person = header_safe($text('person', 150));
                $rel = (string)($_POST['relation'] ?? '');
                $gid = trim((string)($_POST['gdb_gid'] ?? ''));
                if ($person === '' || !isset(CVG_RELATIONS[$rel])) throw new RuntimeException('Add a name and pick how you’re connected.');
                if ($gid !== '' && !valid_gid($gid)) $gid = '';
                $pdo->prepare('INSERT INTO resident_lineage (resident_id, person, relation, place, years, note, gdb_gid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $person, $rel, header_safe($text('place', 150)), header_safe($text('years', 40)), $text('note', 1000), $gid, now()]);
                note_change($r, 'glass lineage');
                flash('Added ' . $person . ' to your lineage.');
                break;

            case 'lineage_delete':
                $pdo->prepare('DELETE FROM resident_lineage WHERE id = ? AND resident_id = ?')->execute([(int)($_POST['lineage_id'] ?? 0), $id]);
                flash('Removed.');
                break;

            case 'signout':
                unset($_SESSION['resident_id'], $_SESSION['resident_token'], $_SESSION['resident_admin']);
                redirect(resident_url($r));
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

$photos = resident_photos($id);
$lineage = resident_lineage($id);
$is_admin = !empty($_SESSION['resident_admin']);

function section_form(string $action, string $section, bool $files = false): void
{
    echo '<form method="post" action="/residency/edit.php" class="edit-form"' . ($files ? ' enctype="multipart/form-data"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="section" value="' . h($section) . '">';
}

public_header('Edit your residency page');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" integrity="sha384-ecIckRi4QlKYya/FQUbBUjS4qp65jF/J87Guw5uzTbO1C1Jfa/6kYmd6dXUF6D7i" crossorigin="anonymous">
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <p class="eyebrow">Your residency page<?= $is_admin ? ' · editing as admin' : '' ?></p>
    <h1>Hi, <?= h(strtok($r['name'], ' ')) ?>.</h1>
    <p class="lead">Everything on <a class="tlink" href="<?= h(resident_url($r)) ?>">your page</a> is yours to change. Each section saves on its own<?= $r['visible'] ? ', and changes show up on the site straight away' : '. Your page isn’t public yet — we’ll publish it when you’re ready' ?>.</p>
    <div class="btn-row">
      <a class="btn btn--primary" href="<?= h(resident_url($r)) ?>" target="_blank" rel="noopener">See your page ↗</a>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="signout"><button class="btn btn--ghost" type="submit">Done — sign out</button></form>
    </div>
  </div>
</section>

<section class="band band--tight">
  <div class="wrap res-edit">
    <nav class="res-toc" aria-label="Sections">
      <a href="#basics">Basics</a><a href="#bio">Bio</a><a href="#project">The residency</a><a href="#photos">Photos</a><a href="#cv">CV</a><a href="#gdb">Glass Database</a><a href="#lineage">Lineage</a>
    </nav>
    <?php flash_messages() ?>

    <section class="panel" id="basics">
      <h2>Basics</h2>
      <?php section_form('basics', 'basics') ?>
        <div class="res-row">
          <label class="field">Your name <input name="name" required maxlength="120" value="<?= h($r['name']) ?>"></label>
          <label class="field field--short">Pronouns <span class="fine">(optional)</span> <input name="pronouns" maxlength="40" value="<?= h($r['pronouns']) ?>" placeholder="she/her"></label>
        </div>
        <label class="field">Title of your residency project <input name="project_title" maxlength="150" value="<?= h($r['project_title']) ?>" placeholder="e.g. Glass Houses"></label>
        <label class="field">Short summary <span class="fine">(one or two sentences, shown on the residency page)</span>
          <textarea name="summary" rows="3" maxlength="400"><?= h($r['summary']) ?></textarea>
        </label>
        <div class="res-row">
          <label class="field">Website <input name="website" maxlength="300" value="<?= h($r['website']) ?>" placeholder="yourname.com"></label>
          <label class="field">Instagram <input name="instagram" maxlength="200" value="<?= h($r['instagram'] !== '' ? '@' . $r['instagram'] : '') ?>" placeholder="@yourhandle"></label>
        </div>
        <div class="btn-row"><button class="btn btn--primary" type="submit">Save basics</button></div>
      </form>
    </section>

    <?php foreach (['bio' => ['Bio', 'Who you are and what your work is about. Paste from Word or Google Docs if you like — the formatting is tidied up for you.'],
                    'project' => ['The residency', 'What you made here, how it went, who you worked with. Headings, lists and links all work.']] as $key => [$title, $help]): ?>
      <section class="panel" id="<?= $key ?>">
        <h2><?= $title ?></h2>
        <p class="fine"><?= $help ?></p>
        <?php section_form($key, $key) ?>
          <div class="rte" data-rte="<?= $key ?>_html" aria-label="<?= h($title) ?>"><?= $r[$key . '_html'] ?></div>
          <textarea class="rte-fallback" name="<?= $key ?>_html" rows="12"><?= h($r[$key . '_html']) ?></textarea>
          <div class="btn-row"><button class="btn btn--primary" type="submit">Save <?= strtolower($title) ?></button></div>
        </form>
      </section>
    <?php endforeach ?>

    <section class="panel" id="photos">
      <h2>Photos</h2>
      <div class="res-headshot">
        <div class="res-thumb res-thumb--portrait">
          <?php if ($r['headshot']): ?><img src="<?= h(upload_url($r['headshot'])) ?>" alt="<?= h($r['headshot_alt']) ?>"><?php else: ?><span>No headshot yet</span><?php endif ?>
        </div>
        <div class="stack">
          <h3>Headshot</h3>
          <?php section_form('headshot', 'photos', true) ?>
            <label class="field">Choose a photo <span class="fine">(JPEG, PNG or WebP; big phone photos are shrunk for you)</span>
              <input type="file" name="headshot" accept="image/jpeg,image/png,image/webp" required data-resize>
            </label>
            <label class="field">Describe the photo for people who can’t see it <input name="headshot_alt" maxlength="300" value="<?= h($r['headshot_alt']) ?>" placeholder="e.g. Hanna in a winter coat, smiling, outdoors"></label>
            <div class="btn-row"><button class="btn btn--primary btn--sm" type="submit"><?= $r['headshot'] ? 'Replace headshot' : 'Upload headshot' ?></button></div>
          </form>
          <?php if ($r['headshot']): ?>
            <?php section_form('headshot_remove', 'photos') ?><button class="linkish fine" type="submit" onclick="return confirm('Remove your headshot?')">Remove headshot</button></form>
          <?php endif ?>
        </div>
      </div>

      <h3>Your work and the residency</h3>
      <?php section_form('photos_add', 'photos', true) ?>
        <label class="field">Add photos <span class="fine">(pick several at once; up to <?= CVG_MAX_PHOTOS ?> in all)</span>
          <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required data-resize>
        </label>
        <div class="btn-row"><button class="btn btn--primary btn--sm" type="submit">Upload photos</button></div>
      </form>

      <?php if ($photos): ?>
        <form method="post" action="/residency/edit.php" class="res-photos" id="photo-details">
          <?= csrf_field() ?><input type="hidden" name="action" value="photos_save"><input type="hidden" name="section" value="photos">
          <?php foreach ($photos as $i => $p): ?>
            <div class="res-photo">
              <div class="res-thumb"><img src="<?= h(upload_url($p['file'])) ?>" alt="" loading="lazy"></div>
              <label class="field">Caption <input name="photo[<?= $p['id'] ?>][caption]" maxlength="300" value="<?= h($p['caption']) ?>" placeholder="“Glass Houses,” 2026."></label>
              <label class="field">Description for screen readers <input name="photo[<?= $p['id'] ?>][alt]" maxlength="300" value="<?= h($p['alt']) ?>" placeholder="What’s in the picture"></label>
              <div class="res-photo-foot">
                <label class="field field--tiny">Order <input name="photo[<?= $p['id'] ?>][sort]" type="number" value="<?= (int)$p['sort'] ?>"></label>
                <button class="linkish fine" type="submit" form="del-photo-<?= $p['id'] ?>" onclick="return confirm('Remove this photo?')">Remove</button>
              </div>
            </div>
          <?php endforeach ?>
          <div class="btn-row res-photos-save"><button class="btn btn--primary" type="submit">Save captions and order</button><span class="fine">Lower numbers come first. The first photo is shown wide.</span></div>
        </form>
        <?php foreach ($photos as $p): ?>
          <form method="post" action="/residency/edit.php" id="del-photo-<?= $p['id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="section" value="photos"><input type="hidden" name="photo_id" value="<?= $p['id'] ?>"></form>
        <?php endforeach ?>
      <?php endif ?>
    </section>

    <section class="panel" id="cv">
      <h2>CV</h2>
      <?php if ($r['cv_file']): ?>
        <p>Current CV: <a class="tlink" href="<?= h(upload_url($r['cv_file'])) ?>" target="_blank" rel="noopener"><?= h($r['cv_name'] ?: 'CV.pdf') ?></a></p>
      <?php endif ?>
      <?php section_form('cv', 'cv', true) ?>
        <label class="field"><?= $r['cv_file'] ? 'Replace it with a new PDF' : 'Upload your CV as a PDF' ?> <span class="fine">(up to <?= CVG_MAX_CV / 1048576 ?> MB)</span>
          <input type="file" name="cv" accept="application/pdf,.pdf" required>
        </label>
        <div class="btn-row"><button class="btn btn--primary btn--sm" type="submit">Upload CV</button></div>
      </form>
      <?php if ($r['cv_file']): ?>
        <?php section_form('cv_remove', 'cv') ?><button class="linkish fine" type="submit" onclick="return confirm('Remove your CV from the page?')">Remove CV</button></form>
      <?php endif ?>
    </section>

    <section class="panel" id="gdb">
      <h2>Glass Database</h2>
      <p class="fine"><a href="<?= CVG_GDB ?>" target="_blank" rel="noopener">glassdatabase.org</a> is an open registry of glass artists, studios and pieces. Link your profile there, and your page here will point to it.</p>
      <?php if ($r['gdb_gid']): ?>
        <p>Linked to <a class="tlink" href="<?= h(gdb_maker_url($r['gdb_gid'])) ?>" target="_blank" rel="noopener"><?= h($r['gdb_name'] ?: $r['gdb_gid']) ?> on glassdatabase.org ↗</a></p>
        <?php section_form('gdb', 'gdb') ?><input type="hidden" name="gdb_gid" value=""><button class="linkish fine" type="submit">Unlink</button></form>
      <?php else: ?>
        <?php section_form('gdb', 'gdb') ?>
          <div class="gdb-pick" data-gdb>
            <label class="field">Find yourself <input type="search" data-gdb-q placeholder="Type your name" autocomplete="off" value="<?= h($r['name']) ?>"></label>
            <ul class="gdb-results" data-gdb-results aria-live="polite"></ul>
            <input type="hidden" name="gdb_gid" data-gdb-gid><input type="hidden" name="gdb_name" data-gdb-name>
          </div>
          <div class="btn-row"><button class="btn btn--primary btn--sm" type="submit" data-gdb-save disabled>Link this profile</button></div>
          <p class="fine">Not listed? <a href="<?= CVG_GDB ?>" target="_blank" rel="noopener">Add yourself on glassdatabase.org</a>, then come back and link it.</p>
        </form>
      <?php endif ?>
    </section>

    <section class="panel" id="lineage">
      <h2>Glass lineage</h2>
      <p class="fine">Glass is passed hand to hand. Record who taught you, who you’ve assisted, and who has assisted you — at the bench, in a class, or over years. These connections show on your page and are published for glassdatabase.org’s lineage map, so the history of who learned from whom isn’t lost.</p>
      <?php if ($lineage): ?>
        <ul class="lineage-list">
          <?php foreach ($lineage as $l): ?>
            <li>
              <span><b><?= h(relation_phrase($l['relation'])) ?></b> <?= $l['gdb_gid'] ? '<a href="' . h(gdb_maker_url($l['gdb_gid'])) . '" target="_blank" rel="noopener">' . h($l['person']) . ' ↗</a>' : h($l['person']) ?><?= $l['place'] !== '' || $l['years'] !== '' ? ' <span class="fine">· ' . h(implode(', ', array_filter([$l['place'], $l['years']]))) . '</span>' : '' ?></span>
              <?php section_form('lineage_delete', 'lineage') ?><input type="hidden" name="lineage_id" value="<?= $l['id'] ?>"><button class="linkish fine" type="submit" onclick="return confirm('Remove this connection?')">Remove</button></form>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <h3>Add a connection</h3>
      <?php section_form('lineage_add', 'lineage') ?>
        <div class="res-row">
          <div class="gdb-pick" data-gdb data-gdb-optional>
            <label class="field">Name <input name="person" data-gdb-q required maxlength="150" autocomplete="off" placeholder="Start typing — we’ll look them up"></label>
            <ul class="gdb-results" data-gdb-results aria-live="polite"></ul>
            <input type="hidden" name="gdb_gid" data-gdb-gid>
          </div>
          <label class="field">How you’re connected
            <select name="relation" required>
              <?php foreach (CVG_RELATIONS as $k => [$label]): ?><option value="<?= $k ?>"><?= h($label) ?></option><?php endforeach ?>
            </select>
          </label>
        </div>
        <div class="res-row">
          <label class="field">Where <span class="fine">(studio, school, program)</span> <input name="place" maxlength="150"></label>
          <label class="field field--short">When <input name="years" maxlength="40" placeholder="e.g. 2014–2016"></label>
        </div>
        <label class="field">Anything to add <span class="fine">(optional)</span> <textarea name="note" rows="2" maxlength="1000"></textarea></label>
        <div class="btn-row"><button class="btn btn--primary btn--sm" type="submit">Add to lineage</button></div>
      </form>
    </section>
  </div>
</section>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" integrity="sha384-utBUCeG4SYaCm4m7GQZYr8Hy8Fpy3V4KGjBZaf4WTKOcwhCYpt/0PfeEe3HNlwx8" crossorigin="anonymous"></script>
<script src="<?= asset('resident-edit.js') ?>"></script>
<?php
public_footer();
