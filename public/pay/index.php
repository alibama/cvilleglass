<?php
// Public page: pick a class, choose months/weeks (or how many people), pay with Stripe.
// Share /pay/ for everything, or /pay/?class=ID for a single class.
require __DIR__ . '/../../app/bootstrap.php';

$admin = current_admin();
$items = products((bool)$admin);
if (isset($_GET['class'])) {
    $items = array_values(array_filter($items, fn($p) => $p['id'] == $_GET['class']));
}

public_header('Pay for classes');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <p class="eyebrow">Classes</p>
    <h1>Pay for classes.</h1>
    <p class="lead">Choose your class and the months or weeks you’re paying for — paying ahead is always welcome. Payment is handled securely by Stripe, and you’ll get a receipt by email.</p>
  </div>
</section>

<section class="band band--tight">
  <div class="wrap pay-list">
    <?php flash_messages() ?>
    <?php if (isset($_GET['cancelled'])): ?>
      <p class="flash flash--info">No payment was made. You can try again whenever you’re ready.</p>
    <?php endif ?>
    <?php if ($admin): ?>
      <p class="flash flash--info">You’re signed in as an admin, so draft classes are shown here too. Visitors only see published ones. <a href="/admin/classes.php">Edit classes</a></p>
    <?php endif ?>

    <?php if (!$items): ?>
      <div class="notice">
        <h3>Nothing open for payment right now.</h3>
        <p>Questions about classes? Email <a class="tlink" href="mailto:<?= h(contact_email()) ?>"><?= h(contact_email()) ?></a>.</p>
      </div>
    <?php endif ?>

    <?php foreach ($items as $p): $options = period_options($p['kind']); ?>
      <article class="pay-card" id="class-<?= $p['id'] ?>">
        <div class="pay-card-info">
          <?php if (!$p['visible']): ?><span class="pill pill--draft">Draft</span><?php endif ?>
          <h2><?= h($p['name']) ?></h2>
          <p class="price"><?= money((int)$p['price_cents']) ?> <span><?= CVG_KINDS[$p['kind']]['per'] ?></span></p>
          <?php if ($p['kind'] === 'once' && $p['class_date']): ?>
            <p class="when"><?= h(date('l, F j, Y', strtotime($p['class_date']))) ?></p>
          <?php endif ?>
          <?php if ($p['description'] !== ''): ?><p><?= nl2br(h($p['description'])) ?></p><?php endif ?>
        </div>

        <form class="pay-form" method="post" action="/pay/checkout.php" data-price="<?= (int)$p['price_cents'] ?>" data-kind="<?= h($p['kind']) ?>">
          <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
          <label class="field">Student’s name
            <input name="student_name" required autocomplete="name" maxlength="120">
          </label>
          <label class="field">Email for the receipt
            <input name="email" type="email" required autocomplete="email" maxlength="200">
          </label>

          <?php if ($p['kind'] === 'once'): ?>
            <label class="field field--short">How many people?
              <input name="quantity" type="number" min="1" max="10" value="1" required>
            </label>
          <?php else: ?>
            <fieldset class="periods">
              <legend><?= $p['kind'] === 'monthly' ? 'Which months?' : 'Which weeks?' ?></legend>
              <?php $first = true; foreach ($options as $value => $label): ?>
                <label class="check"><input type="checkbox" name="periods[]" value="<?= h($value) ?>"<?= $first ? ' checked' : '' ?>> <?= h($label) ?></label>
              <?php $first = false; endforeach ?>
            </fieldset>
          <?php endif ?>

          <button class="btn btn--amber" type="submit" data-pay-button>Pay <?= money((int)$p['price_cents']) ?></button>
          <p class="fine">You’ll finish on Stripe’s secure checkout page.</p>
        </form>
      </article>
    <?php endforeach ?>
  </div>
</section>
<?php
public_footer();
