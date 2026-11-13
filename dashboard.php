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

/** SQL conditions limiting s.date to a period; a blank end is open. */
function period_sql(string $from, string $to): array
{
    $sql    = '';
    $params = [];
    if ($from !== '') {
        $sql     .= ' AND s.date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $sql     .= ' AND s.date <= ?';
        $params[] = $to;
    }
    return [$sql, $params];
}
[$date_sql, $date_params] = period_sql($from, $to);

// The period the headline figures are compared with. "All time" has nothing before it.
$shift_year = fn(string $date) => date('Y-m-d', strtotime($date . ' -1 year'));
$today      = date('Y-m-d');
$compare    = null;
if ($range === '12m') {
    $compare = [$shift_year($from), date('Y-m-d', strtotime($from . ' -1 day')), 'vs the 12 months before'];
} elseif ($range === 'fy') {
    $compare = [$shift_year($from), $shift_year(min($to, $today)), 'vs same time last financial year'];
} elseif ($range === 'year') {
    $compare = [$shift_year($from), $shift_year($today), 'vs same time last year'];
} elseif ($range === 'last_year') {
    $compare = [$shift_year($from), $shift_year($to), 'vs the year before'];
} elseif ($range === 'custom' && $from !== '' && $to !== '') {
    $days    = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $compare = [date('Y-m-d', strtotime("$from -$days days")), date('Y-m-d', strtotime($from . ' -1 day')),
                "vs the $days days before"];
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

$cost    = 'SUM(si.qty * COALESCE(si.unit_cost, i.cost_price))';

/** Headline totals for a period: sales, cost, units, orders, and the share cancelled or returned. */
function period_totals(string $date_sql, array $date_params, string $revenue, string $cost): array
{
    $totals = dashboard_query("SELECT COALESCE($revenue, 0) AS revenue, COALESCE($cost, 0) AS cost,
                                      COALESCE(SUM(si.qty), 0) AS units, COUNT(DISTINCT s.id) AS orders,
                                      COUNT(DISTINCT si.item_id) AS item_types
                               FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                               WHERE s.status NOT IN " . NOT_SOLD_SQL . $date_sql, $date_params)[0];
    $orders = dashboard_query("SELECT COUNT(*) AS orders, COALESCE(SUM(s.status IN " . NOT_SOLD_SQL . "), 0) AS lost
                               FROM sales s WHERE 1 = 1 $date_sql", $date_params)[0];
    $totals['profit']    = $totals['revenue'] - $totals['cost'];
    $totals['lost_rate'] = $orders['orders'] > 0 ? $orders['lost'] / $orders['orders'] * 100 : null;
    return $totals;
}
$totals   = period_totals($date_sql, $date_params, $revenue, $cost);
$previous = $compare ? period_totals(...[...period_sql($compare[0], $compare[1]), $revenue, $cost]) : null;
$profit  = $totals['profit'];
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
    dashboard_query("SELECT DATE_FORMAT(s.date, '%Y-%m') AS month, $revenue AS revenue, $cost AS cost,
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
        $row     = $monthly[$month] ?? ['revenue' => 0, 'cost' => 0, 'orders' => 0, 'units' => 0];
        $trend[] = [
            'label'   => date('M Y', strtotime($month . '-01')),
            'revenue' => (float) $row['revenue'],
            'profit'  => round((float) $row['revenue'] - (float) $row['cost'], 2),
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

// Purchase cost per supplier: stock bought in the period, at the price paid.
$stock_date_sql = str_replace('s.date', 'st.date', $date_sql);
// Each supplier lists the items bought from them, most units first, such as "PHL 334 · SHLP-C2 6".
$suppliers = dashboard_query("SELECT supplier, SUM(cost) AS cost, SUM(units) AS units, SUM(entries) AS entries,
                                     GROUP_CONCAT(CONCAT(code, ' ', units) ORDER BY units DESC, code SEPARATOR ' · ') AS items
                              FROM (SELECT st.supplier, i.code, SUM(st.total_price) AS cost, SUM(st.qty) AS units, COUNT(*) AS entries
                                    FROM stock st JOIN items i ON i.id = st.item_id
                                    WHERE st.unit_price IS NOT NULL $stock_date_sql
                                    GROUP BY st.supplier, i.id, i.code) per_item
                              GROUP BY supplier ORDER BY supplier = '', cost DESC", $date_params);
$unrecorded = array_filter($suppliers, fn($row) => $row['supplier'] === '');
$purchase_total = array_sum(array_column($suppliers, 'cost'));

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

/**
 * The change from the comparison period, as "▲ 12.4%" (or percentage points when $points).
 * Green when the change is good, red when bad; the arrow and sign carry it without colour.
 */
function delta(?float $now, ?float $before, bool $up_is_good = true, bool $points = false): array
{
    if ($now === null || $before === null || (!$points && abs($before) < 0.005)) {
        return [];
    }
    $change = $points ? $now - $before : ($now - $before) / abs($before) * 100;
    if (abs($change) < 0.05) {
        return ['text' => '0.0' . ($points ? ' pts' : '%'), 'class' => 'flat'];
    }
    $good = ($change > 0) === $up_is_good;
    return ['text' => ($change > 0 ? '▲ +' : '▼ ') . number_format($change, 1) . ($points ? ' pts' : '%'), 'class' => $good ? 'good' : 'bad'];
}

/** A 12-month sparkline: grey line, the latest month marked in the accent colour. */
function sparkline(array $values): string
{
    $values = array_slice($values, -12);
    if (count($values) < 2) {
        return '';
    }
    [$w, $h, $pad] = [96, 32, 4];
    $low  = min(0, min($values));
    $high = max($values);
    $span = $high - $low ?: 1;
    $points = [];
    foreach ($values as $i => $value) {
        $points[] = sprintf('%.1f,%.1f', $pad + $i / (count($values) - 1) * ($w - 2 * $pad), $h - $pad - ($value - $low) / $span * ($h - 2 * $pad));
    }
    [$x, $y] = explode(',', end($points));
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true">'
        . '<polyline points="' . implode(' ', $points) . '"/><circle cx="' . $x . '" cy="' . $y . '" r="3"/></svg>';
}

/** A headline figure with its note, its change from the comparison period, and a trend line. */
function kpi_tile(string $icon, string $label, string $value, string $note, array $delta, string $against, string $spark): string
{
    $change = $delta ? '<dd class="stat-delta stat-delta-' . $delta['class'] . '">' . h($delta['text'])
        . ' <span>' . h($against) . '</span></dd>' : '';
    return '<div class="stat stat-kpi"><span class="stat-icon">' . icon($icon) . '</span><div class="stat-body"><dt>' . h($label)
        . '</dt><dd class="stat-value">' . h($value) . '</dd>' . $change . ($note !== '' ? '<dd class="stat-note">' . h($note) . '</dd>' : '')
        . '</div>' . $spark . '</div>';
}

/** Order statuses as one split bar, then a row per status with its icon, count and share. */
function status_split(array $counts, int $total): string
{
    // Delivered first, then orders still on the way, then the ones lost. This order also keeps
    // the green and red segments apart so they stay distinct for colour-blind readers.
    $order = [
        'Delivered' => ['check', 'good'], 'In progress' => ['clock', 'progress'], 'Rescheduled' => ['calendar', 'warning'],
        'Handed to Delivery' => ['truck', 'transit'], 'Cancelled' => ['x', 'critical'], 'Returned' => ['return', 'serious'],
    ];
    $bar  = '';
    $rows = '';
    foreach ($order as $status => [$symbol, $tone]) {
        $count = (int) ($counts[$status] ?? 0);
        $share = $total > 0 ? $count / $total * 100 : 0;
        $tip   = ' data-tip-title="' . h($status) . '" data-tip-value="' . number_format($count) . ' orders" data-tip-detail="'
            . number_format($share, 1) . '% of all orders"';
        if ($count > 0) {
            $bar .= '<span class="split-part tone-' . $tone . '" style="flex-grow: ' . $count . '" tabindex="0"' . $tip . '></span>';
        }
        $rows .= '<li class="' . ($count ? '' : 'is-zero') . '"><span class="status-icon tone-' . $tone . '">' . icon($symbol) . '</span>'
            . '<span>' . h($status) . '</span><span class="amount">' . number_format($count) . '</span>'
            . '<span class="share">' . number_format($share, 1) . '%</span></li>';
    }
    return '<div class="split-bar" role="img" aria-label="Orders by status. The figures are listed below.">' . $bar . '</div>'
        . '<ul class="status-list">' . $rows . '</ul>';
}

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

/**
 * A pie of shares, drawn as SVG. Each row is [label, amount, tooltip detail], largest first.
 * Past six parts the smallest are grouped as one grey "Other" slice; the legend still lists
 * every part with its amount and share, so no figure is hidden.
 */
function pie_chart(array $rows, string $other_label, string $caption): string
{
    $total = array_sum(array_column($rows, 1));
    if ($total <= 0) {
        return empty_state('No sales in this period.');
    }
    $slices = array_slice($rows, 0, count($rows) > 6 ? 5 : 6);
    $folded = array_slice($rows, count($slices));
    if ($folded) {
        $slices[] = [$other_label, array_sum(array_column($folded, 1)), count($folded) . ' provinces'];
    }

    $rupees = fn(float $amount) => 'Rs. ' . number_format($amount);
    $share  = fn(float $amount) => number_format($amount / $total * 100, 1) . '%';
    $svg    = '';
    $legend = '';
    $angle  = -M_PI / 2;   // start at 12 o'clock, clockwise
    foreach ($slices as $index => [$label, $amount, $detail]) {
        $colour = $folded && $index === count($slices) - 1 ? 'var(--series-other)' : 'var(--series-' . ($index + 1) . ')';
        $sweep  = $amount / $total * 2 * M_PI;
        $tip    = ' tabindex="0" data-tip-title="' . h($label) . '" data-tip-value="' . h($rupees($amount)) . '" data-tip-detail="'
            . h($share($amount) . ' of sales · ' . $detail) . '"';
        if ($sweep >= 2 * M_PI - 0.0001) {
            $svg .= '<circle class="pie-slice" cx="100" cy="100" r="96" fill="' . $colour . '"' . $tip . '/>';
        } else {
            $point = fn(float $at, float $r) => sprintf('%.2f %.2f', 100 + $r * cos($at), 100 + $r * sin($at));
            $svg  .= '<path class="pie-slice" fill="' . $colour . '"' . $tip . ' d="M100 100 L' . $point($angle, 96)
                . ' A96 96 0 ' . ($sweep > M_PI ? 1 : 0) . ' 1 ' . $point($angle + $sweep, 96) . ' Z"/>';
        }
        if ($amount / $total >= 0.06) {   // label only slices wide enough to hold it
            $middle = $angle + $sweep / 2;
            $dark   = in_array($colour, ['var(--series-4)', 'var(--series-other)'], true) ? ' pie-label-dark' : '';
            $svg   .= sprintf('<text class="pie-label%s" x="%.2f" y="%.2f" text-anchor="middle" dominant-baseline="central">%s</text>',
                $dark, 100 + 62 * cos($middle), 100 + 62 * sin($middle), round($amount / $total * 100) . '%');
        }
        $angle  += $sweep;
        $legend .= '<li><span class="swatch" style="background: ' . $colour . '"></span><span>' . h($label) . '</span>'
            . '<span class="amount">' . h($rupees($amount)) . '</span><span class="share">' . $share($amount) . '</span></li>';
    }
    foreach ($folded as [$label, $amount]) {
        $legend .= '<li class="pie-folded"><span></span><span>' . h($label) . '</span>'
            . '<span class="amount">' . h($rupees($amount)) . '</span><span class="share">' . $share($amount) . '</span></li>';
    }
    return '<div class="pie-wrap"><svg class="pie" viewBox="0 0 200 200" role="img" aria-label="' . h($caption) . '">' . $svg . '</svg>'
        . '<ul class="pie-legend">' . $legend . '</ul></div>';
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

<?php
$against = $compare[2] ?? '';
$was     = fn(string $key) => $previous ? (float) $previous[$key] : null;
$series  = fn(string $key) => array_column($trend, $key);
?>
<dl class="stats stats-flat stats-5">
    <?= kpi_tile('sales', 'Product sales', 'Rs. ' . money($totals['revenue']), 'Excludes delivery, cancelled and returned',
        delta((float) $totals['revenue'], $was('revenue')), $against, sparkline($series('revenue'))) ?>
    <?= kpi_tile('wallet', 'Estimated profit', 'Rs. ' . money($profit), number_format($margin, 1) . '% of sales, before expenses',
        delta((float) $profit, $was('profit')), $against, sparkline($series('profit'))) ?>
    <?= kpi_tile('tag', 'Items sold', number_format((int) $totals['units']), number_format($per_order, 1) . ' per order · ' . $totals['item_types'] . ' different items',
        delta((float) $totals['units'], $was('units')), $against, sparkline($series('units'))) ?>
    <?= kpi_tile('receipt', 'Orders sold', number_format((int) $totals['orders']), 'Rs. ' . money($average) . ' average per order',
        delta((float) $totals['orders'], $was('orders')), $against, sparkline($series('orders'))) ?>
    <?= kpi_tile('alert', 'Cancelled or returned', number_format($lost_rate, 1) . '%', $lost . ' of ' . $all_orders . ' orders',
        delta($totals['lost_rate'], $previous['lost_rate'] ?? null, false, true), $against, '') ?>
</dl>

<?php if (!$trend): ?>
    <section class="card"><?= empty_state('No sales in this period.') ?></section>
<?php else: ?>
    <section class="card chart-card">
        <h2>Product sales by month</h2>
        <p class="chart-sub">Best month: <?= h($best['label']) ?>, Rs. <?= money($best['revenue']) ?></p>
        <ul class="legend" aria-hidden="true">
            <li><span class="legend-line series-sales"></span>Product sales</li>
            <li><span class="legend-line series-profit"></span>Estimated profit</li>
        </ul>
        <div id="trend" class="trend" tabindex="0" role="img" aria-label="Line chart of product sales and estimated profit by month. The figures are in the table below it."></div>
        <details class="chart-table">
            <summary>View as a table</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Month</th><th class="num">Product sales</th><th class="num">Estimated profit</th><th class="num">Orders</th><th class="num">Units</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($trend) as $row): ?>
                        <tr>
                            <td><?= h($row['label']) ?></td>
                            <td class="num"><?= money($row['revenue']) ?></td>
                            <td class="num<?= $row['profit'] < 0 ? ' negative' : '' ?>"><?= money($row['profit']) ?></td>
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
            <p class="chart-sub">Share of product sales</p>
            <?= pie_chart(array_map(fn($row) => [$row['province'], (float) $row['revenue'], $row['orders'] . ' orders'], $provinces),
                'Other provinces', 'Pie chart of product sales by province. The figures are listed beside it.') ?>
        </section>

        <section class="card chart-card">
            <h2>Where orders come from</h2>
            <p class="chart-sub">Orders sold, by referral type</p>
            <?php $referred = array_sum(array_column($referrals, 'orders')); ?>
            <?= rank_bars(array_map(fn($row) => [
                $row['referral_type'], number_format($referred ? $row['orders'] / $referred * 100 : 0, 1) . '% of orders',
                (float) $row['orders'], number_format((int) $row['orders']),
                'Rs. ' . number_format((float) $row['revenue']) . ' in sales',
            ], $referrals)) ?>
        </section>

        <section class="card chart-card">
            <h2>Order status</h2>
            <p class="chart-sub">All <?= number_format($all_orders) ?> orders in this period</p>
            <?= status_split($statuses, $all_orders) ?>
        </section>
    </div>
<?php endif; ?>

<section class="card chart-card supplier-card">
    <div class="card-head">
        <h2>Purchases by supplier</h2>
        <a href="stock.php">View stock entries</a>
    </div>
    <?php if (!$suppliers): ?>
        <?= empty_state('No stock bought in this period.') ?>
    <?php else: ?>
    <p class="chart-sub">Purchase cost in this period, from the Stock page: Rs. <?= money($purchase_total) ?> in total</p>
    <?= rank_bars(array_map(fn($row) => [
        $row['supplier'] !== '' ? $row['supplier'] : 'Supplier not recorded',
        number_format((int) $row['units']) . ' units: ' . $row['items'],
        (float) $row['cost'], 'Rs. ' . number_format((float) $row['cost']),
        ($purchase_total > 0 ? number_format($row['cost'] / $purchase_total * 100, 1) : '0') . '% of purchase cost · '
            . $row['entries'] . ($row['entries'] == 1 ? ' stock entry' : ' stock entries'),
    ], $suppliers)) ?>
    <?php if ($unrecorded): ?>
        <p class="chart-foot"><a href="stock.php?supplier=none">See the stock entries with no supplier</a> and add one to each.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

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
<script src="<?= asset('dashboard.js') ?>" defer></script>
<?php layout_bottom();
