<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/two_factor.php';

header('Cache-Control: no-store');

$user_id   = (int) $_SESSION['user_id'];
$errors    = [];
$tf_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'codes_saved') {
    require_csrf();
    unset($_SESSION['recovery_codes']);
    redirect('account.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['new_codes', 'new_phone'], true)) {
    require_csrf();
    $find = db()->prepare('SELECT id, password_hash, totp_secret FROM users WHERE id = ?');
    $find->execute([$user_id]);
    $user = $find->fetch();

    if (!password_verify(post_password('tf_password'), $user['password_hash'])) {
        $tf_errors[] = 'Your current password is not correct.';
    } elseif (($error = second_factor_error($user, post('code'))) !== null) {
        $tf_errors[] = $error;
    } elseif (post('action') === 'new_codes') {
        db()->beginTransaction();
        $_SESSION['recovery_codes'] = new_recovery_codes($user_id);
        db()->commit();
        flash('New recovery codes created. Your old codes no longer work.');
        redirect('account.php');
    } else {
        clear_two_factor($user_id);
        sign_out();
        redirect('login.php');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

$new_codes  = $_SESSION['recovery_codes'] ?? [];
$codes_left = recovery_codes_left($user_id);

layout_top('My account', 'account');
hero('My account', 'Signed in as ' . $_SESSION['username'] . ' (' . $_SESSION['role'] . ').', '', true);
?>
<?php if ($new_codes): ?>
    <section class="card narrow recovery-card">
        <h2>Your recovery codes</h2>
        <p>Keep these somewhere safe, away from your phone. Each code works once if you can't use your authenticator app. They won't be shown again.</p>
        <ul class="recovery-list">
            <?php foreach ($new_codes as $code): ?><li><?= h($code) ?></li><?php endforeach; ?>
        </ul>
        <div class="actions no-print">
            <button type="button" class="btn btn-quiet" data-copy-codes>Copy</button>
            <button type="button" class="btn btn-quiet" data-print>Print</button>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="codes_saved">
                <button class="btn btn-primary">I've saved them</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="card narrow no-print">
    <h2>Two-factor authentication</h2>
    <p><span class="badge badge-ok"><?= icon('shield') ?>On</span> You have <?= $codes_left ?> recovery <?= $codes_left === 1 ? 'code' : 'codes' ?> left.</p>
    <?php show_errors($tf_errors); ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Current password
            <input type="password" name="tf_password" autocomplete="current-password" required>
        </label>
        <label>Authentication or recovery code
            <input class="code-input" name="code" maxlength="20" autocomplete="one-time-code" required>
        </label>
        <div class="actions">
            <button class="btn btn-primary" name="action" value="new_codes">Create new recovery codes</button>
            <button class="btn btn-quiet" name="action" value="new_phone">Move to a new phone</button>
        </div>
        <small class="hint">Moving to a new phone signs you out and asks you to scan a new QR code at your next sign-in.</small>
    </form>
</section>

<section class="card narrow no-print">
    <h2>Change password</h2>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Current password
            <input type="password" name="current" autocomplete="current-password" required>
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
<script src="assets/two_factor.js" defer></script>
<?php layout_bottom();
