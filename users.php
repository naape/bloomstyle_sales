<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();

$me     = (int) $_SESSION['user_id'];
$errors = [];
$blank  = ['id' => 0, 'username' => '', 'role' => 'Staff'];
$form   = $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) post('id');

    if (post('action') === 'delete') {
        if ($id === $me) {
            flash('You cannot delete your own account.', 'error');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('User deleted.');
        }
        redirect('users.php');
    }

    $form     = ['id' => $id, 'username' => post('username'), 'role' => post('role')];
    $password = post_password('password');

    if (mb_strlen($form['username']) < 3 || mb_strlen($form['username']) > 50) {
        $errors[] = 'Username must be 3 to 50 characters.';
    }
    $duplicate = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
    $duplicate->execute([$form['username'], $id]);
    if ($duplicate->fetchColumn()) {
        $errors[] = "The username {$form['username']} is already taken.";
    }
    if (!in_array($form['role'], ROLES, true)) {
        $errors[] = 'Select a role.';
    }
    if (!$id || $password !== '') {
        $errors = [...$errors, ...password_errors($password, post_password('confirm'))];
    }
    if ($id && $form['role'] !== 'Admin') {
        $other_admins = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND id <> ?");
        $other_admins->execute([$id]);
        if ((int) $other_admins->fetchColumn() === 0) {
            $errors[] = 'There must be at least one admin.';
        }
    }

    if (!$errors) {
        if ($id) {
            db()->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?')
                ->execute([$form['username'], $form['role'], $id]);
            if ($password !== '') {
                set_password($id, $password);
            }
            flash('User updated.');
        } else {
            db()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
                ->execute([$form['username'], password_hash($password, PASSWORD_DEFAULT), $form['role']]);
            flash('User added.');
        }
        redirect('users.php');
    }
} elseif (get('edit') !== '') {
    $find = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $find->execute([(int) get('edit')]);
    $form = $find->fetch() ?: $blank;
}

$users  = db()->query('SELECT id, username, role FROM users ORDER BY role, username')->fetchAll();
$admins = count(array_filter($users, fn($user) => $user['role'] === 'Admin'));

layout_top('Users', 'users');
hero('Users', 'Choose who can sign in and what they can do.');
?>
<dl class="stats stats-3">
    <?= stat_tile('users', 'Users', (string) count($users)) ?>
    <?= stat_tile('shield', 'Admins', (string) $admins) ?>
    <?= stat_tile('direct', 'Staff', (string) (count($users) - $admins)) ?>
</dl>

<div class="split">
    <section class="card sticky">
        <h2><?= $form['id'] ? 'Edit user' : 'Add a user' ?></h2>
        <?php show_errors($errors); ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <label>Username
                <input name="username" maxlength="50" value="<?= h($form['username']) ?>" autocomplete="off" required autofocus>
            </label>
            <div class="field">
                <span class="field-label">Role</span>
                <?= chips('role', ROLES, $form['role']) ?>
                <small class="hint">Admins can also manage users. Staff can work with sales, stock and items.</small>
            </div>
            <label><?= $form['id'] ? 'New password' : 'Password' ?>
                <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"<?= $form['id'] ? '' : ' required' ?>>
                <?php if ($form['id']): ?><small class="hint">Leave blank to keep the current password.</small><?php endif; ?>
            </label>
            <label>Confirm password
                <input type="password" name="confirm" minlength="8" maxlength="72" autocomplete="new-password"<?= $form['id'] ? '' : ' required' ?>>
            </label>
            <div class="actions">
                <button class="btn btn-primary"><?= $form['id'] ? 'Save changes' : 'Add user' ?></button>
                <?php if ($form['id']): ?><a class="btn btn-quiet" href="users.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card card-table">
        <ul class="user-list">
            <?php foreach ($users as $user): ?>
                <li>
                    <span class="avatar"><?= h(monogram($user['username'])) ?></span>
                    <span class="user-name"><?= h($user['username']) ?><?php if ((int) $user['id'] === $me): ?> <em>You</em><?php endif; ?></span>
                    <span class="badge badge-<?= h(strtolower($user['role'])) ?>"><?= icon($user['role'] === 'Admin' ? 'shield' : 'direct') ?><?= h($user['role']) ?></span>
                    <span class="row-actions">
                        <a class="icon-link" href="?edit=<?= (int) $user['id'] ?>" title="Edit" aria-label="Edit <?= h($user['username']) ?>"><?= icon('edit') ?></a>
                        <?php if ((int) $user['id'] !== $me): ?>
                            <form method="post" data-confirm="Delete user <?= h($user['username']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                <button class="icon-link danger" title="Delete" aria-label="Delete <?= h($user['username']) ?>"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
<?php layout_bottom();
