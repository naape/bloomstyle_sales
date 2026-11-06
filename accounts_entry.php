<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$accounts = account_balances();
$known    = array_column($accounts, null, 'id');
$errors   = [];
$id       = (int) (get('id') !== '' ? get('id') : post('id'));
$kind     = array_key_exists(get('type'), ENTRY_TYPES) || get('type') === 'journal' ? get('type') : 'expense';

$form = ['date' => date('Y-m-d'), 'reference' => '', 'memo' => '', 'amount' => '', 'debit_account' => 0, 'credit_account' => 0];
$rows = [['account_id' => 0, 'debit' => '', 'credit' => ''], ['account_id' => 0, 'debit' => '', 'credit' => '']];

if ($id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $find = db()->prepare('SELECT * FROM journal_entries WHERE id = ?');
    $find->execute([$id]);
    $entry = $find->fetch();
    if (!$entry) {
        flash('That entry no longer exists.', 'error');
        redirect('accounts_journal.php');
    }
    $kind = $entry['kind'];
    $form = array_merge($form, array_intersect_key($entry, $form));
    $find = db()->prepare('SELECT account_id, debit, credit FROM journal_lines WHERE entry_id = ? ORDER BY id');
    $find->execute([$id]);
    $rows = $find->fetchAll();
    if ($kind !== 'journal') {
        foreach ($rows as $row) {
            if ($row['debit'] > 0) {
                $form['debit_account'] = (int) $row['account_id'];
                $form['amount']        = $row['debit'];
            } else {
                $form['credit_account'] = (int) $row['account_id'];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($id) {
        $find = db()->prepare('SELECT 1 FROM journal_entries WHERE id = ?');
        $find->execute([$id]);
        if (!$find->fetchColumn()) {
            flash('That entry no longer exists.', 'error');
            redirect('accounts_journal.php');
        }
    }
    $kind = array_key_exists(post('kind'), ENTRY_TYPES) || post('kind') === 'journal' ? post('kind') : 'expense';
    $form = array_merge($form, ['date' => post('date'), 'reference' => post('reference'), 'memo' => post('memo')]);

    if (!valid_date($form['date'])) {
        $errors[] = 'Enter a valid date.';
    }
    if (mb_strlen($form['reference']) > 50 || mb_strlen($form['memo']) > 255) {
        $errors[] = 'The reference or description is too long.';
    }

    $lines = [];
    if ($kind === 'journal') {
        $ids     = post_list('account_id');
        $debits  = post_list('debit');
        $credits = post_list('credit');
        $rows    = [];
        foreach ($ids as $index => $account_id) {
            $debit_text  = $debits[$index] ?? '';
            $credit_text = $credits[$index] ?? '';
            $rows[]      = ['account_id' => (int) $account_id, 'debit' => $debit_text, 'credit' => $credit_text];
            $debit       = $debit_text === '' ? 0.0 : parse_amount($debit_text);
            $credit      = $credit_text === '' ? 0.0 : parse_amount($credit_text);
            $line        = 'Line ' . ($index + 1);

            if (!isset($known[(int) $account_id])) {
                $errors[] = "$line: select an account.";
            }
            if ($debit === null || $credit === null) {
                $errors[] = "$line: enter a valid amount.";
            } elseif (($debit > 0) === ($credit > 0)) {
                $errors[] = "$line: enter either a debit or a credit.";
            } else {
                $lines[] = [(int) $account_id, $debit, $credit];
            }
        }
        if (count($rows) < 2) {
            $errors[] = 'A journal entry needs at least two lines.';
        }
        $total_debit  = round(array_sum(array_column($lines, 1)), 2);
        $total_credit = round(array_sum(array_column($lines, 2)), 2);
        if ($total_debit !== $total_credit) {
            $errors[] = 'Debits (' . money($total_debit) . ') must equal credits (' . money($total_credit) . ').';
        }
    } else {
        $form['debit_account']  = (int) post('debit_account');
        $form['credit_account'] = (int) post('credit_account');
        $form['amount']         = post('amount');
        $amount = parse_amount($form['amount']);
        [, , $debit_kind, , $credit_kind] = ENTRY_TYPES[$kind];

        if (!in_array($form['debit_account'], array_map('intval', array_column(entry_choices($accounts, $debit_kind), 'id')), true)
            || !in_array($form['credit_account'], array_map('intval', array_column(entry_choices($accounts, $credit_kind), 'id')), true)) {
            $errors[] = 'Select both accounts.';
        } elseif ($form['debit_account'] === $form['credit_account']) {
            $errors[] = 'Choose two different accounts.';
        }
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Enter an amount greater than 0.';
        } else {
            $lines = [[$form['debit_account'], $amount, 0], [$form['credit_account'], 0, $amount]];
        }
    }

    if (!$errors) {
        save_entry($id, $form['date'], $kind, $form['reference'], $form['memo'], $lines);
        flash($id ? 'Entry updated.' : 'Entry saved.');
        redirect('accounts_journal.php');
    }
}

$label = $kind === 'journal' ? 'Journal entry' : ENTRY_TYPES[$kind][0];
layout_top($label, 'accounts');
hero(($id ? 'Edit: ' : 'Record: ') . $label, 'Every entry keeps your books balanced.', '', true);
accounts_nav('journal');
?>
<?php if (!$id): ?>
    <nav class="tabs" aria-label="Entry type">
        <?php foreach (ENTRY_TYPES as $type => [$type_label]): ?>
            <a href="?type=<?= $type ?>"<?= $type === $kind ? ' class="active" aria-current="true"' : '' ?>><?= h($type_label) ?></a>
        <?php endforeach; ?>
        <a href="?type=journal"<?= $kind === 'journal' ? ' class="active" aria-current="true"' : '' ?>>Journal entry</a>
    </nav>
<?php endif; ?>

<section class="card entry-card">
    <?php show_errors($errors); ?>
    <form method="post"<?= $kind === 'journal' ? ' data-journal' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="kind" value="<?= h($kind) ?>">
        <div class="grid">
            <label>Date
                <input type="date" name="date" value="<?= h($form['date']) ?>" required>
            </label>
            <label>Reference
                <input name="reference" maxlength="50" value="<?= h($form['reference']) ?>" placeholder="Bill or receipt number">
            </label>
            <label>Description
                <input name="memo" maxlength="255" value="<?= h($form['memo']) ?>">
            </label>
        </div>

        <?php if ($kind === 'journal'):
            $journal_row = fn(array $row) => '<div class="journal-line"><label>Account<select name="account_id[]" required><option value="">Select…</option>'
                . account_options($accounts, (int) $row['account_id']) . '</select></label>'
                . '<label>Debit<input type="number" name="debit[]" min="0" step="0.01" value="' . h((float) $row['debit'] > 0 ? $row['debit'] : '') . '"></label>'
                . '<label>Credit<input type="number" name="credit[]" min="0" step="0.01" value="' . h((float) $row['credit'] > 0 ? $row['credit'] : '') . '"></label>'
                . '<button type="button" class="icon-link danger line-remove" title="Remove line" aria-label="Remove line">' . icon('trash') . '</button></div>';
        ?>
            <div id="journal-lines">
                <?php foreach ($rows as $row): ?><?= $journal_row($row) ?><?php endforeach; ?>
            </div>
            <template id="journal-template"><?= $journal_row(['account_id' => 0, 'debit' => '', 'credit' => '']) ?></template>
            <div class="line-footer">
                <button type="button" class="btn btn-quiet" id="add-journal-line"><?= icon('plus') ?>Add line</button>
                <p class="journal-totals">Debits <strong id="journal-debit">0.00</strong> · Credits <strong id="journal-credit">0.00</strong> · Difference <strong id="journal-difference">0.00</strong></p>
            </div>
        <?php else:
            [, $debit_label, $debit_kind, $credit_label, $credit_kind] = ENTRY_TYPES[$kind];
        ?>
            <div class="grid">
                <label><?= h($debit_label) ?>
                    <select name="debit_account" required>
                        <option value="">Select…</option>
                        <?= account_options(entry_choices($accounts, $debit_kind), (int) $form['debit_account']) ?>
                    </select>
                </label>
                <label><?= h($credit_label) ?>
                    <select name="credit_account" required>
                        <option value="">Select…</option>
                        <?= account_options(entry_choices($accounts, $credit_kind), (int) $form['credit_account']) ?>
                    </select>
                </label>
                <label>Amount (Rs.)
                    <input type="number" name="amount" min="0.01" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= h($form['amount']) ?>" required>
                </label>
            </div>
        <?php endif; ?>

        <div class="actions">
            <button class="btn btn-primary"><?= $id ? 'Save changes' : 'Save entry' ?></button>
            <a class="btn btn-quiet" href="accounts_journal.php">Cancel</a>
        </div>
    </form>
</section>
<script src="assets/accounts.js" defer></script>
<?php layout_bottom();
