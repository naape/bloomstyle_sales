<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$accounts = account_balances();
$selected = (int) get('account') ?: (int) $accounts[0]['id'];
$account  = array_column($accounts, null, 'id')[$selected] ?? $accounts[0];
$selected = (int) $account['id'];
$sign     = debit_normal($account['type']) ? 1 : -1;

[$key, $from, $to] = report_period('all');

$opening = 0.0;
if ($from !== null) {
    $before = db()->prepare('SELECT COALESCE(SUM(debit - credit), 0) FROM ledger WHERE account_id = ? AND date < ?');
    $before->execute([$selected, $from]);
    $opening = $sign * (float) $before->fetchColumn();
}

$where  = 'account_id = ?';
$params = [$selected];
if ($from !== null) {
    $where   .= ' AND date >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $where   .= ' AND date <= ?';
    $params[] = $to;
}
$query = db()->prepare("SELECT * FROM ledger WHERE $where ORDER BY date, source, source_id");
$query->execute($params);

$running = $opening;
$lines   = [];
$debits  = 0.0;
$credits = 0.0;
foreach ($query as $line) {
    $debits   += (float) $line['debit'];
    $credits  += (float) $line['credit'];
    $running  += $sign * ((float) $line['debit'] - (float) $line['credit']);
    $line['running'] = $running;
    $lines[]  = $line;
}
$closing = $running;
$lines   = array_reverse($lines);
[$page, $pages, $offset] = paginate(count($lines));
$shown   = array_slice($lines, $offset, PER_PAGE);

$source_link = fn(array $line) => match ($line['source']) {
    'sale'  => 'sale_form.php?id=' . (int) $line['source_id'],
    'stock' => 'stock.php?edit=' . (int) $line['source_id'],
    default => 'accounts_entry.php?id=' . (int) $line['source_id'],
};

layout_top('General ledger', 'accounts');
hero('General ledger', 'Every movement in an account, newest first.', '', true);
accounts_nav('ledger');
$keep = ['account' => $selected];
period_filter($key, $from, $to, $keep);
?>
<form method="get" class="ledger-picker no-print">
    <label>Account
        <select name="account" data-autosubmit><?= account_options($accounts, $selected) ?></select>
    </label>
    <?php foreach (['range', 'from', 'to'] as $name): ?>
        <?php if (get($name) !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= h(get($name)) ?>"><?php endif; ?>
    <?php endforeach; ?>
</form>

<dl class="stats stats-flat">
    <?= stat_tile('wallet', 'Opening balance', 'Rs. ' . money($opening)) ?>
    <?= stat_tile('plus', 'Debits', 'Rs. ' . money($debits)) ?>
    <?= stat_tile('receipt', 'Credits', 'Rs. ' . money($credits)) ?>
    <?= stat_tile('sales', 'Closing balance', 'Rs. ' . money($closing), period_label($from, $to)) ?>
</dl>

<section class="card card-table" data-closing="<?= h(number_format($closing, 2, '.', '')) ?>">
    <?php if (!$shown): ?>
        <?= empty_state('No movements in this period.') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Reference</th><th>Details</th><th>From</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($shown as $line): ?>
                <tr>
                    <td class="nowrap"><?= h(fmt_date($line['date'])) ?></td>
                    <td class="nowrap"><a href="<?= h($source_link($line)) ?>"><?= h($line['reference'] !== '' ? $line['reference'] : 'View') ?></a></td>
                    <td><?= h($line['memo']) ?></td>
                    <td><?= h(['sale' => 'Sale', 'stock' => 'Stock', 'journal' => 'Entry'][$line['source']]) ?></td>
                    <td class="num"><?= (float) $line['debit'] ? money($line['debit']) : '' ?></td>
                    <td class="num"><?= (float) $line['credit'] ? money($line['credit']) : '' ?></td>
                    <td class="num strong"><?= money($line['running']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, array_filter(['account' => $selected, 'range' => get('range'), 'from' => get('from'), 'to' => get('to')])) ?>
    <?php endif; ?>
</section>
<?php layout_bottom();
