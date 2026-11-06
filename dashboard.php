<?php
require __DIR__ . '/includes/bootstrap.php';

$presets = ['all' => 'All time', '12m' => 'Last 12 months', 'fy' => 'Financial year', 'year' => 'This year', 'last_year' => 'Last year'];
$range   = array_key_exists(get('range'), $presets) ? get('range') : 'all';
$from    = valid_date(get('from')) ? get('from') : '';
$to      = valid_date(get('to')) ? get('to') : '';

if ($from !== '' || $to !== '') {
    $range = 'custom';
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }
} elseif ($range === '12m') {
    $from = date('Y-m-01', strtotime('-11 months'));
} elseif ($range === 'fy') {
    [$from, $to] = fy_range(fy_start_year(date('Y-m-d')));
} elseif ($range === 'year') {
    $from = date('Y-01-01');
} elseif ($range === 'last_year') {
    $from = date('Y-01-01', strtotime('-1 year'));
    $to   = date('Y-12-31', strtotime('-1 year'));
}

$date_sql    = '';
$date_params = [];
if ($from !== '') {
    $date_sql     .= ' AND s.date >= ?';
    $date_params[] = $from;
}
if ($to !== '') {
    $date_sql     .= ' AND s.date <= ?';
    $date_params[] = $to;
}

function dashboard_query(string $sql, array $params): array
{
    $query = db()->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}

// Everything sold in the period: orders that were not cancelled or returned.
$sold    = 'FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
            WHERE s.status NOT IN ' . NOT_SOLD_SQL . $date_sql;
$revenue = 'SUM(ROUND(si.qty * si.unit_price, 2))';

