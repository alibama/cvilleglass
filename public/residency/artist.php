<?php
// One resident's public page. The resident edits it at /residency/edit.php.
require __DIR__ . '/../../app/bootstrap.php';

$r = resident_by_slug((string)($_GET['r'] ?? ''));
$editor = $r ? editing_resident() : null;
$can_edit = $r && (current_admin() || ($editor && (int)$editor['id'] === (int)$r['id']));
if (!$r || (!$r['visible'] && !$can_edit)) {
    http_response_code(404);
    public_header('Not found');
    echo '<section class="band"><div class="wrap"><div class="notice"><h3>We couldn’t find that residency page.</h3><p><a class="tlink" href="/residency/">See all residents &rarr;</a></p></div></div></section>';
    public_footer();
    exit;
}
$photos = resident_photos((int)$r['id']);
$lineage = resident_lineage((int)$r['id']);
$first = strtok($r['name'], ' ');

public_header($r['name'] . ' — Artist residency');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <?php if (!$r['visible']): ?><p><span class="pill pill--draft">Draft — only you can see this</span></p><?php endif ?>
    <p class="eyebrow">Artist residency<?= $r['year'] !== '' ? ' · ' . h($r['year']) : '' ?></p>
    <h1><?= h($r['name']) ?><?= $r['project_title'] !== '' ? ': <em>' . h($r['project_title']) . '</em>' : '' ?>.</h1>
    <?php if ($r['summary'] !== ''): ?><p class="lead"><?= h($r['summary']) ?></p><?php endif ?>
    <?php if ($can_edit): ?><div class="btn-row"><a class="btn btn--amber" href="<?= current_admin() && !$editor ? '/admin/residents.php?open=' . $r['id'] : '/residency/edit.php' ?>">Edit this page</a></div><?php endif ?>
  </div>
</section>

<section class="band">
  <div class="wrap split flip">
    <?php if ($r['headshot']): ?>
      <figure class="photo-figure photo-figure--portrait reveal">
        <img src="<?= h(upload_url($r['headshot'])) ?>" alt="<?= h($r['headshot_alt']) ?>" loading="lazy" decoding="async">
        <figcaption><?= h($r['name']) ?>, resident artist<?= $r['year'] !== '' ? ', ' . h($r['year']) : '' ?>.</figcaption>
      </figure>
    <?php endif ?>
    <div class="reveal">
      <p class="eyebrow">The artist</p>
      <h2 class="section-head"><?= h($r['name']) ?>.</h2>
      <?php if ($r['pronouns'] !== ''): ?><p class="fine"><?= h($r['pronouns']) ?></p><?php endif ?>
      <div class="prose"><?= $r['bio_html'] /* cleaned by clean_html() when saved */ ?></div>
      <ul class="res-links">
        <?php if ($r['cv_file']): ?><li><a class="btn btn--primary btn--sm" href="<?= h(upload_url($r['cv_file'])) ?>" target="_blank" rel="noopener">Read <?= h($first) ?>’s CV (PDF)</a></li><?php endif ?>
        <?php if ($r['website']): ?><li><a class="tlink" href="<?= h($r['website']) ?>" target="_blank" rel="noopener"><?= h(preg_replace('~^https?://(www\.)?|/$~', '', $r['website'])) ?> &rarr;</a></li><?php endif ?>
        <?php if ($r['instagram']): ?><li><a class="tlink" href="https://www.instagram.com/<?= h($r['instagram']) ?>/" target="_blank" rel="noopener">@<?= h($r['instagram']) ?> on Instagram &rarr;</a></li><?php endif ?>
        <?php if ($r['gdb_gid']): ?><li><a class="tlink" href="<?= h(gdb_maker_url($r['gdb_gid'])) ?>" target="_blank" rel="noopener">On the Glass Database &rarr;</a></li><?php endif ?>
      </ul>
    </div>
  </div>
</section>

<?php if ($r['project_html'] !== ''): ?>
<section class="band band--petrol">
  <div class="wrap">
    <div class="reveal measure">
      <p class="eyebrow">The residency</p>
      <h2><?= $r['project_title'] !== '' ? '<em>' . h($r['project_title']) . '</em>' : 'At the bench' ?>.</h2>
      <div class="prose"><?= $r['project_html'] ?></div>
    </div>
  </div>
</section>
<?php endif ?>

<?php if ($photos): ?>
<section class="band band--paper2" id="gallery">
  <div class="wrap">
    <div class="section-head reveal">
      <p class="eyebrow">From the residency</p>
      <h2><?= $r['project_title'] !== '' ? 'The <em>' . h($r['project_title']) . '</em> series' : 'The work' ?>.</h2>
    </div>
    <div class="gallery">
      <?php foreach ($photos as $i => $p): ?>
        <figure class="photo-figure<?= $i === 0 ? ' photo-figure--span' : '' ?> reveal">
          <img src="<?= h(upload_url($p['file'])) ?>" width="<?= (int)$p['width'] ?>" height="<?= (int)$p['height'] ?>" alt="<?= h($p['alt']) ?>" loading="lazy" decoding="async">
          <?php if ($p['caption'] !== ''): ?><figcaption><?= h($p['caption']) ?></figcaption><?php endif ?>
        </figure>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php if ($lineage): ?>
<section class="band">
  <div class="wrap split">
    <div class="reveal">
      <p class="eyebrow">Glass lineage</p>
      <h2 class="section-head">Hand to hand.</h2>
      <p>Glassblowing is learned at the bench, from the people beside you. These are the people <?= h($first) ?> has learned from, worked with and been helped by — recorded for the <a class="tlink" href="<?= CVG_GDB ?>" target="_blank" rel="noopener">Glass Database</a> so the lineage isn’t lost.</p>
    </div>
    <ul class="lineage-list lineage-list--public reveal">
      <?php foreach ($lineage as $l): ?>
        <li>
          <span class="eyebrow"><?= h(relation_phrase($l['relation'])) ?></span>
          <b><?= $l['gdb_gid'] ? '<a href="' . h(gdb_maker_url($l['gdb_gid'])) . '" target="_blank" rel="noopener">' . h($l['person']) . ' ↗</a>' : h($l['person']) ?></b>
          <?php if ($l['place'] !== '' || $l['years'] !== ''): ?><span class="fine"><?= h(implode(' · ', array_filter([$l['place'], $l['years']]))) ?></span><?php endif ?>
          <?php if ($l['note'] !== ''): ?><span class="fine"><?= h($l['note']) ?></span><?php endif ?>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
</section>
<?php endif ?>

<section class="band band--paper2">
  <div class="wrap">
    <p><a class="tlink" href="/residency/">&larr; All residents and the residency program</a></p>
  </div>
</section>
<?php
public_footer();
