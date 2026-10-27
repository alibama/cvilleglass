<?php
// Classes and prices. New classes start as drafts so they can be checked before sharing.
require __DIR__ . '/../../app/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'delete') {
        $used = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE product_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn()) {
            flash('That class has payments recorded, so it can’t be deleted. Untick “Published” to hide it instead.', 'error');
        } else {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            flash('Class deleted.');
        }
        redirect('/admin/classes.php');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $kind = array_key_exists($_POST['kind'] ?? '', CVG_KINDS) ? $_POST['kind'] : 'once';
    $price = (int)round((float)str_replace(['$', ','], '', (string)($_POST['price'] ?? '0')) * 100);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['class_date'] ?? '') ? $_POST['class_date'] : '';
    $fields = [$name, trim((string)($_POST['description'] ?? '')), $kind, $price, $date,
               isset($_POST['visible']) ? 1 : 0, (int)($_POST['sort'] ?? 0)];

    if ($name === '' || $price < 50) {
        flash('A class needs a name and a price of at least $0.50.', 'error');
        redirect('/admin/classes.php?edit=' . ($id ?: 'new'));
    }
    if ($id) {
        $pdo->prepare('UPDATE products SET name = ?, description = ?, kind = ?, price_cents = ?, class_date = ?, visible = ?, sort = ? WHERE id = ?')
            ->execute([...$fields, $id]);
    } else {
        $pdo->prepare('INSERT INTO products (name, description, kind, price_cents, class_date, visible, sort) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute($fields);
    }
    flash('Saved “' . $name . '”.');
    redirect('/admin/classes.php');
}

$editing = null;
if (($_GET['edit'] ?? '') === 'new') {
    $editing = ['id' => 0, 'name' => '', 'description' => '', 'kind' => 'once', 'price_cents' => 0, 'class_date' => '', 'visible' => 0, 'sort' => 10];
} elseif (isset($_GET['edit'])) {
    $editing = product((int)$_GET['edit']);
}

admin_header('Classes', 'classes');
?>
<div class="admin-head">
  <h1>Classes &amp; prices</h1>
  <a class="btn btn--primary btn--sm" href="/admin/classes.php?edit=new">+ New class</a>
</div>

<?php if ($editing): ?>
<section class="panel">
  <h2><?= $editing['id'] ? 'Edit ' . h($editing['name']) : 'New class' ?></h2>
  <form method="post" class="edit-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <label class="field">Name <input name="name" value="<?= h($editing['name']) ?>" required maxlength="120"></label>
    <label class="field">How people pay
      <select name="kind">
        <?php foreach (CVG_KINDS as $k => $info): ?>
          <option value="<?= $k ?>"<?= $editing['kind'] === $k ? ' selected' : '' ?>><?= h($info['label']) ?> — <?= h($info['help']) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="field field--short">Price ($) <input name="price" inputmode="decimal" value="<?= $editing['price_cents'] ? h(rtrim(rtrim(number_format($editing['price_cents'] / 100, 2, '.', ''), '0'), '.')) : '' ?>" required></label>
    <label class="field field--short">Class date <span class="fine">(one-time classes)</span> <input name="class_date" type="date" value="<?= h($editing['class_date']) ?>"></label>
    <label class="field">Description <span class="fine">(shown on the pay page)</span>
      <textarea name="description" rows="4"><?= h($editing['description']) ?></textarea>
    </label>
    <label class="field field--short">Order on page <input name="sort" type="number" value="<?= (int)$editing['sort'] ?>"></label>
    <label class="check check--big"><input type="checkbox" name="visible"<?= $editing['visible'] ? ' checked' : '' ?>> Published — show it on the public pay page</label>
    <div class="btn-row">
      <button class="btn btn--primary" type="submit">Save</button>
      <a class="btn btn--ghost" href="/admin/classes.php">Cancel</a>
    </div>
  </form>
  <?php if ($editing['id']): ?>
    <form method="post" onsubmit="return confirm('Delete this class for good?')" class="danger-zone">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <button class="linkish" type="submit">Delete this class</button>
    </form>
  <?php endif ?>
</section>
<?php endif ?>

<section class="panel">
  <p class="fine">Price changes apply to new payments only. Drafts are visible to admins on the <a href="/pay/">pay page</a> so you can check them before publishing. Each class has its own link you can share.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Class</th><th>Type</th><th class="num">Price</th><th>Date</th><th>Status</th><th>Share link</th><th></th></tr></thead>
    <tbody>
    <?php foreach (products(true) as $p): $link = site_url('/pay/?class=' . $p['id']); ?>
      <tr>
        <td><b><?= h($p['name']) ?></b></td>
        <td><?= h(CVG_KINDS[$p['kind']]['label']) ?></td>
        <td class="num"><?= money((int)$p['price_cents']) ?> <span class="fine"><?= CVG_KINDS[$p['kind']]['per'] ?></span></td>
        <td><?= $p['class_date'] ? h(date('M j, Y', strtotime($p['class_date']))) : '' ?></td>
        <td><span class="pill pill--<?= $p['visible'] ? 'paid' : 'draft' ?>"><?= $p['visible'] ? 'Published' : 'Draft' ?></span></td>
        <td><input class="copy" readonly value="<?= h($link) ?>" onclick="this.select()" aria-label="Share link"></td>
        <td><a href="/admin/classes.php?edit=<?= $p['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php
admin_footer();
