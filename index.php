<?php
require __DIR__ . '/includes/bootstrap.php';

$filters = array_filter([
    'q'      => get('q'),
    'status' => in_array(get('status'), STATUSES, true) ? get('status') : '',
    'cash'   => in_array(get('cash'), CASH_STATUSES, true) ? get('cash') : '',
    'from' => valid_date(get('from')) ? get('from') : '',
    'to'   => valid_date(get('to')) ? get('to') : '',
], fn($value) => $value !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) post('id');

    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM sales WHERE id = ?')->execute([$id]);
        flash('Sale deleted.');
    } elseif (in_array(post('status'), STATUSES, true)) {
        db()->prepare('UPDATE sales SET status = ? WHERE id = ?')->execute([post('status'), $id]);
    } elseif (in_array(post('cash_status'), CASH_STATUSES, true)) {
        db()->prepare('UPDATE sales SET cash_status = ? WHERE id = ?')->execute([post('cash_status'), $id]);
    }

    // Return to the same filtered view and page.
    parse_str(post('back'), $back);
    redirect('index.php?' . http_build_query($back));
}

$where  = [];
$params = [];
if (isset($filters['q'])) {
    $where[] = '(s.order_no LIKE ? OR s.invoice_no LIKE ? OR s.waybill_no LIKE ? OR s.customer_name LIKE ? OR s.mobile LIKE ? OR s.address LIKE ? OR s.city LIKE ?
                 OR EXISTS (SELECT 1 FROM sale_items si JOIN items i ON i.id = si.item_id
                            WHERE si.sale_id = s.id AND (i.code LIKE ? OR i.description LIKE ?)))';
    array_push($params, ...array_fill(0, 9, '%' . $filters['q'] . '%'));
}
if (isset($filters['cash'])) {
    $where[]  = 's.cash_status = ?';
    $params[] = $filters['cash'];
}
if (isset($filters['from'])) {
    $where[]  = 's.date >= ?';
    $params[] = $filters['from'];
}
if (isset($filters['to'])) {
    $where[]  = 's.date <= ?';
    $params[] = $filters['to'];
}
$from_sales = 'FROM sales s JOIN (SELECT sale_id, SUM(ROUND(qty * unit_price, 2)) AS subtotal, SUM(qty) AS units
                                  FROM sale_items GROUP BY sale_id) t ON t.sale_id = s.id';

// The status tabs count within the other filters.
$status_counts = db()->prepare("SELECT s.status, COUNT(*) $from_sales" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY s.status');
$status_counts->execute($params);
$status_counts = $status_counts->fetchAll(PDO::FETCH_KEY_PAIR);

if (isset($filters['status'])) {
    $where[]  = 's.status = ?';
    $params[] = $filters['status'];
}
$from_where = $from_sales . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$line_total = 't.subtotal + s.delivery_charge';
$summary = db()->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(CASE WHEN s.status NOT IN " . NOT_SOLD_SQL . " THEN $line_total END), 0) AS sales,
           COALESCE(SUM(CASE WHEN s.cash_status = 'Received' THEN $line_total END), 0) AS received,
           COALESCE(SUM(CASE WHEN s.cash_status = 'Pending' AND s.status NOT IN " . NOT_SOLD_SQL . " THEN $line_total END), 0) AS pending
    $from_where");
$summary->execute($params);
$summary = $summary->fetch();

