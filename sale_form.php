<?php
require __DIR__ . '/includes/bootstrap.php';

$items   = items_with_stock();
$known   = array_column($items, 'code', 'id');
$costs   = array_column($items, 'cost_price', 'id');
$errors  = [];
$blank_line = ['item_id' => 0, 'qty' => '1', 'unit_price' => ''];
$form = [
    'id' => 0, 'date' => date('Y-m-d'), 'order_no' => '', 'invoice_no' => '', 'waybill_no' => '', 'customer_name' => '',
    'address' => '', 'mobile' => '',
    'city' => '', 'district' => '', 'delivery_charge' => '0', 'status' => STATUSES[0],
    'referral_type' => '', 'cash_status' => CASH_STATUSES[0],
];
$lines = [$blank_line];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach (array_keys($form) as $field) {
        $form[$field] = post($field);
    }
    $form['id'] = (int) $form['id'];
    $delivery   = parse_amount($form['delivery_charge']);

    if (!valid_date($form['date'])) {
        $errors[] = 'Enter a valid date.';
    }
    if (mb_strlen($form['order_no']) > 50 || mb_strlen($form['invoice_no']) > 50 || mb_strlen($form['waybill_no']) > 50
        || mb_strlen($form['customer_name']) > 255 || mb_strlen($form['address']) > 255
        || mb_strlen($form['mobile']) > 50 || mb_strlen($form['city']) > 100) {
        $errors[] = 'One of the fields is too long.';
    }
    if ($form['order_no'] !== '') {
        $duplicate = db()->prepare('SELECT 1 FROM sales WHERE order_no = ? AND id <> ?');
        $duplicate->execute([$form['order_no'], $form['id']]);
        if ($duplicate->fetchColumn()) {
            $errors[] = "Order number {$form['order_no']} already exists.";
        }
    }
    if ($form['invoice_no'] !== '') {
        $duplicate = db()->prepare('SELECT 1 FROM sales WHERE invoice_no = ? AND id <> ?');
        $duplicate->execute([$form['invoice_no'], $form['id']]);
        if ($duplicate->fetchColumn()) {
            $errors[] = "Invoice {$form['invoice_no']} already exists. Add all of its items to that sale.";
        }
    }
    if ($form['customer_name'] === '') {
        $errors[] = 'Enter the customer name.';
    }
    if (!preg_match('/^[0-9+\-\s,\/()]*$/', $form['mobile'])) {
        $errors[] = 'Enter the mobile number using digits only. Separate two numbers with a comma.';
    }
    if ($form['city'] === '') {
        $errors[] = 'Enter the city.';
    }
    if (!isset(DISTRICTS[$form['district']])) {
        $errors[] = 'Select a district.';
    }

    // Item rows: each must be a known item, listed once, with a valid quantity and price.
    $item_ids = post_list('item_id');
    $qtys     = post_list('qty');
    $prices   = post_list('unit_price');
    $lines    = [];
    $saved    = [];
    foreach ($item_ids as $index => $item_id) {
        $item_id = (int) $item_id;
        $qty     = parse_qty($qtys[$index] ?? '');
        $price   = parse_amount($prices[$index] ?? '', 4);
        $row     = 'Item row ' . ($index + 1);
        $lines[] = ['item_id' => $item_id, 'qty' => $qtys[$index] ?? '', 'unit_price' => $prices[$index] ?? ''];

        if (!isset($known[$item_id])) {
            $errors[] = "$row: select an item code.";
        } elseif (isset($saved[$item_id])) {
            $errors[] = "{$known[$item_id]} is listed twice. Use one row and increase its qty.";
        }
        if ($qty === null) {
            $errors[] = "$row: qty must be a whole number of at least 1.";
        }
        if ($price === null) {
            $errors[] = "$row: enter a valid product price.";
        }
        $saved[$item_id] = [$qty, $price];
    }
    if (!$lines) {
        $errors[] = 'Add at least one item.';
        $lines    = [$blank_line];
    }

    if ($delivery === null) {
        $errors[] = 'Enter valid delivery charges (0 if none).';
    }
    if (!in_array($form['status'], STATUSES, true)) {
        $errors[] = 'Select a status.';
    }
    if (!in_array($form['referral_type'], REFERRAL_TYPES, true)) {
        $errors[] = 'Select a referral type.';
    }
    if (!in_array($form['cash_status'], CASH_STATUSES, true)) {
        $errors[] = 'Select a cash received status.';
    }

    if (!$errors) {
        $values = [
            $form['date'], $form['order_no'] !== '' ? $form['order_no'] : null,
            $form['invoice_no'] !== '' ? $form['invoice_no'] : null, $form['waybill_no'], $form['customer_name'],
            $form['address'], $form['mobile'], $form['city'],
            $form['district'], DISTRICTS[$form['district']], $delivery,
            $form['status'], $form['referral_type'], $form['cash_status'],
        ];
        // A line keeps the cost it was sold at; a changed item cost must not rewrite past profit.
        $sold_costs = [];
        db()->beginTransaction();
        if ($form['id']) {
            $find = db()->prepare('SELECT item_id, unit_cost FROM sale_items WHERE sale_id = ?');
            $find->execute([$form['id']]);
            $sold_costs = array_column($find->fetchAll(), 'unit_cost', 'item_id');
            db()->prepare('UPDATE sales SET date = ?, order_no = ?, invoice_no = ?, waybill_no = ?, customer_name = ?, address = ?, mobile = ?, city = ?,
                           district = ?, province = ?, delivery_charge = ?, status = ?, referral_type = ?,
                           cash_status = ? WHERE id = ?')
                ->execute([...$values, $form['id']]);
            db()->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$form['id']]);
            $sale_id = $form['id'];
        } else {
            db()->prepare('INSERT INTO sales (date, order_no, invoice_no, waybill_no, customer_name, address, mobile, city, district, province,
                           delivery_charge, status, referral_type, cash_status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute($values);
            $sale_id = (int) db()->lastInsertId();
        }
        $add_line = db()->prepare('INSERT INTO sale_items (sale_id, item_id, qty, unit_price, unit_cost) VALUES (?, ?, ?, ?, ?)');
        foreach ($saved as $item_id => [$qty, $price]) {
            $add_line->execute([$sale_id, $item_id, $qty, $price, $sold_costs[$item_id] ?? $costs[$item_id] ?? null]);
        }
        db()->commit();
        flash($form['id'] ? 'Sale updated.' : 'Sale saved.');
        redirect('index.php');
    }
} elseif (get('id') !== '') {
    $find = db()->prepare('SELECT * FROM sales WHERE id = ?');
    $find->execute([(int) get('id')]);
    $sale = $find->fetch();
    if (!$sale) {
        flash('That sale no longer exists.', 'error');
        redirect('index.php');
    }
    $form = array_intersect_key($sale, $form);

    $find = db()->prepare('SELECT item_id, qty, unit_price FROM sale_items WHERE sale_id = ? ORDER BY id');
    $find->execute([$sale['id']]);
    $lines = $find->fetchAll();
}