$totals = dashboard_query("SELECT COALESCE($revenue, 0) AS revenue, COALESCE(SUM(si.qty * COALESCE(si.unit_cost, i.cost_price)), 0) AS cost,
                                  COALESCE(SUM(si.qty), 0) AS units, COUNT(DISTINCT s.id) AS orders,
                                  COUNT(DISTINCT si.item_id) AS item_types $sold", $date_params)[0];
$profit  = $totals['revenue'] - $totals['cost'];
$margin  = $totals['revenue'] > 0 ? $profit / $totals['revenue'] * 100 : 0;
$average = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : 0;
$per_order = $totals['orders'] > 0 ? $totals['units'] / $totals['orders'] : 0;

$statuses = array_column(
    dashboard_query("SELECT s.status, COUNT(*) AS orders FROM sales s WHERE 1 = 1 $date_sql GROUP BY s.status", $date_params),
    'orders',
    'status'
);
$all_orders = array_sum($statuses);
$lost       = ($statuses['Cancelled'] ?? 0) + ($statuses['Returned'] ?? 0);
$lost_rate  = $all_orders > 0 ? $lost / $all_orders * 100 : 0;

// Monthly sales, with months that had no sales filled in as zero.
$monthly = array_column(
    dashboard_query("SELECT DATE_FORMAT(s.date, '%Y-%m') AS month, $revenue AS revenue,
                            COUNT(DISTINCT s.id) AS orders, SUM(si.qty) AS units
                     $sold GROUP BY month ORDER BY month", $date_params),
    null,
    'month'
);
$trend = [];
if ($monthly) {
    $month = array_key_first($monthly);
    $last  = array_key_last($monthly);
    while ($month <= $last) {
        $row     = $monthly[$month] ?? ['revenue' => 0, 'orders' => 0, 'units' => 0];
        $trend[] = [
            'label'   => date('M Y', strtotime($month . '-01')),
            'revenue' => (float) $row['revenue'],
            'orders'  => (int) $row['orders'],
            'units'   => (int) $row['units'],
        ];
        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }
}
$best = $trend ? array_reduce($trend, fn($top, $row) => $row['revenue'] > ($top['revenue'] ?? -1) ? $row : $top, []) : null;

$top_items = dashboard_query("SELECT i.code, i.description, $revenue AS revenue, SUM(si.qty) AS units
                              $sold GROUP BY i.id ORDER BY revenue DESC LIMIT 8", $date_params);
$provinces = dashboard_query("SELECT s.province, $revenue AS revenue, COUNT(DISTINCT s.id) AS orders
                              $sold GROUP BY s.province ORDER BY revenue DESC", $date_params);
$referrals = dashboard_query("SELECT s.referral_type, $revenue AS revenue, COUNT(DISTINCT s.id) AS orders
                              $sold GROUP BY s.referral_type ORDER BY orders DESC", $date_params);

// Stock is always as of today, whatever period is selected.
$units_in_stock = 0;
$stock_value    = 0;
$reorder        = [];
foreach (items_with_stock() as $item) {
    $left             = (int) $item['in_stock'];
    $units_in_stock  += max(0, $left);
    $stock_value     += max(0, $left) * (float) $item['cost_price'];
    [$state, $label, $symbol] = stock_state((int) $item['purchased'], $left);
    if ($state === 'low' || $state === 'out') {
        $reorder[] = $item + ['left' => $left, 'state' => $state, 'state_label' => $label, 'state_icon' => $symbol];
    }
}
usort($reorder, fn($a, $b) => $a['left'] <=> $b['left']);

/** Ranked bars. Each row is [label, detail, amount, shown value, tooltip detail]. */
function rank_bars(array $rows): string
{
    $largest = max(array_column($rows, 2) ?: [0]);
    $html    = '<ul class="rank">';
    foreach ($rows as [$label, $detail, $amount, $shown, $tip]) {
        $width = $largest > 0 ? round($amount / $largest * 100, 1) : 0;
        $html .= '<li tabindex="0" data-tip-title="' . h($label) . '" data-tip-value="' . h($shown) . '" data-tip-detail="' . h($tip) . '">'
            . '<span class="rank-label">' . h($label) . ($detail !== '' ? '<small>' . h($detail) . '</small>' : '') . '</span>'
            . '<span class="rank-track">' . ($amount > 0 ? '<span class="rank-bar" style="width: ' . $width . '%"></span>' : '') . '</span>'
            . '<span class="rank-value">' . h($shown) . '</span></li>';
    }
    return $html . '</ul>';
}

layout_top('Dashboard', 'dashboard');
hero('Dashboard', 'How your store is doing, from sales to stock.', '', true);
?>
<div class="period-bar">
    <nav class="tabs" aria-label="Period">
        <?php foreach ($presets as $key => $label): ?>
            <a href="?range=<?= $key ?>"<?= $key === $range ? ' class="active" aria-current="true"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="get" class="period-custom">
        <label>From <input type="date" name="from" value="<?= h($range === 'custom' ? $from : '') ?>"></label>
        <label>To <input type="date" name="to" value="<?= h($range === 'custom' ? $to : '') ?>"></label>
        <button class="btn btn-quiet">Apply</button>
    </form>
</div>

<dl class="stats stats-flat stats-5">
    <?= stat_tile('sales', 'Product sales', 'Rs. ' . money($totals['revenue']), 'Excludes delivery, cancelled and returned') ?>
    <?= stat_tile('wallet', 'Estimated profit', 'Rs. ' . money($profit), number_format($margin, 1) . '% of sales, before expenses') ?>
    <?= stat_tile('tag', 'Items sold', number_format((int) $totals['units']), number_format($per_order, 1) . ' per order · ' . $totals['item_types'] . ' different items') ?>
    <?= stat_tile('receipt', 'Orders sold', number_format((int) $totals['orders']), 'Rs. ' . money($average) . ' average per order') ?>
    <?= stat_tile('alert', 'Cancelled or returned', number_format($lost_rate, 1) . '%', $lost . ' of ' . $all_orders . ' orders') ?>
</dl>

<?php if (!$trend): ?>
    <section class="card"><?= empty_state('No sales in this period.') ?></section>
<?php else: ?>
    <section class="card chart-card">
        <h2>Product sales by month</h2>
        <p class="chart-sub">Best month: <?= h($best['label']) ?>, Rs. <?= money($best['revenue']) ?></p>
        <div id="trend" class="trend" tabindex="0" role="img" aria-label="Line chart of product sales by month. The figures are in the table below it."></div>
        <details class="chart-table">
            <summary>View as a table</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Month</th><th class="num">Product sales</th><th class="num">Orders</th><th class="num">Units</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($trend) as $row): ?>
                        <tr>
                            <td><?= h($row['label']) ?></td>
                            <td class="num"><?= money($row['revenue']) ?></td>
                            <td class="num"><?= $row['orders'] ?></td>
                            <td class="num"><?= $row['units'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <script type="application/json" id="trend-data"><?= json_encode($trend, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </section>

    <div class="chart-grid">
        <section class="card chart-card">
            <h2>Top items</h2>
            <p class="chart-sub">By product sales</p>
            <?= rank_bars(array_map(fn($row) => [
                $row['code'], $row['description'], (float) $row['revenue'], 'Rs. ' . number_format((float) $row['revenue']),
                $row['units'] . ' units sold',
            ], $top_items)) ?>
        </section>

        <section class="card chart-card">
            <h2>Sales by province</h2>
            <p class="chart-sub">By product sales</p>
            <?= rank_bars(array_map(fn($row) => [
                $row['province'], '', (float) $row['revenue'], 'Rs. ' . number_format((float) $row['revenue']),
                $row['orders'] . ' orders',
            ], $provinces)) ?>
        </section>

        <section class="card chart-card">
            <h2>Where orders come from</h2>
            <p class="chart-sub">Orders sold, by referral type</p>
            <?= rank_bars(array_map(fn($row) => [
                $row['referral_type'], '', (float) $row['orders'], number_format((int) $row['orders']),
                'Rs. ' . number_format((float) $row['revenue']) . ' in sales',
            ], $referrals)) ?>
        </section>

        <section class="card chart-card">
            <h2>Order status</h2>
            <p class="chart-sub">All orders in this period</p>
            <?= rank_bars(array_map(fn($status) => [
                $status, '', (float) ($statuses[$status] ?? 0), number_format((int) ($statuses[$status] ?? 0)),
                ($all_orders > 0 ? number_format(($statuses[$status] ?? 0) / $all_orders * 100, 1) : '0') . '% of orders',
            ], STATUSES)) ?>
        </section>
    </div>
<?php endif; ?>

<h2 class="section-title">Stock today <small>Not affected by the period above</small></h2>
<dl class="stats stats-flat stats-2">
    <?= stat_tile('box', 'Units in stock', number_format($units_in_stock)) ?>
    <?= stat_tile('tag', 'Stock value at cost', 'Rs. ' . money($stock_value)) ?>
</dl>

<section class="card card-table">
    <div class="card-head">
        <h2>Running low</h2>
        <a href="items.php">View all items</a>
    </div>
    <?php if (!$reorder): ?>
        <?= empty_state('Nothing is running low.') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Code</th><th>Description</th><th class="num">Left</th><th>Stock</th></tr></thead>
            <tbody>
            <?php foreach ($reorder as $item): ?>
                <tr>
                    <td class="strong nowrap"><?= h($item['code']) ?></td>
                    <td><?= h($item['description']) ?></td>
                    <td class="num<?= $item['left'] < 0 ? ' negative' : '' ?>"><?= $item['left'] ?></td>
                    <td><span class="badge badge-<?= h($item['state']) ?>"><?= icon($item['state_icon']) ?><?= h($item['state_label']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<div id="chart-tip" class="chart-tip" hidden></div>
<script src="assets/dashboard.js" defer></script>
<?php layout_bottom();
