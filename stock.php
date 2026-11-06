<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$blank  = ['id' => 0, 'date' => date('Y-m-d'), 'item_id' => 0, 'qty' => '', 'unit_price' => '', 'note' => ''];
$form   = $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) post('id');

    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM stock WHERE id = ?')->execute([$id]);
        flash('Stock entry deleted.');
        redirect('stock.php');
    }

    $form = ['id' => $id, 'date' => post('date'), 'item_id' => (int) post('item_id'),
             'qty' => post('qty'), 'unit_price' => post('unit_price'), 'note' => post('note')];
    $qty      = parse_stock_qty($form['qty']);
    $has_unit = $form['unit_price'] !== '';
    $unit     = $has_unit ? parse_amount($form['unit_price']) : null;

    $exists = db()->prepare('SELECT 1 FROM items WHERE id = ?');
    $exists->execute([$form['item_id']]);

    if (!valid_date($form['date'])) {
        $errors[] = 'Enter a valid date.';
    }
    if (!$exists->fetchColumn()) {
        $errors[] = 'Select an item code.';
    }
    if ($qty === null) {
        $errors[] = 'Qty must be a whole number and not 0. Use a minus for damaged stock.';
    }
    if ($has_unit && $unit === null) {
        $errors[] = 'Enter a valid unit price.';
    }
    if (!$has_unit && $form['note'] === '') {
        $errors[] = 'Add a note when there is no unit price, such as Damaged or Replacement.';
    }
    if ($has_unit && $qty !== null && $qty < 0) {
        $errors[] = 'Leave the unit price blank for damaged stock.';
    }
    if (mb_strlen($form['note']) > 255) {
        $errors[] = 'The note is too long.';
    }

    if (!$errors) {
        $values = [$form['date'], $form['item_id'], $qty, $unit, round($qty * ($unit ?? 0), 2), $form['note']];
        if ($id) {
            db()->prepare('UPDATE stock SET date = ?, item_id = ?, qty = ?, unit_price = ?, total_price = ?, note = ? WHERE id = ?')
                ->execute([...$values, $id]);
            flash('Stock entry updated.');
        } else {
            db()->prepare('INSERT INTO stock (date, item_id, qty, unit_price, total_price, note) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute($values);
            flash('Stock entry saved.');
        }
        redirect('stock.php');
    }
} elseif (get('edit') !== '') {
    $find = db()->prepare('SELECT id, date, item_id, qty, unit_price, note FROM stock WHERE id = ?');
    $find->execute([(int) get('edit')]);
    $form = $find->fetch() ?: $blank;
}

$items  = items_with_stock();
$filter = (int) get('item');
$where  = $filter ? 'WHERE s.item_id = ?' : '';
$params = $filter ? [$filter] : [];

$totals = db()->prepare("SELECT COUNT(*) AS entries, COALESCE(SUM(CASE WHEN unit_price IS NOT NULL THEN qty END), 0) AS qty,
                                COALESCE(SUM(total_price), 0) AS cost
                         FROM stock s $where");
$totals->execute($params);
$totals = $totals->fetch();

[$page, $pages, $offset] = paginate((int) $totals['entries']);
$rows = db()->prepare("SELECT s.*, i.code, i.description FROM stock s JOIN items i ON i.id = s.item_id $where
                       ORDER BY s.date DESC, s.id DESC LIMIT " . PER_PAGE . " OFFSET $offset");
$rows->execute($params);
$rows = $rows->fetchAll();

layout_top('Stock', 'stock');
hero('Stock', 'Record what you buy and what it costs.');
$average = $totals['qty'] > 0 ? $totals['cost'] / $totals['qty'] : 0;
?>
<dl class="stats stats-3">
    <?= stat_tile('box', 'Units purchased', (string) (int) $totals['qty']) ?>
    <?= stat_tile('wallet', 'Total purchase cost', 'Rs. ' . money($totals['cost'])) ?>
    <?= stat_tile('tag', 'Average unit cost', 'Rs. ' . money($average)) ?>
</dl>

<div class="split">
    <section class="card sticky">
        <h2><?= $form['id'] ? 'Edit stock entry' : 'Add stock' ?></h2>
        <?php show_errors($errors); ?>
        <?php if (!$items): ?>
            <?= empty_state('Register an item first on the <a href="items.php">Items page</a>.') ?>
        <?php else: ?>
        <form method="post" data-entry="stock">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <label>Date
                <input type="date" name="date" value="<?= h($form['date']) ?>" required>
            </label>
            <label>Item code
                <select name="item_id" required>
                    <option value="">Select…</option>
                    <?= item_options($items, (int) $form['item_id']) ?>
                </select>
            </label>
            <label>Description
                <input name="description" readonly tabindex="-1">
            </label>
            <div class="row-2">
                <label>Qty purchased
                    <input type="number" name="qty" min="-<?= MAX_QTY ?>" max="<?= MAX_QTY ?>" step="1" value="<?= h($form['qty']) ?>" required>
                </label>
                <label>Unit price (Rs.)
                    <input type="number" name="unit_price" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= h($form['unit_price']) ?>">
                </label>
            </div>
            <small class="hint form-hint">For damaged stock, enter a minus qty. For damaged or replacement stock, leave the unit price blank and add a note.</small>
            <label>Total price (Rs.)
                <input id="total" class="total" readonly tabindex="-1">
            </label>
            <label>Note
                <input name="note" maxlength="255" value="<?= h($form['note']) ?>" placeholder="Supplier, damage or replacement">
            </label>
            <div class="actions">
                <button class="btn btn-primary"><?= $form['id'] ? 'Save changes' : 'Save stock' ?></button>
                <?php if ($form['id']): ?><a class="btn btn-quiet" href="stock.php">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="card card-table">
        <form method="get" class="filters">
            <label>Show
                <select name="item" data-autosubmit>
                    <option value="">All items</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"<?= (int) $item['id'] === $filter ? ' selected' : '' ?>><?= h($item['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if (!$rows): ?>
            <?= empty_state('No stock entries yet.') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Date</th><th>Item</th><th class="num">Qty</th>
                    <th class="num">Unit price</th><th class="num">Total</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><span class="date-chip"><b><?= date('d', strtotime($row['date'])) ?></b><?= date('M Y', strtotime($row['date'])) ?></span></td>
                        <td>
                            <span class="strong"><?= h($row['code']) ?></span>
                            <small><?= h($row['note'] !== '' ? $row['note'] : $row['description']) ?></small>
                        </td>
                        <td class="num<?= $row['qty'] < 0 ? ' negative' : '' ?>"><?= (int) $row['qty'] ?></td>
                        <td class="num"><?= $row['unit_price'] === null ? '–' : money($row['unit_price']) ?></td>
                        <td class="num strong"><?= $row['unit_price'] === null ? '–' : money($row['total_price']) ?></td>
                        <td class="row-actions">
                            <a class="icon-link" href="?edit=<?= (int) $row['id'] ?>" title="Edit" aria-label="Edit entry"><?= icon('edit') ?></a>
                            <form method="post" data-confirm="Delete this stock entry?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="icon-link danger" title="Delete" aria-label="Delete entry"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pager($page, $pages, $filter ? ['item' => $filter] : []) ?>
        <?php endif; ?>
    </section>
</div>
<?php layout_bottom();
