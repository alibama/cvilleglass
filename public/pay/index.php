<?php
// Public page: shop rental (pick months/weeks) and classes (pick how many people), paid with Stripe.
// Share /pay/ for everything, or /pay/?class=ID for a single item.
require __DIR__ . '/../../app/bootstrap.php';

$admin = current_admin();
$items = products((bool)$admin);
if (isset($_GET['class'])) {
    $items = array_values(array_filter($items, fn($p) => $p['id'] == $_GET['class']));
}
$rentals = array_values(array_filter($items, 'is_rental'));
$classes = array_values(array_filter($items, fn($p) => !is_rental($p)));
$classes_only = $classes && !$rentals;

function pay_card(array $p): void
{
    ?>
      <article class="pay-card" id="class-<?= $p['id'] ?>">
        <div class="pay-card-info">
          <?php if (!$p['visible']): ?><span class="pill pill--draft">Draft</span><?php endif ?>
          <h2><?= h($p['name']) ?></h2>
          <p class="price"><?= money((int)$p['price_cents']) ?> <span><?= CVG_KINDS[$p['kind']]['per'] ?></span></p>
          <?php if (!is_rental($p) && $p['class_date']): ?>
            <p class="when"><?= h(date('l, F j, Y', strtotime($p['class_date']))) ?></p>
          <?php endif ?>
          <?php if ($p['description'] !== ''): ?><p><?= nl2br(h($p['description'])) ?></p><?php endif ?>
        </div>

        <form class="pay-form" method="post" action="/pay/checkout.php" data-price="<?= (int)$p['price_cents'] ?>" data-kind="<?= h($p['kind']) ?>">
          <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
          <label class="field"><?= is_rental($p) ? 'Your name' : 'Student’s name' ?>
            <input name="student_name" required autocomplete="name" maxlength="120">
          </label>
          <label class="field">Email for the receipt
            <input name="email" type="email" required autocomplete="email" maxlength="200">
          </label>

          <?php if (!is_rental($p)): ?>
            <label class="field field--short">How many people?
              <input name="quantity" type="number" min="1" max="10" value="1" required>
            </label>
          <?php else: ?>
            <fieldset class="periods">
              <legend><?= $p['kind'] === 'monthly' ? 'Which months?' : 'Which weeks?' ?></legend>
              <?php $first = true; foreach (period_options($p['kind']) as $value => $label): ?>
                <label class="check"><input type="checkbox" name="periods[]" value="<?= h($value) ?>"<?= $first ? ' checked' : '' ?>> <?= h($label) ?></label>
              <?php $first = false; endforeach ?>
            </fieldset>
          <?php endif ?>

          <button class="btn btn--amber" type="submit" data-pay-button>Pay <?= money((int)$p['price_cents']) ?></button>
          <p class="fine">You’ll finish on Stripe’s secure checkout page.</p>
        </form>
      </article>
    <?php
}

public_header($classes_only ? 'Classes' : 'Shop rental');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <?php if ($classes_only): ?>
      <p class="eyebrow">Classes</p>
      <h1>Sign up for a class.</h1>
      <p class="lead">Save your spot by paying here. Payment is handled securely by Stripe, and you’ll get a receipt by email.</p>
    <?php else: ?>
      <p class="eyebrow">Hot shop</p>
      <h1>Shop rental.</h1>
      <p class="lead">Rent time in the hot shop by the month or by the week. Pick the months or weeks you’re paying for — paying ahead is always welcome. Payment is handled securely by Stripe, and you’ll get a receipt by email.</p>
    <?php endif ?>
  </div>
</section>

<section class="band band--tight">
  <div class="wrap pay-list">
    <?php flash_messages() ?>
    <?php if (isset($_GET['cancelled'])): ?>
      <p class="flash flash--info">No payment was made. You can try again whenever you’re ready.</p>
    <?php endif ?>
    <?php if ($admin): ?>
      <p class="flash flash--info">You’re signed in as an admin, so drafts are shown here too. Visitors only see published items. <a href="/admin/classes.php">Edit rentals &amp; classes</a></p>
    <?php endif ?>

    <?php if (!$items): ?>
      <div class="notice">
        <h3>Nothing open for payment right now.</h3>
        <p>Questions? Email <a class="tlink" href="mailto:<?= h(contact_email()) ?>"><?= h(contact_email()) ?></a>.</p>
      </div>
    <?php endif ?>

    <?php foreach ($rentals as $p) pay_card($p) ?>

    <?php if ($classes && $rentals): ?>
      <div class="section-head pay-section">
        <p class="eyebrow">Classes</p>
        <h2>Classes.</h2>
        <p>Separate from shop rental: guided sessions you sign up and pay for one at a time.</p>
      </div>
    <?php endif ?>
    <?php foreach ($classes as $p) pay_card($p) ?>
  </div>
</section>
<?php
public_footer();
