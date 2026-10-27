<?php
// Where Stripe sends people after paying. Also confirms the payment directly
// with Stripe, so records stay right even before the webhook is set up.
require __DIR__ . '/../../app/bootstrap.php';

$payment = null;
$session_id = (string)($_GET['session_id'] ?? '');
if (preg_match('/^cs_[A-Za-z0-9_]+$/', $session_id)) {
    try {
        $payment = apply_checkout_session(stripe_api('GET', 'checkout/sessions/' . $session_id));
    } catch (RuntimeException $e) {
        error_log('cvilleglass thanks: ' . $e->getMessage());
    }
}
$periods = [];
if ($payment) {
    $st = db()->prepare('SELECT period FROM payment_periods WHERE payment_id = ? ORDER BY period');
    $st->execute([$payment['id']]);
    $periods = $st->fetchAll(PDO::FETCH_COLUMN);
}

public_header('Thank you');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <p class="eyebrow">Payment</p>
    <?php if ($payment && $payment['status'] === 'paid'): ?>
      <h1>Thank you, <?= h(strtok($payment['student_name'], ' ')) ?>.</h1>
      <p class="lead">You’re paid up for <b><?= h($payment['product_name']) ?></b> — <?= h(covers_text($payment, $periods)) ?>. A receipt is on its way to <?= h($payment['email']) ?>.</p>
    <?php elseif ($payment): ?>
      <h1>Payment is processing.</h1>
      <p class="lead">Some payment types (like bank transfers) take a few days to clear. You’ll get an email from Stripe when it does.</p>
    <?php else: ?>
      <h1>Thank you.</h1>
      <p class="lead">If you just paid, a receipt is on its way to your email. Questions? Write to <a class="tlink" href="mailto:<?= h(contact_email()) ?>"><?= h(contact_email()) ?></a>.</p>
    <?php endif ?>
    <div class="btn-row"><a class="btn btn--primary" href="/">Back to the site</a><a class="btn btn--ghost" href="/pay/">Pay for something else</a></div>
  </div>
</section>
<?php
public_footer();
