<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$errors = [];
$blank  = ['id' => 0, 'code' => '', 'name' => '', 'type' => 'Expense', 'is_cash' => 0, 'is_system' => 0];
$form   = $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id   = (int) post('id');
    $find = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $find->execute([$id]);
    $current = $find->fetch() ?: null;

    if (post('action') === 'delete') {
        if ($current && $current['is_system']) {
            flash('This account is used by sales and stock, so it cannot be deleted.', 'error');
        } else {
            try {
                db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
                flash('Account deleted.');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                flash('This account has entries, so it cannot be deleted.', 'error');
            }
        }
        redirect('accounts_chart.php');
    }

    $system = $current && $current['is_system'];
    $form   = [
        'id'        => $id,
        'code'      => $system ? $current['code'] : post('code'),
        'name'      => post('name'),
        'type'      => $system ? $current['type'] : post('type'),
        'is_cash'   => $system ? (int) $current['is_cash'] : (post('is_cash') !== '' ? 1 : 0),
        'is_system' => $system ? 1 : 0,
    ];

    if (!preg_match('/^[A-Za-z0-9-]{2,10}$/', $form['code'])) {
        $errors[] = 'Account code must be 2 to 10 letters or numbers.';
    }
    if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
        $errors[] = 'Enter an account name of up to 100 characters.';
    }
    if (!in_array($form['type'], ACCOUNT_TYPES, true)) {
        $errors[] = 'Select an account type.';
    }
    if ($form['is_cash'] && $form['type'] !== 'Asset') {
        $errors[] = 'Only an Asset can be a cash or bank account.';
    }
    $duplicate = db()->prepare('SELECT 1 FROM accounts WHERE code = ? AND id <> ?');
    $duplicate->execute([$form['code'], $id]);
    if ($duplicate->fetchColumn()) {
        $errors[] = "Account code {$form['code']} is already used.";
    }

    if (!$errors) {
        $values = [$form['code'], $form['name'], $form['type'], $form['is_cash']];
        if ($id) {
            db()->prepare('UPDATE accounts SET code = ?, name = ?, type = ?, is_cash = ? WHERE id = ?')->execute([...$values, $id]);
            flash('Account updated.');
        } else {
            db()->prepare('INSERT INTO accounts (code, name, type, is_cash) VALUES (?, ?, ?, ?)')->execute($values);
            flash('Account added.');
        }
        redirect('accounts_chart.php');
    }
} elseif (get('edit') !== '') {
    $find = db()->prepare('SELECT id, code, name, type, is_cash, is_system FROM accounts WHERE id = ?');
    $find->execute([(int) get('edit')]);
    $form = $find->fetch() ?: $blank;
}

$accounts = account_balances();

layout_top('Chart of accounts', 'accounts');
hero('Chart of accounts', 'The categories your money is sorted into.', '', true);
accounts_nav('chart');
?>
<div class="split">
    <section class="card sticky">
        <h2><?= $form['id'] ? 'Edit account' : 'Add an account' ?></h2>
        <?php show_errors($errors); ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <label>Code
                <input name="code" maxlength="10" value="<?= h($form['code']) ?>"<?= $form['is_system'] ? ' readonly' : ' required' ?>>
            </label>
            <label>Name
                <input name="name" maxlength="100" value="<?= h($form['name']) ?>" required>
            </label>
            <label>Type
                <?php if ($form['is_system']): ?>
                    <input value="<?= h($form['type']) ?>" readonly>
                <?php else: ?>
                    <select name="type"><?= options(ACCOUNT_TYPES, $form['type']) ?></select>
                <?php endif; ?>
            </label>
            <?php if (!$form['is_system']): ?>
                <label class="check"><input type="checkbox" name="is_cash" value="1"<?= $form['is_cash'] ? ' checked' : '' ?>> This is a cash or bank account</label>
            <?php endif; ?>
            <div class="actions">
                <button class="btn btn-primary"><?= $form['id'] ? 'Save changes' : 'Add account' ?></button>
                <?php if ($form['id']): ?><a class="btn btn-quiet" href="accounts_chart.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card card-table">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Balance</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr data-code="<?= h($account['code']) ?>" data-id="<?= (int) $account['id'] ?>" data-balance="<?= h(number_format((float) $account['balance'], 2, '.', '')) ?>">
                        <td class="strong"><?= h($account['code']) ?></td>
                        <td><a href="accounts_ledger.php?account=<?= (int) $account['id'] ?>"><?= h($account['name']) ?></a><?php if ($account['is_system']): ?><small>Automatic</small><?php endif; ?></td>
                        <td><?= h($account['type']) ?></td>
                        <td class="num"><?= money($account['balance']) ?></td>
                        <td class="row-actions">
                            <a class="icon-link" href="?edit=<?= (int) $account['id'] ?>" title="Edit" aria-label="Edit <?= h($account['name']) ?>"><?= icon('edit') ?></a>
                            <?php if (!$account['is_system']): ?>
                                <form method="post" data-confirm="Delete <?= h($account['name']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                    <button class="icon-link danger" title="Delete" aria-label="Delete <?= h($account['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php layout_bottom();
