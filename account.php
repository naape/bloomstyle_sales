<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $find = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $find->execute([$_SESSION['user_id']]);

    if (!password_verify(post_password('current'), (string) $find->fetchColumn())) {
        $errors[] = 'Your current password is not correct.';
    }
    $errors = [...$errors, ...password_errors(post_password('password'), post_password('confirm'))];

    if (!$errors) {
        set_password((int) $_SESSION['user_id'], post_password('password'));
        flash('Password changed.');
        redirect('account.php');
    }
}

layout_top('My account', 'account');
hero('My account', 'Signed in as ' . $_SESSION['username'] . ' (' . $_SESSION['role'] . ').', '', true);
?>
<section class="card narrow">
    <h2>Change password</h2>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Current password
            <input type="password" name="current" autocomplete="current-password" required autofocus>
        </label>
        <label>New password
            <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        </label>
        <label>Confirm new password
            <input type="password" name="confirm" minlength="8" maxlength="72" autocomplete="new-password" required>
        </label>
        <button class="btn btn-primary">Change password</button>
    </form>
</section>
<?php layout_bottom();