/** One item row of the sale form. */
function sale_line(array $items, array $line): string
{
    $price = $line['unit_price'];
    if (is_numeric($price)) {
        $price = rtrim(rtrim(number_format((float) $price, 4, '.', ''), '0'), '.');
    }
    return '<div class="line">'
        . '<label>Item code<select name="item_id[]" required><option value="">Select…</option>'
        . item_options($items, (int) $line['item_id']) . '</select><small class="line-stock"></small></label>'
        . '<label class="line-desc">Description<input class="line-description" readonly tabindex="-1"></label>'
        . '<label>Qty<input type="number" name="qty[]" min="1" max="' . MAX_QTY . '" step="1" value="' . h($line['qty']) . '" required></label>'
        . '<label>Price each (Rs.)<input type="number" name="unit_price[]" min="0" max="' . MAX_AMOUNT . '" step="any" value="' . h($price) . '" required></label>'
        . '<div class="line-total"><span class="field-label">Line total</span><output class="line-amount"></output></div>'
        . '<button type="button" class="icon-link danger line-remove" title="Remove item" aria-label="Remove item">' . icon('trash') . '</button>'
        . '</div>';
}

$title = $form['id'] ? 'Edit sale' : 'New sale';
layout_top($title, 'sales');
hero($title, $form['id'] ? 'Update the details of this order.' : 'Record a new order.',
    '<a class="btn btn-hero" href="index.php">Back to sales</a>', true);
