<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$blank  = ['id' => 0, 'code' => '', 'description' => '', 'cost_price' => '', 'selling_price' => '', 'buyer' => ''];
$form   = $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) post('id');

    if (post('action') === 'delete') {
        try {
            db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
            flash('Item deleted.');
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            flash('This item has sales or stock entries, so it cannot be deleted.', 'error');
        }
        redirect('items.php');
    }

    $form  = ['id' => $id, 'code' => post('code'), 'description' => post('description'),
              'cost_price' => post('cost_price'), 'selling_price' => post('selling_price'), 'buyer' => post('buyer')];
    $cost    = parse_amount($form['cost_price']);
    $selling = parse_amount($form['selling_price']);

    if ($form['code'] === '') {
        $errors[] = 'Enter the item code.';
    }
    if ($form['description'] === '') {
        $errors[] = 'Enter the item description.';
    }
    if ($cost === null) {
        $errors[] = 'Enter a valid product cost.';
    }
    if ($selling === null) {
        $errors[] = 'Enter a valid selling price.';
    }
    if (mb_strlen($form['code']) > 50 || mb_strlen($form['description']) > 255 || mb_strlen($form['buyer']) > 255) {
        $errors[] = 'One of the fields is too long.';
    }
    $duplicate = db()->prepare('SELECT 1 FROM items WHERE code = ? AND id <> ?');
    $duplicate->execute([$form['code'], $id]);
    if ($duplicate->fetchColumn()) {
        $errors[] = "Item code {$form['code']} is already registered.";
    }

    if (!$errors) {
        $values = [$form['code'], $form['description'], $cost, $selling, $form['buyer']];
        if ($id) {
            db()->prepare('UPDATE items SET code = ?, description = ?, cost_price = ?, selling_price = ?, buyer = ? WHERE id = ?')
                ->execute([...$values, $id]);
            flash('Item updated.');
        } else {
            db()->prepare('INSERT INTO items (code, description, cost_price, selling_price, buyer) VALUES (?, ?, ?, ?, ?)')
                ->execute($values);
            flash('Item registered.');
        }
        redirect('items.php');
    }
} elseif (get('edit') !== '') {
    $find = db()->prepare('SELECT id, code, description, cost_price, selling_price, buyer FROM items WHERE id = ?');
    $find->execute([(int) get('edit')]);
    $form = $find->fetch() ?: $blank;
}

$items = items_with_stock();
$units = $low = $out = 0;
foreach ($items as $item) {
    $units += max(0, (int) $item['in_stock']);
    [$state] = stock_state((int) $item['purchased'], (int) $item['in_stock']);
    $low += $state === 'low' ? 1 : 0;
    $out += $state === 'out' ? 1 : 0;
}

layout_top('Items', 'items');
hero('Items', 'Your product catalogue and what is left on the shelf.');
?>
<dl class="stats">
    <?= stat_tile('tag', 'Items registered', (string) count($items)) ?>
    <?= stat_tile('box', 'Units in stock', (string) $units) ?>
    <?= stat_tile('clock', 'Low stock', (string) $low) ?>
    <?= stat_tile('alert', 'Out of stock', (string) $out) ?>
</dl>

<div class="split">
    <section class="card sticky">
        <h2><?= $form['id'] ? 'Edit item' : 'Register an item' ?></h2>
        <?php show_errors($errors); ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <label>Item code
                <input name="code" maxlength="50" value="<?= h($form['code']) ?>" required autofocus>
            </label>
            <label>Item description
                <input name="description" maxlength="255" value="<?= h($form['description']) ?>" required>
            </label>
            <div class="row-2">
                <label>Product cost (Rs.)
                    <input type="number" name="cost_price" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= h($form['cost_price']) ?>" required>
                </label>
                <label>Selling price (Rs.)
                    <input type="number" name="selling_price" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= h($form['selling_price']) ?>" required>
                </label>
            </div>
            <label>Buyer
                <input name="buyer" maxlength="255" value="<?= h($form['buyer']) ?>">
            </label>
            <div class="actions">
                <button class="btn btn-primary"><?= $form['id'] ? 'Save changes' : 'Register item' ?></button>
                <?php if ($form['id']): ?><a class="btn btn-quiet" href="items.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section>
        <?php if (!$items): ?>
            <div class="card"><?= empty_state('No items yet. Register your first item to start recording stock and sales.') ?></div>
        <?php else: ?>
        <label class="catalogue-search"><span class="sr-only">Search items</span>
            <span class="search-field"><?= icon('search') ?><input type="search" id="item-search" placeholder="Search by code, description or buyer"></span>
        </label>
        <div class="products">
            <?php foreach ($items as $item):
                $purchased = (int) $item['purchased'];
                $in_stock  = (int) $item['in_stock'];
                [$state, $state_label, $state_icon] = stock_state($purchased, $in_stock);
                $percent = $purchased ? (int) round(max(0, min(1, $in_stock / $purchased)) * 100) : 0;
            ?>
            <article class="product" data-search="<?= h(mb_strtolower($item['code'] . ' ' . $item['description'] . ' ' . $item['buyer'])) ?>">
                <div class="product-head">
                    <span class="monogram"><?= h(monogram($item['description'])) ?></span>
                    <div>
                        <span class="product-code"><?= h($item['code']) ?></span>
                        <h3><?= h($item['description']) ?></h3>
                    </div>
                </div>
                <?php if ($item['selling_price'] === null): ?>
                    <p class="product-price product-price-missing">Selling price not set</p>
                <?php else: ?>
                    <p class="product-price">Rs. <?= money($item['selling_price']) ?></p>
                <?php endif; ?>
                <p class="product-cost">
                    <?php if ($item['cost_price'] === null): ?>
                        Cost not set
                    <?php else: ?>
                        Cost Rs. <?= money($item['cost_price']) ?><?php if ($item['selling_price'] !== null): ?> · Profit Rs. <?= money($item['selling_price'] - $item['cost_price']) ?><?php endif; ?>
                    <?php endif; ?>
                </p>
                <p class="product-buyer"><?= $item['buyer'] !== '' ? 'Buyer: ' . h($item['buyer']) : 'No buyer recorded' ?></p>
                <div class="meter meter-<?= $state ?>" role="img" aria-label="<?= $in_stock ?> of <?= $purchased ?> units left"><span style="width: <?= $percent ?>%"></span></div>
                <div class="product-stock">
                    <span><?php if ($in_stock < 0): ?><strong>Oversold by <?= -$in_stock ?></strong><?php else: ?><strong><?= $in_stock ?></strong> of <?= $purchased ?> left<?php endif; ?> · <?= (int) $item['sold'] ?> sold</span>
                    <span class="badge badge-<?= $state ?>"><?= icon($state_icon) ?><?= h($state_label) ?></span>
                </div>
                <div class="product-actions">
                    <a class="icon-link" href="?edit=<?= (int) $item['id'] ?>" title="Edit" aria-label="Edit <?= h($item['code']) ?>"><?= icon('edit') ?></a>
                    <form method="post" data-confirm="Delete item <?= h($item['code']) ?>?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <button class="icon-link danger" title="Delete" aria-label="Delete <?= h($item['code']) ?>"><?= icon('trash') ?></button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <div id="no-match" class="card" hidden><?= empty_state('No items match your search.') ?></div>
        <?php endif; ?>
    </section>
</div>
<?php layout_bottom();