[$page, $pages, $offset] = paginate((int) $summary['orders']);
$rows = db()->prepare("SELECT s.*, t.units, $line_total AS total $from_where
                       ORDER BY s.date DESC, s.id DESC LIMIT " . PER_PAGE . " OFFSET $offset");
$rows->execute($params);
$rows = $rows->fetchAll();

// The items on the sales shown, grouped by sale.
$sale_lines = [];
if ($rows) {
    $ids  = array_column($rows, 'id');
    $find = db()->prepare('SELECT si.sale_id, si.qty, si.unit_price, i.code, i.description
                           FROM sale_items si JOIN items i ON i.id = si.item_id
                           WHERE si.sale_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                           ORDER BY si.id');
    $find->execute($ids);
    foreach ($find as $line) {
        $sale_lines[$line['sale_id']][] = $line;
    }
}

$back = http_build_query(['page' => $page] + $filters);

layout_top('Sales', 'sales');
hero('Sales', 'Follow every order from invoice to cash in hand.',
    '<a class="btn btn-hero" href="sale_form.php">' . icon('plus') . 'New sale</a>');
$all_count = array_sum($status_counts);
$tab_link  = fn(string $status) => '?' . h(http_build_query(($status ? ['status' => $status] : []) + array_diff_key($filters, ['status' => 1])));
?>
<dl class="stats">
    <?= stat_tile('receipt', 'Orders', (string) $summary['orders']) ?>
    <?= stat_tile('sales', 'Sales value', 'Rs. ' . money($summary['sales'])) ?>
    <?= stat_tile('wallet', 'Cash received', 'Rs. ' . money($summary['received'])) ?>
    <?= stat_tile('clock', 'Cash pending', 'Rs. ' . money($summary['pending'])) ?>
</dl>

<nav class="tabs" aria-label="Filter by status">
    <a href="<?= $tab_link('') ?>"<?= isset($filters['status']) ? '' : ' class="active" aria-current="true"' ?>>All <span><?= (int) $all_count ?></span></a>
    <?php foreach (STATUSES as $status): ?>
        <a href="<?= $tab_link($status) ?>"<?= ($filters['status'] ?? '') === $status ? ' class="active" aria-current="true"' : '' ?>><?= h($status) ?> <span><?= (int) ($status_counts[$status] ?? 0) ?></span></a>
    <?php endforeach; ?>
</nav>

<section class="card card-table">
    <form method="get" class="filters">
        <?php if (isset($filters['status'])): ?><input type="hidden" name="status" value="<?= h($filters['status']) ?>"><?php endif; ?>
        <label class="grow">Search
            <span class="search-field"><?= icon('search') ?><input type="search" name="q" value="<?= h($filters['q'] ?? '') ?>" placeholder="Order, invoice, waybill, name, mobile, address, city, item"></span>
        </label>
        <label>Cash
            <select name="cash"><option value="">All</option><?= options(CASH_STATUSES, $filters['cash'] ?? '') ?></select>
        </label>
        <label>From
            <input type="date" name="from" value="<?= h($filters['from'] ?? '') ?>">
        </label>
        <label>To
            <input type="date" name="to" value="<?= h($filters['to'] ?? '') ?>">
        </label>
        <button class="btn btn-quiet">Apply</button>
        <?php if ($filters): ?><a class="btn btn-quiet" href="index.php">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <?= empty_state($filters ? 'No sales match these filters.' : 'No sales yet. Add your first one with New sale.') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Date</th><th>Reference</th><th>Customer</th><th>Items</th><th class="num">Amount</th>
                <th>Status</th><th>Referral</th><th>Cash</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $sale_label = $row['invoice_no'] !== null ? 'invoice ' . $row['invoice_no'] : 'the sale for ' . $row['customer_name'];
            ?>
                <tr>
                    <td class="nowrap">
                        <?= h(date('d M', strtotime($row['date']))) ?>
                        <small><?= h(date('Y', strtotime($row['date']))) ?></small>
                    </td>
                    <td class="nowrap">
                        <?php if ($row['invoice_no'] !== null): ?><span class="strong"><?= h($row['invoice_no']) ?></span><?php else: ?><span class="no-invoice">No invoice</span><?php endif; ?>
                        <?php if ($row['order_no'] !== null): ?><small>Order <?= h($row['order_no']) ?></small><?php endif; ?>
                        <small><?= $row['waybill_no'] !== '' ? h($row['waybill_no']) : 'No waybill' ?></small>
                    </td>
                    <td class="wide">
                        <div class="person">
                            <span class="avatar"><?= h(monogram($row['customer_name'])) ?></span>
                            <div>
                                <?= h($row['customer_name']) ?>
                                <?php if ($row['mobile'] !== ''): ?><small><?= h($row['mobile']) ?></small><?php endif; ?>
                                <small<?= $row['address'] !== '' ? ' title="' . h($row['address']) . '"' : '' ?>><?= h($row['city']) ?>, <?= h($row['district']) ?>, <?= h($row['province']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td class="medium">
                        <?php foreach ($sale_lines[$row['id']] as $line): ?>
                            <div class="sale-line">
                                <span class="nowrap"><?= (int) $line['qty'] ?> × <?= h($line['code']) ?> <span class="line-price">@ <?= money($line['unit_price']) ?></span></span>
                                <small><?= h($line['description']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td class="num">
                        <span class="strong"><?= money($row['total']) ?></span>
                        <small><?= (int) $row['units'] ?> <?= (int) $row['units'] === 1 ? 'unit' : 'units' ?> + <?= money($row['delivery_charge']) ?> delivery</small>
                    </td>
                    <td>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <select class="pill pill-<?= h(slug($row['status'])) ?>" name="status" aria-label="Status" data-autosubmit><?= options(STATUSES, $row['status']) ?></select>
                        </form>
                    </td>
                    <td><span class="referral"><?= referral_icon($row['referral_type']) ?><?= h($row['referral_type']) ?></span></td>
                    <td>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <select class="pill pill-<?= h(slug($row['cash_status'])) ?>" name="cash_status" aria-label="Cash received status" data-autosubmit><?= options(CASH_STATUSES, $row['cash_status']) ?></select>
                        </form>
                    </td>
                    <td class="row-actions">
                        <a class="icon-link" href="sale_form.php?id=<?= (int) $row['id'] ?>" title="Edit" aria-label="Edit <?= h($sale_label) ?>"><?= icon('edit') ?></a>
                        <form method="post" data-confirm="Delete <?= h($sale_label) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <button class="icon-link danger" title="Delete" aria-label="Delete <?= h($sale_label) ?>"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, $filters) ?>
    <?php endif; ?>
</section>
<?php layout_bottom();
