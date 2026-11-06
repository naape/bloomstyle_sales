<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$report = in_array(get('report'), ['pl', 'bs', 'tb'], true) ? get('report') : 'pl';
$titles = ['pl' => 'Profit and loss', 'bs' => 'Balance sheet', 'tb' => 'Trial balance'];
$as_of  = valid_date(get('date')) ? get('date') : date('Y-m-d');

function report_lines(array $accounts): void
{
    foreach ($accounts as $account) {
        if (abs((float) $account['balance']) < 0.005) {
            continue;
        }
        echo '<tr><td><a href="accounts_ledger.php?account=' . (int) $account['id'] . '">' . h($account['code'] . ' ' . $account['name']) . '</a></td>'
            . '<td class="num">' . money($account['balance']) . '</td></tr>';
    }
}

function report_total(string $label, float $amount, string $class = ''): void
{
    echo '<tr class="report-total ' . $class . '"><td>' . h($label) . '</td><td class="num">' . money($amount) . '</td></tr>';
}

function report_heading(string $label): void
{
    echo '<tr class="report-heading"><td colspan="2">' . h($label) . '</td></tr>';
}

layout_top($titles[$report], 'accounts');
hero($titles[$report], 'Bloom Style', '<button class="btn btn-hero no-print" type="button" data-print>Print</button>', true);
accounts_nav($report);

if ($report === 'pl') {
    [$key, $from, $to] = report_period('fy');
    period_filter($key, $from, $to, ['report' => 'pl']);
    $accounts = account_balances($from, $to);
    $income   = accounts_of_type($accounts, 'Income');
    $expenses = accounts_of_type($accounts, 'Expense');
    $cost     = array_values(array_filter($expenses, fn($account) => str_starts_with($account['code'], '5')));
    $running  = array_values(array_filter($expenses, fn($account) => !str_starts_with($account['code'], '5')));
    $revenue  = balance_total($income);
    $gross    = round($revenue - balance_total($cost), 2);
    $net      = round($gross - balance_total($running), 2);
    ?>
    <section class="card report-card" data-net="<?= h(number_format($net, 2, '.', '')) ?>" data-gross="<?= h(number_format($gross, 2, '.', '')) ?>">
        <p class="report-period"><?= h(period_label($from, $to)) ?></p>
        <table class="report">
            <tbody>
            <?php
            report_heading('Income');
            report_lines($income);
            report_total('Total income', $revenue);
            report_heading('Cost of sales');
            report_lines($cost);
            report_total('Gross profit', $gross, 'report-key');
            report_heading('Expenses');
            report_lines($running);
            report_total('Total expenses', balance_total($running));
            report_total($net < 0 ? 'Net loss' : 'Net profit', $net, 'report-key');
            ?>
            </tbody>
        </table>
    </section>
    <?php
} else {
    $accounts = account_balances(null, $as_of);
    ?>
    <form method="get" class="ledger-picker no-print">
        <input type="hidden" name="report" value="<?= h($report) ?>">
        <label>As of
            <input type="date" name="date" value="<?= h($as_of) ?>">
        </label>
        <button class="btn btn-quiet">Apply</button>
    </form>
    <?php if ($report === 'bs'):
        $assets      = accounts_of_type($accounts, 'Asset');
        $liabilities = accounts_of_type($accounts, 'Liability');
        $equity      = accounts_of_type($accounts, 'Equity');
        $earnings    = round(balance_total(accounts_of_type($accounts, 'Income')) - balance_total(accounts_of_type($accounts, 'Expense')), 2);
        $year_start  = fy_range(fy_start_year($as_of))[0];
        $earlier     = account_balances(null, date('Y-m-d', strtotime("$year_start -1 day")));
        $retained    = round(balance_total(accounts_of_type($earlier, 'Income')) - balance_total(accounts_of_type($earlier, 'Expense')), 2);
        $total_assets = balance_total($assets);
        $total_claims = round(balance_total($liabilities) + balance_total($equity) + $earnings, 2);
        $balanced     = abs($total_assets - $total_claims) < 0.005;
    ?>
        <section class="card report-card" data-balanced="<?= $balanced ? 'yes' : 'no' ?>" data-assets="<?= h(number_format($total_assets, 2, '.', '')) ?>">
            <p class="report-period">As of <?= h(fmt_date($as_of)) ?></p>
            <table class="report">
                <tbody>
                <?php
                report_heading('Assets');
                report_lines($assets);
                report_total('Total assets', $total_assets, 'report-key');
                report_heading('Liabilities');
                report_lines($liabilities);
                report_total('Total liabilities', balance_total($liabilities));
                report_heading('Equity');
                report_lines($equity);
                ?>
                <tr><td>Retained earnings from earlier years</td><td class="num" data-retained="<?= h(number_format($retained, 2, '.', '')) ?>"><?= money($retained) ?></td></tr>
                <tr><td>Profit for financial year <?= h(fy_label(fy_start_year($as_of))) ?> to date</td><td class="num" data-current="<?= h(number_format($earnings - $retained, 2, '.', '')) ?>"><?= money($earnings - $retained) ?></td></tr>
                <?php
                report_total('Total equity', round(balance_total($equity) + $earnings, 2));
                report_total('Total liabilities and equity', $total_claims, 'report-key');
                ?>
                </tbody>
            </table>
            <p class="report-check <?= $balanced ? 'ok' : 'bad' ?>"><?= $balanced ? 'Balanced: assets equal liabilities plus equity.' : 'Out of balance. Please check your entries.' ?></p>
        </section>
    <?php else:
        $debit_total = 0.0;
        $credit_total = 0.0;
    ?>
        <section class="card report-card">
            <p class="report-period">As of <?= h(fmt_date($as_of)) ?></p>
            <table class="report report-3">
                <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account):
                    $net = round($account['debit'] - $account['credit'], 2);
                    if (abs($net) < 0.005) {
                        continue;
                    }
                    $debit_total  += max($net, 0);
                    $credit_total += max(-$net, 0);
                ?>
                    <tr>
                        <td><a href="accounts_ledger.php?account=<?= (int) $account['id'] ?>"><?= h($account['code'] . ' ' . $account['name']) ?></a></td>
                        <td class="num"><?= $net > 0 ? money($net) : '' ?></td>
                        <td class="num"><?= $net < 0 ? money(-$net) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="report-total report-key" data-debit="<?= h(number_format($debit_total, 2, '.', '')) ?>" data-credit="<?= h(number_format($credit_total, 2, '.', '')) ?>">
                    <td>Total</td><td class="num"><?= money($debit_total) ?></td><td class="num"><?= money($credit_total) ?></td>
                </tr>
                </tbody>
            </table>
        </section>
    <?php endif;
}
?>
<script src="assets/accounts.js" defer></script>
<?php layout_bottom();
