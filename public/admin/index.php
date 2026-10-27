<?php
// Payments: who has paid for each month, plus cash/check entries.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
$pdo = db();

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$here = '/admin/?m=' . $month;

// ---- actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'manual') {
        $product = product((int)($_POST['product_id'] ?? 0));
        $student = trim((string)($_POST['student_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $periods = [];
        $quantity = 1;
        if ($product && $product['kind'] === 'once') {
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $periods = once_period($product);
        } elseif ($product) {
            $allowed = period_options($product['kind'], $product['kind'] === 'monthly' ? 3 : 8);
            foreach (array_intersect(array_keys($allowed), (array)($_POST['periods_' . $product['kind']] ?? [])) as $p) {
                $periods[$p] = substr($p, 0, 7);
            }
        }
        $standard = $product ? (int)$product['price_cents'] * ($product['kind'] === 'once' ? $quantity : count($periods)) : 0;
        $typed = trim((string)($_POST['amount'] ?? ''));
        $amount = $typed === '' ? $standard : (int)round((float)str_replace(['$', ','], '', $typed) * 100);

        if (!$product || $student === '' || !$periods) {
            flash('To record a payment, pick a class, a student name, and at least one month or week.', 'error');
        } else {
            record_payment($product, $student, $email, $periods, $quantity, $amount, 'manual', trim((string)($_POST['note'] ?? '')));
            flash('Recorded ' . money($amount) . ' from ' . $student . '.');
        }
        redirect($here);
    }

    if ($action === 'delete_manual') {
        $pdo->prepare("DELETE FROM payments WHERE id = ? AND method = 'manual'")->execute([(int)$_POST['id']]);
        flash('Entry removed.');
        redirect($here);
    }
}

// ---- who paid for this month ----
$st = $pdo->prepare("SELECT p.*, pp.period, pp.amount_cents AS period_cents
    FROM payment_periods pp JOIN payments p ON p.id = pp.payment_id
    WHERE p.status = 'paid' AND pp.month = ? ORDER BY lower(p.student_name), p.id, pp.period");
$st->execute([$month]);
$rows = [];
$total = 0;
foreach ($st->fetchAll() as $r) {
    $key = $r['id'];
    $rows[$key] ??= $r + ['month_cents' => 0];
    $rows[$key]['month_cents'] += $r['period_cents'];
    $total += $r['period_cents'];
}
$students = count(array_unique(array_map(fn($r) => strtolower(trim($r['student_name'])), $rows)));

// ---- six-month grid ----
$first = new DateTimeImmutable($month . '-01');
$grid_months = [];
for ($i = -2; $i <= 3; $i++) $grid_months[] = $first->modify("$i month")->format('Y-m');
$st = $pdo->prepare("SELECT p.student_name, p.email, p.kind, p.product_name, pp.month
    FROM payment_periods pp JOIN payments p ON p.id = pp.payment_id
    WHERE p.status = 'paid' AND pp.month BETWEEN ? AND ? ORDER BY lower(p.student_name)");
$st->execute([$grid_months[0], end($grid_months)]);
$grid = [];
foreach ($st->fetchAll() as $r) {
    $who = strtolower(trim($r['student_name'])) . '|' . strtolower(trim($r['email']));
    $grid[$who]['name'] = $r['student_name'];
    $grid[$who]['email'] = $r['email'];
    $grid[$who]['cells'][$r['month']][$r['kind']][] = $r['product_name'];
}

// ---- recent checkouts (all statuses) ----
$recent = $pdo->query('SELECT * FROM payments ORDER BY id DESC LIMIT 40')->fetchAll();
$periods_of = $pdo->prepare('SELECT period FROM payment_periods WHERE payment_id = ? ORDER BY period');
$covers = function (array $p) use ($periods_of): string {
    $periods_of->execute([$p['id']]);
    return covers_text($p, $periods_of->fetchAll(PDO::FETCH_COLUMN));
};

$all_products = products(true);
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');

admin_header('Payments', 'payments');
?>
<div class="admin-head">
  <h1>Payments</h1>
  <div class="month-nav">
    <a class="btn btn--ghost btn--sm" href="/admin/?m=<?= $prev ?>">← <?= h(date('M', strtotime($prev . '-01'))) ?></a>
    <form method="get"><input type="month" name="m" value="<?= h($month) ?>" onchange="this.form.submit()" aria-label="Month"></form>
    <a class="btn btn--ghost btn--sm" href="/admin/?m=<?= $next ?>"><?= h(date('M', strtotime($next . '-01'))) ?> →</a>
  </div>
</div>

<section class="panel">
  <div class="panel-head">
    <h2>Paid for <?= h(month_label($month)) ?></h2>
    <p class="stat"><b><?= money($total) ?></b> from <?= $students ?> student<?= $students === 1 ? '' : 's' ?></p>
  </div>
  <?php if (!$rows): ?>
    <p class="empty">No payments for <?= h(month_label($month)) ?> yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Student</th><th>Email</th><th>Class</th><th>Covers</th><th class="num">This month</th><th>Paid</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['student_name']) ?></td>
          <td><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
          <td><?= h($r['product_name']) ?></td>
          <td><?= h($covers($r)) ?></td>
          <td class="num"><?= money((int)$r['month_cents']) ?></td>
          <td><?= h(nice_date($r['paid_at'])) ?><?= $r['method'] === 'manual' ? ' <span class="pill">cash/check</span>' : '' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php endif ?>
  <p class="fine"><a href="/admin/export.php">Download every payment as a spreadsheet (CSV)</a></p>
</section>

<section class="panel">
  <div class="panel-head"><h2>Month by month</h2><p class="fine">✓ = monthly · number = weeks paid · ★ = one-time class. Paying ahead shows up in future months.</p></div>
  <?php if (!$grid): ?>
    <p class="empty">Nobody has paid for these months yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="grid">
      <thead><tr><th>Student</th><?php foreach ($grid_months as $m): ?><th class="<?= $m === $month ? 'is-current' : '' ?>"><a href="/admin/?m=<?= $m ?>"><?= h(date('M Y', strtotime($m . '-01'))) ?></a></th><?php endforeach ?></tr></thead>
      <tbody>
      <?php foreach ($grid as $g): ?>
        <tr>
          <td><?= h($g['name']) ?><br><span class="fine"><?= h($g['email']) ?></span></td>
          <?php foreach ($grid_months as $m): $cell = $g['cells'][$m] ?? []; ?>
            <td class="<?= $m === $month ? 'is-current' : '' ?>">
              <?php if (isset($cell['monthly'])): ?><span class="mark mark--month" title="<?= h(implode(', ', array_unique($cell['monthly']))) ?>">✓</span><?php endif ?>
              <?php if (isset($cell['weekly'])): ?><span class="mark mark--week" title="<?= h(implode(', ', array_unique($cell['weekly']))) ?>"><?= count($cell['weekly']) ?> wk</span><?php endif ?>
              <?php if (isset($cell['once'])): ?><span class="mark mark--once" title="<?= h(implode(', ', array_unique($cell['once']))) ?>">★</span><?php endif ?>
            </td>
          <?php endforeach ?>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  <?php endif ?>
</section>

<details class="panel">
  <summary><h2>Record a cash or check payment</h2></summary>
  <form method="post" class="manual-form" data-manual-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="manual">
    <label class="field">Class
      <select name="product_id" required>
        <?php foreach ($all_products as $p): ?>
          <option value="<?= $p['id'] ?>" data-kind="<?= h($p['kind']) ?>"><?= h($p['name']) ?> (<?= money((int)$p['price_cents']) ?> <?= CVG_KINDS[$p['kind']]['per'] ?>)</option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="field">Student’s name <input name="student_name" required></label>
    <label class="field">Email <input name="email" type="email"></label>
    <fieldset class="periods" data-kind="monthly"><legend>Months covered</legend>
      <?php foreach (period_options('monthly', 3) as $v => $label): ?>
        <label class="check"><input type="checkbox" name="periods_monthly[]" value="<?= h($v) ?>"<?= $v === $month ? ' checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach ?>
    </fieldset>
    <fieldset class="periods" data-kind="weekly"><legend>Weeks covered</legend>
      <?php foreach (period_options('weekly', 8) as $v => $label): ?>
        <label class="check"><input type="checkbox" name="periods_weekly[]" value="<?= h($v) ?>"> <?= h($label) ?></label>
      <?php endforeach ?>
    </fieldset>
    <label class="field field--short" data-kind="once">How many people <input name="quantity" type="number" min="1" value="1"></label>
    <label class="field field--short">Amount received <input name="amount" inputmode="decimal" placeholder="standard price"></label>
    <label class="field">Note <input name="note" placeholder="e.g. check #1042"></label>
    <button class="btn btn--primary" type="submit">Record payment</button>
  </form>
</details>

<section class="panel">
  <div class="panel-head"><h2>Recent activity</h2><p class="fine">Every checkout started on the pay page, newest first. “Started” means they opened Stripe but haven’t paid.</p></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Student</th><th>Class</th><th>Covers</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $p): ?>
      <tr class="status-<?= h($p['status']) ?>">
        <td><?= h(nice_date($p['created_at'])) ?></td>
        <td><?= h($p['student_name']) ?><br><span class="fine"><?= h($p['email']) ?></span></td>
        <td><?= h($p['product_name']) ?></td>
        <td><?= h($covers($p)) ?></td>
        <td class="num"><?= money((int)$p['amount_cents']) ?></td>
        <td><span class="pill pill--<?= h($p['status']) ?>"><?= h(['pending' => 'started', 'paid' => 'paid', 'expired' => 'abandoned', 'failed' => 'failed'][$p['status']] ?? $p['status']) ?></span><?= $p['method'] === 'manual' ? ' <span class="pill">cash/check</span>' : '' ?></td>
        <td>
          <?php if ($p['stripe_payment_intent']): ?>
            <a href="<?= h(stripe_dashboard_url($p['stripe_payment_intent'])) ?>" target="_blank" rel="noopener">Stripe ↗</a>
          <?php elseif ($p['method'] === 'manual'): ?>
            <form method="post" onsubmit="return confirm('Remove this cash/check entry?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_manual"><input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button class="linkish" type="submit">Remove</button>
            </form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$recent): ?><tr><td colspan="7" class="empty">Nothing yet.</td></tr><?php endif ?>
    </tbody>
  </table></div>
</section>
<?php
admin_footer();
