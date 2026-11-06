<?php
const PUBLIC_PAGE = true;
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/two_factor.php';

if (signed_in()) {
    redirect('index.php');
}

$setup    = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
$ip       = $_SERVER['REMOTE_ADDR'] ?? '';
$username = '';
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = post('username');
    $password = post_password('password');

    if ($setup) {
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            $errors[] = 'Username must be 3 to 50 characters.';
        }
        $errors = [...$errors, ...password_errors($password, post_password('confirm'))];
        if (!$errors) {
            db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'Admin')")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            begin_two_factor(['id' => db()->lastInsertId(), 'session_version' => 0]);
        }
    } else {
        $recent = db()->prepare('SELECT COUNT(*) FROM login_attempts
                                 WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . ATTEMPT_WINDOW . ' MINUTE');
        $recent->execute([$ip]);

        if ((int) $recent->fetchColumn() >= MAX_ATTEMPTS) {
            $errors[] = 'Too many failed attempts. Try again in ' . ATTEMPT_WINDOW . ' minutes.';
        } else {
            $find = db()->prepare('SELECT id, password_hash, session_version FROM users WHERE username = ?');
            $find->execute([$username]);
            $user = $find->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                begin_two_factor($user);
            }
            db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())')->execute([$ip]);
            $errors[] = 'Incorrect username or password.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><?php head_tags($setup ? 'Create account' : 'Sign in'); ?></head>
<body class="login-page">
    <section class="login-art">
        <?= bloom('login-bloom') ?>
        <div>
            <p class="login-brand">bloomstyle<em>.lk</em></p>
            <p class="login-tagline">Sales, stock and items, all in one place.</p>
        </div>
    </section>
    <section class="login-panel">
        <form method="post" class="login-form">
            <img class="login-logo" src="assets/logo.png" alt="Bloom Style">
            <h1><?= $setup ? 'Create your account' : 'Welcome back' ?></h1>
            <p class="login-intro"><?= $setup ? 'Choose the username and password you will use to sign in.' : 'Sign in to manage your store.' ?></p>
            <?php show_errors($errors); ?>
            <?= csrf_field() ?>
            <label>Username
                <input name="username" value="<?= h($username) ?>" maxlength="50" autocomplete="username" required autofocus>
            </label>
            <label>Password
                <span class="password-field">
                    <input type="password" name="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"<?= $setup ? ' minlength="8"' : '' ?> required>
                    <button type="button" class="icon-btn" data-reveal aria-label="Show password"><?= icon('eye') ?></button>
                </span>
            </label>
            <?php if ($setup): ?>
                <label>Confirm password
                    <input type="password" name="confirm" autocomplete="new-password" minlength="8" required>
                </label>
            <?php endif; ?>
            <button class="btn btn-primary btn-block"><?= $setup ? 'Create account' : 'Sign in' ?></button>
        </form>
    </section>
</body>
</html>
