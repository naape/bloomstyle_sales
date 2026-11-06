<?php
const PUBLIC_PAGE = true;
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/two_factor.php';

header('Cache-Control: no-store');

if (signed_in()) {
    redirect('index.php');
}
$user = pending_user();
if (!$user) {
    sign_out();
    redirect('login.php');
}

$setup  = $user['totp_secret'] === null;
$errors = [];
if ($setup) {
    $_SESSION['pending']['secret'] ??= new_totp_secret();
    $secret = $_SESSION['pending']['secret'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = post('code');

    if ($setup) {
        $step = preg_match('/^\d{6}$/', clean_code($code)) ? totp_match($secret, clean_code($code)) : null;
        if ($step === null) {
            $errors[] = 'That code is not correct. Check that you scanned this QR code, then enter the newest code.';
        } else {
            db()->beginTransaction();
            $save = db()->prepare('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ? AND totp_secret IS NULL');
            $save->execute([$secret, $step, $user['id']]);
            if ($save->rowCount() !== 1) {
                db()->rollBack();
                sign_out();
                redirect('login.php');
            }
            $codes = new_recovery_codes((int) $user['id']);
            db()->commit();
            sign_in($user);
            $_SESSION['recovery_codes'] = $codes;
            flash('Two-factor authentication is on. Save your recovery codes now.');
            redirect('account.php');
        }
    } else {
        $error = second_factor_error($user, $code);
        if ($error === null) {
            sign_in($user);
            $left = recovery_codes_left((int) $user['id']);
            if ($left <= 3) {
                flash("You have $left recovery codes left. Create new ones in My account.", 'error');
            }
            redirect('index.php');
        }
        $errors[] = $error;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <?php head_tags('Two-factor authentication'); ?>
    <?php if ($setup): ?>
        <script src="assets/qrcode.js" defer></script>
        <script src="assets/two_factor.js" defer></script>
    <?php endif; ?>
</head>
<body class="login-page">
    <section class="login-art">
        <?= bloom('login-bloom') ?>
        <div>
            <p class="login-brand">bloomstyle<em>.lk</em></p>
            <p class="login-tagline">Sales, stock and items, all in one place.</p>
        </div>
    </section>
    <section class="login-panel">
        <div class="login-form">
            <img class="login-logo" src="assets/logo.png" alt="Bloom Style">
            <h1><?= $setup ? 'Protect your account' : 'Enter your code' ?></h1>
            <?php if ($setup): ?>
                <p class="login-intro">Scan this QR code with an authenticator app, such as Google Authenticator or Microsoft Authenticator. Then enter the 6-digit code it shows.</p>
                <div class="qr-box" role="img" aria-label="QR code for your authenticator app" data-otpauth="<?= h(totp_uri($secret, $user['username'])) ?>"></div>
                <details class="manual-key">
                    <summary>Can't scan the code?</summary>
                    <p>Add an account in your app and enter this key:</p>
                    <code><?= h(implode(' ', str_split($secret, 4))) ?></code>
                </details>
            <?php else: ?>
                <p class="login-intro">Open your authenticator app and enter the 6-digit code for Bloom Style.</p>
            <?php endif; ?>
            <?php show_errors($errors); ?>
            <form method="post">
                <?= csrf_field() ?>
                <label><?= $setup ? '6-digit code' : 'Authentication code' ?>
                    <input class="code-input" name="code" maxlength="<?= $setup ? 7 : 20 ?>" inputmode="<?= $setup ? 'numeric' : 'text' ?>" autocomplete="one-time-code" required autofocus>
                    <?php if (!$setup): ?><small class="hint">Lost your phone? Enter one of your recovery codes instead.</small><?php endif; ?>
                </label>
                <button class="btn btn-primary btn-block"><?= $setup ? 'Turn on and continue' : 'Verify' ?></button>
            </form>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button class="btn btn-quiet btn-block">Back to sign in</button>
            </form>
        </div>
    </section>
</body>
</html>
