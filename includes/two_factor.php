<?php
// Two-factor sign-in: time-based codes from an authenticator app (RFC 6238)
// and single-use recovery codes for when the phone is not available.

const TOTP_PERIOD         = 30;
const TOTP_DRIFT          = 1;    // also accept the code either side of now, for clock differences
const RECOVERY_CODE_COUNT = 10;
const PENDING_LIMIT       = 600;  // seconds allowed between the password and the code
const BASE32_ALPHABET     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $text = '';
    foreach (str_split($bits, 5) as $chunk) {
        $text .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $text;
}

function base32_decode(string $text): string
{
    $bits = '';
    foreach (str_split($text) as $character) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $bytes .= chr(bindec($chunk));
        }
    }
    return $bytes;
}

function new_totp_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $step): string
{
    $hash   = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 15;
    $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The time step a code belongs to, or null when it does not match. */
function totp_match(string $secret, string $code): ?int
{
    $now = intdiv(time(), TOTP_PERIOD);
    for ($step = $now - TOTP_DRIFT; $step <= $now + TOTP_DRIFT; $step++) {
        if (hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $username): string
{
    return 'otpauth://totp/' . rawurlencode('Bloom Style:' . $username)
        . '?secret=' . $secret . '&issuer=' . rawurlencode('Bloom Style')
        . '&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
}

function clean_code(string $input): string
{
    return strtoupper(preg_replace('/[\s-]+/', '', $input));
}

function recovery_hash(string $code): string
{
    return hash('sha256', clean_code($code));
}

/** Replaces a user's recovery codes. Only hashes are kept, so the codes can be shown just once. */
function new_recovery_codes(int $user_id): array
{
    $codes = [];
    for ($count = 0; $count < RECOVERY_CODE_COUNT; $count++) {
        $code = '';
        for ($position = 0; $position < 10; $position++) {
            $code .= BASE32_ALPHABET[random_int(0, 31)];
        }
        $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
    }
    db()->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$user_id]);
    $save = db()->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
    foreach ($codes as $code) {
        $save->execute([$user_id, recovery_hash($code)]);
    }
    return $codes;
}

function recovery_codes_left(int $user_id): int
{
    $find = db()->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ?');
    $find->execute([$user_id]);
    return (int) $find->fetchColumn();
}

/** Accepts an authenticator code once only, or uses up a recovery code. */
function verify_second_factor(array $user, string $input): bool
{
    $code = clean_code($input);
    if (preg_match('/^\d{6}$/', $code)) {
        $step = totp_match($user['totp_secret'], $code);
        if ($step === null) {
            return false;
        }
        $use = db()->prepare('UPDATE users SET totp_last_step = ?
                              WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $use->execute([$step, $user['id'], $step]);
        return $use->rowCount() === 1;
    }
    $use = db()->prepare('DELETE FROM recovery_codes WHERE user_id = ? AND code_hash = ?');
    $use->execute([$user['id'], recovery_hash($code)]);
    return $use->rowCount() === 1;
}

/**
 * Checks a code, limiting guesses per user rather than per address, so that
 * knowing the password and changing address does not allow more tries.
 * Returns an error message, or null when the code is accepted.
 */
function second_factor_error(array $user, string $input): ?string
{
    $key    = 'user:' . (int) $user['id'];
    $recent = db()->prepare('SELECT COUNT(*) FROM login_attempts
                             WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . ATTEMPT_WINDOW . ' MINUTE');
    $recent->execute([$key]);
    if ((int) $recent->fetchColumn() >= MAX_ATTEMPTS) {
        return 'Too many incorrect codes. Try again in ' . ATTEMPT_WINDOW . ' minutes.';
    }
    if (verify_second_factor($user, $input)) {
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$key]);
        return null;
    }
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())')->execute([$key]);
    return 'That code is not correct or has already been used. Wait for a new code and try again.';
}

/** Removes two-factor from a user and signs them out, so they set it up again at next sign-in. */
function clear_two_factor(int $user_id): void
{
    db()->beginTransaction();
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_last_step = NULL, session_version = session_version + 1 WHERE id = ?')
        ->execute([$user_id]);
    db()->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$user_id]);
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute(['user:' . $user_id]);
    db()->commit();
}

/** The password is correct: hold the user at the second step, not yet signed in. */
function begin_two_factor(array $user): never
{
    sign_out();
    $_SESSION['pending'] = [
        'id'      => (int) $user['id'],
        'version' => (int) $user['session_version'],
        'at'      => time(),
    ];
    redirect('two_factor.php');
}

function pending_user(): ?array
{
    $pending = $_SESSION['pending'] ?? null;
    if (!$pending || time() - $pending['at'] > PENDING_LIMIT) {
        return null;
    }
    $find = db()->prepare('SELECT id, username, session_version, totp_secret FROM users WHERE id = ?');
    $find->execute([$pending['id']]);
    $user = $find->fetch();
    return $user && (int) $user['session_version'] === $pending['version'] ? $user : null;
}
