<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$today    = date('Y-m-d');
$to_date  = account_balances(null, $today);
[$key, $from, $to] = report_period('fy');
$period   = account_balances($from, $to);

$by_code  = array_column($to_date, null, 'code');
$cash     = array_values(array_filter($to_date, fn($account) => (bool) $account['is_cash']));
$income   = balance_total(accounts_of_type($period, 'Income'));
$expenses = balance_total(accounts_of_type($period, 'Expense'));

$entries  = db()->query('SELECT e.*, (SELECT SUM(l.debit) FROM journal_lines l WHERE l.entry_id = e.id) AS amount
                         FROM journal_entries e ORDER BY e.date DESC, e.id DESC LIMIT 6')->fetchAll();
$counts   = array_column(db()->query('SELECT kind, COUNT(*) AS entries FROM journal_entries GROUP BY kind')->fetchAll(), 'entries', 'kind');

$notices = [];
if (empty($counts['capital']) && empty($counts['journal'])) {
    $notices[] = ['Add your opening money.', 'Record what you put into the business, so that Cash and Bank starts from the right amount.', 'accounts_entry.php?type=capital', 'Record owner money in'];
}
if (empty($counts['expense'])) {
    $notices[] = ['No expenses are recorded yet.', 'Until courier fees, advertising, rent and similar costs are added, profit here is higher than your real profit.', 'accounts_entry.php?type=expense', 'Record an expense'];
}

layout_top('Accounts', 'accounts');
hero('Accounts', 'Your books, kept in step with sales and stock.',
    '<a class="btn btn-hero" href="accounts_entry.php?type=expense">' . icon('plus') . 'Record expense</a>', true);
accounts_nav('overview');
period_filter($key, $from, $to);
?>
<dl class="stats stats-flat">
    <?= stat_tile('wallet', 'Cash and bank today', 'Rs. ' . money(balance_total($cash))) ?>
    <?= stat_tile('receipt', 'Customers owe you', 'Rs. ' . money($by_code['1100']['balance'])) ?>
    <?= stat_tile('box', 'Stock at cost', 'Rs. ' . money($by_code['1200']['balance'])) ?>
    <?= stat_tile('sales', 'Net profit', 'Rs. ' . money($income - $expenses), period_label($from, $to)) ?>
</dl>

<?php foreach ($notices as [$title, $text, $href, $action]): ?>
    <div class="notice no-print">
        <div><strong><?= h($title) ?></strong> <?= h($text) ?></div>
        <a class="btn btn-quiet" href="<?= h($href) ?>"><?= h($action) ?></a>
    </div>
<?php endforeach; ?>

<div class="chart-grid">
    <section class="card">
        <h2>Record money</h2>
        <div class="quick-actions">
            <?php foreach (ENTRY_TYPES as $type => [$label]): ?>
                <a class="btn btn-quiet" href="accounts_entry.php?type=<?= $type ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
            <a class="btn btn-quiet" href="accounts_entry.php?type=journal">Journal entry</a>
        </div>
        <h2 class="spaced">Cash and bank accounts</h2>
        <table class="report">
            <tbody>
            <?php foreach ($cash as $account): ?>
                <tr>
                    <td><a href="accounts_ledger.php?account=<?= (int) $account['id'] ?>"><?= h($account['name']) ?></a></td>
                    <td class="num strong"><?= money($account['balance']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card card-table">
        <div class="card-head">
            <h2>Latest entries</h2>
            <a href="accounts_journal.php">View all</a>
        </div>
        <?php if (!$entries): ?>
            <?= empty_state('Nothing recorded yet. Sales and stock post automatically; expenses are added here.') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="nowrap"><?= h(fmt_date($entry['date'])) ?></td>
                        <td><?= h($entry['memo'] !== '' ? $entry['memo'] : (ENTRY_TYPES[$entry['kind']][0] ?? 'Journal entry')) ?></td>
                        <td class="num strong"><?= money($entry['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php layout_bottom();
