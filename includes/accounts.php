<?php
declare(strict_types=1);

const ACCOUNT_TYPES = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];

/** Simple two-sided entries: label, debit field, debit choices, credit field, credit choices. */
const ENTRY_TYPES = [
    'expense'  => ['Expense', 'Expense account', 'Expense', 'Paid from', 'cash'],
    'income'   => ['Other income', 'Received into', 'cash', 'Income account', 'Income'],
    'transfer' => ['Transfer', 'Transfer to', 'cash', 'Transfer from', 'cash'],
    'capital'  => ['Owner money in', 'Received into', 'cash', 'Equity account', 'Equity'],
    'drawings' => ['Owner money out', 'Equity account', 'Equity', 'Paid from', 'cash'],
];

/** All accounts with debit, credit and balance for a period; null dates are open-ended. */
function account_balances(?string $from = null, ?string $to = null): array
{
    $join   = '';
    $params = [];
    if ($from !== null) {
        $join    .= ' AND l.date >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $join    .= ' AND l.date <= ?';
        $params[] = $to;
    }
    $query = db()->prepare("SELECT a.*, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit
                            FROM accounts a LEFT JOIN ledger l ON l.account_id = a.id $join
                            GROUP BY a.id ORDER BY a.code");
    $query->execute($params);
    $accounts = $query->fetchAll();
    foreach ($accounts as &$account) {
        $account['balance'] = debit_normal($account['type'])
            ? $account['debit'] - $account['credit']
            : $account['credit'] - $account['debit'];
    }
    return $accounts;
}

function debit_normal(string $type): bool
{
    return $type === 'Asset' || $type === 'Expense';
}

function accounts_of_type(array $accounts, string ...$types): array
{
    return array_values(array_filter($accounts, fn($account) => in_array($account['type'], $types, true)));
}

function balance_total(array $accounts): float
{
    return round(array_sum(array_column($accounts, 'balance')), 2);
}

/** The choices for one side of a simple entry. */
function entry_choices(array $accounts, string $kind): array
{
    return $kind === 'cash'
        ? array_values(array_filter($accounts, fn($account) => (bool) $account['is_cash']))
        : accounts_of_type($accounts, $kind);
}

function account_options(array $accounts, int $selected): string
{
    $html = '';
    foreach ($accounts as $account) {
        $html .= '<option value="' . (int) $account['id'] . '"' . ((int) $account['id'] === $selected ? ' selected' : '') . '>'
            . h($account['code'] . ' · ' . $account['name']) . '</option>';
    }
    return $html;
}

/** Saves a manual entry and its lines in one transaction. Returns the entry id. */
function save_entry(int $id, string $date, string $kind, string $reference, string $memo, array $lines): int
{
    db()->beginTransaction();
    if ($id) {
        db()->prepare('UPDATE journal_entries SET date = ?, kind = ?, reference = ?, memo = ? WHERE id = ?')
            ->execute([$date, $kind, $reference, $memo, $id]);
        db()->prepare('DELETE FROM journal_lines WHERE entry_id = ?')->execute([$id]);
    } else {
        db()->prepare('INSERT INTO journal_entries (date, kind, reference, memo, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$date, $kind, $reference, $memo, $_SESSION['username']]);
        $id = (int) db()->lastInsertId();
    }
    $add = db()->prepare('INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)');
    foreach ($lines as [$account_id, $debit, $credit]) {
        $add->execute([$id, $account_id, $debit, $credit]);
    }
    db()->commit();
    return $id;
}

/** The selected report period as [key, from, to]. Dates are null when open-ended. */
function report_period(string $default = 'fy'): array
{
    $from = valid_date(get('from')) ? get('from') : null;
    $to   = valid_date(get('to')) ? get('to') : null;
    if ($from !== null || $to !== null) {
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['custom', $from, $to];
    }

    if (preg_match('/^\d{4}$/', get('fy'))) {
        return ['fy-' . get('fy'), ...fy_range((int) get('fy'))];
    }

    $key     = array_key_exists(get('range'), period_presets()) ? get('range') : $default;
    $current = fy_start_year(date('Y-m-d'));
    return match ($key) {
        'month'   => [$key, date('Y-m-01'), date('Y-m-t')],
        'fy'      => [$key, ...fy_range($current)],
        'last_fy' => [$key, ...fy_range($current - 1)],
        default   => ['all', null, null],
    };
}

function period_presets(): array
{
    return ['month' => 'This month', 'fy' => 'This financial year', 'last_fy' => 'Last financial year', 'all' => 'All time'];
}

function period_filter(string $key, ?string $from, ?string $to, array $keep = []): void
{
    echo '<div class="period-bar no-print"><nav class="tabs" aria-label="Period">';
    foreach (period_presets() as $preset => $label) {
        echo '<a href="?' . h(http_build_query(['range' => $preset] + $keep)) . '"'
            . ($preset === $key ? ' class="active" aria-current="true"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav><form method="get" class="fy-picker">';
    foreach ($keep as $name => $value) {
        echo '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    echo '<label>Financial year <select name="fy" data-autosubmit><option value="">Select…</option>';
    $first = db()->query('SELECT MIN(date) FROM ledger')->fetchColumn() ?: date('Y-m-d');
    for ($year = fy_start_year(date('Y-m-d')); $year >= fy_start_year($first); $year--) {
        echo '<option value="' . $year . '"' . ($key === "fy-$year" ? ' selected' : '') . '>' . h(fy_label($year)) . '</option>';
    }
    echo '</select></label></form><form method="get" class="period-custom">';
    foreach ($keep as $name => $value) {
        echo '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
    echo '<label>From <input type="date" name="from" value="' . h($key === 'custom' ? (string) $from : '') . '"></label>'
        . '<label>To <input type="date" name="to" value="' . h($key === 'custom' ? (string) $to : '') . '"></label>'
        . '<button class="btn btn-quiet">Apply</button></form></div>';
}

function accounts_nav(string $active): void
{
    $links = [
        'overview' => ['accounts.php', 'Overview'],
        'journal'  => ['accounts_journal.php', 'Transactions'],
        'chart'    => ['accounts_chart.php', 'Chart of accounts'],
        'ledger'   => ['accounts_ledger.php', 'General ledger'],
        'pl'       => ['accounts_reports.php?report=pl', 'Profit and loss'],
        'bs'       => ['accounts_reports.php?report=bs', 'Balance sheet'],
        'tb'       => ['accounts_reports.php?report=tb', 'Trial balance'],
        'settings' => ['accounts_settings.php', 'Settings'],
    ];
    echo '<nav class="tabs subnav no-print" aria-label="Accounts">';
    foreach ($links as $key => [$href, $label]) {
        echo '<a href="' . $href . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav>';
}

function period_label(?string $from, ?string $to): string
{
    if ($from === null && $to === null) {
        return 'All time';
    }
    if ($from === null) {
        return 'Up to ' . fmt_date($to);
    }
    return $to === null ? 'From ' . fmt_date($from) : fmt_date($from) . ' to ' . fmt_date($to);
}