show_errors($errors);
?>
<?php if (!$items): ?>
    <section class="card"><?= empty_state('Register an item first on the <a href="items.php">Items page</a>.') ?></section>
<?php else: ?>
<form method="post" data-entry="sale" class="sale-layout">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

    <div class="sale-main">
        <section class="card">
            <h2><span class="step">1</span>Order</h2>
            <div class="grid grid-4">
                <label>Date
                    <input type="date" name="date" value="<?= h($form['date']) ?>" required>
                </label>
                <label>Order number
                    <input name="order_no" maxlength="50" value="<?= h($form['order_no']) ?>">
                </label>
                <label>Invoice number
                    <input name="invoice_no" maxlength="50" value="<?= h($form['invoice_no']) ?>" autofocus>
                </label>
                <label>Waybill number
                    <input name="waybill_no" maxlength="50" value="<?= h($form['waybill_no']) ?>">
                </label>
            </div>
            <div class="field">
                <span class="field-label">Referral type</span>
                <?= chips('referral_type', REFERRAL_TYPES, $form['referral_type']) ?>
            </div>
        </section>

        <section class="card">
            <h2><span class="step">2</span>Customer</h2>
            <div class="grid grid-2">
                <label>Name
                    <input name="customer_name" maxlength="255" value="<?= h($form['customer_name']) ?>" required>
                </label>
                <label>Mobile number
                    <input type="tel" name="mobile" maxlength="50" value="<?= h($form['mobile']) ?>" placeholder="07X XXX XXXX">
                </label>
            </div>
            <label>Address
                <input name="address" maxlength="255" value="<?= h($form['address']) ?>" autocomplete="off">
            </label>
            <div class="grid">
                <label>City
                    <input name="city" maxlength="100" value="<?= h($form['city']) ?>" required>
                </label>
                <label>District
                    <select name="district" required>
                        <option value="">Select…</option>
                        <?php foreach (DISTRICTS as $district => $province): ?>
                            <option value="<?= h($district) ?>" data-province="<?= h($province) ?>"<?= $district === $form['district'] ? ' selected' : '' ?>><?= h($district) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Province
                    <input name="province" readonly tabindex="-1">
                </label>
            </div>
        </section>

        <section class="card">
            <h2><span class="step">3</span>Items</h2>
            <div id="lines">
                <?php foreach ($lines as $line): ?>
                    <?= sale_line($items, $line) ?>
                <?php endforeach; ?>
            </div>
            <template id="line-template"><?= sale_line($items, $blank_line) ?></template>
            <div class="line-footer">
                <button type="button" class="btn btn-quiet" id="add-line"><?= icon('plus') ?>Add another item</button>
                <label>Delivery charges (Rs.)
                    <input type="number" name="delivery_charge" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= h($form['delivery_charge']) ?>" required>
                </label>
            </div>
        </section>
    </div>

    <aside class="card summary">
        <h2>Order summary</h2>
        <p id="sum-empty" class="summary-item">No items selected</p>
        <ul id="sum-lines" class="summary-lines"></ul>
        <dl>
            <div><dt>Subtotal</dt><dd id="sum-subtotal"></dd></div>
            <div><dt>Delivery</dt><dd id="sum-delivery"></dd></div>
        </dl>
        <div class="summary-total">
            <span>Total</span>
            <output id="total"></output>
        </div>
        <label>Status
            <select name="status" required><?= options(STATUSES, $form['status']) ?></select>
        </label>
        <div class="field">
            <span class="field-label">Cash received status</span>
            <?= chips('cash_status', CASH_STATUSES, $form['cash_status']) ?>
        </div>
        <button class="btn btn-primary btn-block"><?= $form['id'] ? 'Save changes' : 'Save sale' ?></button>
    </aside>
</form>
<?php endif; ?>
<?php layout_bottom();
