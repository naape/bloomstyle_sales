<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    db()->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([(int) post('id')]);
    flash('Entry deleted.');
    redirect('accounts_journal.php');
}

[$key, $from, $to] = report_period('all');
$where  = '1 = 1';
$params = [];
if ($from !== null) {
    $where   .= ' AND e.date >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $where   .= ' AND e.date <= ?';
    $params[] = $to;
}

$count = db()->prepare("SELECT COUNT(*) FROM journal_entries e WHERE $where");
$count->execute($params);
[$page, $pages, $offset] = paginate((int) $count->fetchColumn());

$rows = db()->prepare("SELECT e.*,
        (SELECT SUM(l.debit) FROM journal_lines l WHERE l.entry_id = e.id) AS amount,
        (SELECT GROUP_CONCAT(DISTINCT a.name ORDER BY l.debit DESC SEPARATOR ' / ')
         FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE l.entry_id = e.id) AS account_names
    FROM journal_entries e WHERE $where ORDER BY e.date DESC, e.id DESC LIMIT " . PER_PAGE . " OFFSET $offset");
$rows->execute($params);
$rows = $rows->fetchAll();

layout_top('Transactions', 'accounts');
hero('Transactions', 'Expenses, income and other entries you record yourself.',
    '<a class="btn btn-hero" href="accounts_entry.php?type=expense">' . icon('plus') . 'New entry</a>', true);
accounts_nav('journal');
period_filter($key, $from, $to);
?>
<p class="page-note no-print">Sales and stock post automatically. You can see those in the <a href="accounts_ledger.php">General ledger</a>, and change them on the Sales and Stock pages.</p>
<section class="card card-table">
    <?php if (!$rows): ?>
        <?= empty_state('No entries in this period.') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Accounts</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="nowrap"><?= h(fmt_date($row['date'])) ?></td>
                    <td class="nowrap"><?= h(ENTRY_TYPES[$row['kind']][0] ?? 'Journal entry') ?></td>
                    <td><?= h($row['memo']) ?></td>
                    <td><small><?= h($row['account_names']) ?></small></td>
                    <td><?= h($row['reference']) ?></td>
                    <td class="num strong"><?= money($row['amount']) ?></td>
                    <td class="row-actions">
                        <a class="icon-link" href="accounts_entry.php?id=<?= (int) $row['id'] ?>" title="Edit" aria-label="Edit entry"><?= icon('edit') ?></a>
                        <form method="post" data-confirm="Delete this entry?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="icon-link danger" title="Delete" aria-label="Delete entry"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, array_filter(['range' => get('range'), 'from' => get('from'), 'to' => get('to')])) ?>
    <?php endif; ?>
</section>
<?php layout_bottom();
