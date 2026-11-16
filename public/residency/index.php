<?php
// The residency program page, with every published resident. Residents edit their own pages.
require __DIR__ . '/../../app/bootstrap.php';

$list = residents((bool)current_admin());

public_header('Artist residency');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <p class="eyebrow">Artist residency</p>
    <h1>Artists at the fire.</h1>
    <p class="lead">Charlottesville Glass awards residencies to artists — in any material — with an idea that needs hot glass. Days in the hot shop, mentors at the bench, and a new body of work.</p>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <div class="section-head reveal">
      <p class="eyebrow">Residents</p>
      <h2>Our residents.</h2>
    </div>
    <?php if (!$list): ?>
      <p class="empty">Our first residents will be announced soon.</p>
    <?php endif ?>
    <div class="res-cards">
      <?php foreach ($list as $r): ?>
        <a class="res-card reveal" href="<?= h(resident_url($r)) ?>">
          <div class="res-card-img"><?php if ($r['headshot']): ?><img src="<?= h(upload_url($r['headshot'])) ?>" alt="" loading="lazy" decoding="async"><?php endif ?></div>
          <div class="res-card-body">
            <p class="eyebrow"><?= h($r['year']) ?><?= !$r['visible'] ? ' · <span class="pill pill--draft">Draft</span>' : '' ?></p>
            <h3><?= h($r['name']) ?></h3>
            <?php if ($r['project_title'] !== ''): ?><p class="res-card-project"><em><?= h($r['project_title']) ?></em></p><?php endif ?>
            <?php if ($r['summary'] !== ''): ?><p><?= h($r['summary']) ?></p><?php endif ?>
            <span class="tlink">Read more &rarr;</span>
          </div>
        </a>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="band band--paper2">
  <div class="wrap split">
    <div class="reveal">
      <p class="eyebrow">Why residencies</p>
      <h2 class="section-head">Working artists make the studio better for everyone.</h2>
      <p>Our classes and sessions are built for beginners, and they always will be. But a studio also needs people pushing the material past what it’s done before. A residency gives an artist from outside glass the time, the fire, and the hands to try something ambitious — and it gives everyone who walks through the door a look at what the material can become.</p>
      <p>Residents bring new techniques and new questions into the hot shop. The skills and the know-how stay here, in Charlottesville, passed hand to hand.</p>
    </div>
    <div class="reveal">
      <p class="eyebrow">What a residency includes</p>
      <div class="ways" style="margin-top:0.6rem;">
        <div class="way"><h4>Studio time</h4><p>Dedicated days in the hot shop, with the furnace, glory hole, and annealer run for you.</p></div>
        <div class="way"><h4>Mentorship</h4><p>Experienced glassblowers at the bench throughout — teaching, assisting, and keeping the piece alive.</p></div>
        <div class="way"><h4>A body of work</h4><p>You leave with finished pieces, and we help share the work with the community.</p></div>
      </div>
    </div>
  </div>
</section>

<section class="band">
  <div class="wrap split">
    <div class="reveal">
      <p class="eyebrow">Future residencies</p>
      <h2 class="section-head">Interested in the next one?</h2>
      <p>We’re a new organization, and more residencies will follow as funding and studio time allow. If you’re an artist — in any material — with an idea that needs hot glass, tell us about it. If you’d like to help fund the next resident’s time at the bench, we’d love that too.</p>
      <div class="btn-row">
        <a class="btn btn--primary" href="/contact.php?subject=Residency%20interest">Tell us about your idea</a>
        <a class="btn btn--ghost" href="/get-involved.html#support">Support a residency</a>
      </div>
    </div>
    <div class="notice reveal">
      <p class="eyebrow">Good to know</p>
      <h3>No glass experience needed.</h3>
      <p>Residents can come from any material. Our mentors handle the glass side, so a resident can bring their own practice and their own questions to the fire.</p>
    </div>
  </div>
</section>
<?php
public_footer();
