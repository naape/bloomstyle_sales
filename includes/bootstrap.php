<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

date_default_timezone_set('Asia/Colombo');
session_name('bloomstyle_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => !empty($_SERVER['HTTPS']),
]);

const STATUSES       = ['In progress', 'Handed to Delivery', 'Rescheduled', 'Delivered', 'Cancelled', 'Returned'];
const REFERRAL_TYPES = ['WhatsApp', 'Messenger', 'Facebook', 'Instagram', 'Daraz', 'Contacts', 'Direct', 'Not recorded'];
const CASH_STATUSES  = ['Pending', 'Received', 'Refund'];
const ROLES          = ['Admin', 'Staff'];
const PER_PAGE       = 50;
const MAX_AMOUNT     = 99999999.99;
const MAX_QTY        = 1000000;
const LOW_STOCK      = 3;          // at or below this many units an item shows as low
const IDLE_LIMIT     = 8 * 3600;   // seconds of inactivity before signing out
const MAX_ATTEMPTS   = 5;          // failed sign-ins allowed per address ...
const ATTEMPT_WINDOW = 15;         // ... within this many minutes

// Orders with these statuses did not leave stock and do not count as sales.
const NOT_SOLD_SQL = "('Cancelled', 'Returned')";

const DISTRICTS = [
    'Ampara' => 'Eastern', 'Anuradhapura' => 'North Central', 'Badulla' => 'Uva',
    'Batticaloa' => 'Eastern', 'Colombo' => 'Western', 'Galle' => 'Southern',
    'Gampaha' => 'Western', 'Hambantota' => 'Southern', 'Jaffna' => 'Northern',
    'Kalutara' => 'Western', 'Kandy' => 'Central', 'Kegalle' => 'Sabaragamuwa',
    'Kilinochchi' => 'Northern', 'Kurunegala' => 'North Western', 'Mannar' => 'Northern',
    'Matale' => 'Central', 'Matara' => 'Southern', 'Monaragala' => 'Uva',
    'Mullaitivu' => 'Northern', 'Nuwara Eliya' => 'Central', 'Polonnaruwa' => 'North Central',
    'Puttalam' => 'North Western', 'Ratnapura' => 'Sabaragamuwa', 'Trincomalee' => 'Eastern',
    'Vavuniya' => 'Northern',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Bloomstyle database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Cannot connect to the database. Check that MySQL is running in MAMP and that includes/config.php is correct.');
        }
    }
    return $pdo;
}

function setting(string $name, string $default = ''): string
{
    $find = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $find->execute([$name]);
    $value = $find->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function save_setting(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

function fy_start_month(): int
{
    return (int) setting('financial_year_start_month', '4');
}

/** The calendar year in which the financial year containing a date begins. */
function fy_start_year(string $date): int
{
    $year = (int) substr($date, 0, 4);
    return (int) substr($date, 5, 2) >= fy_start_month() ? $year : $year - 1;
}

/** First and last day of the financial year beginning in a calendar year. */
function fy_range(int $year): array
{
    $from = sprintf('%04d-%02d-01', $year, fy_start_month());
    return [$from, date('Y-m-d', strtotime("$from +1 year -1 day"))];
}

function fy_label(int $year): string
{
    return fy_start_month() === 1 ? (string) $year : $year . '/' . substr((string) ($year + 1), 2);
}

/** All items with their purchased, sold and in-stock quantities. */
function items_with_stock(): array
{
    return db()->query("
        SELECT i.*, p.purchased, s.sold, p.purchased - s.sold AS in_stock
        FROM items i
        JOIN (SELECT i2.id, COALESCE(SUM(st.qty), 0) AS purchased
              FROM items i2 LEFT JOIN stock st ON st.item_id = i2.id GROUP BY i2.id) p ON p.id = i.id
        JOIN (SELECT i3.id, COALESCE(SUM(si.qty), 0) AS sold
              FROM items i3
              LEFT JOIN (sale_items si JOIN sales sa ON sa.id = si.sale_id AND sa.status NOT IN " . NOT_SOLD_SQL . ")
                     ON si.item_id = i3.id
              GROUP BY i3.id) s ON s.id = i.id
        ORDER BY i.code
    ")->fetchAll();
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return number_format((float) $value, 2);
}

/** A trimmed POST value; anything that is not a plain string counts as empty. */
function post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** A repeated POST field such as item_id[], as a list of trimmed strings. */
function post_list(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }
    return array_map(fn($value) => is_string($value) ? trim($value) : '', array_values($values));
}

function get(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

/** Parses a non-negative amount, or returns null if it isn't one. */
function parse_amount(string $value, int $decimals = 2): ?float
{
    $number = filter_var($value, FILTER_VALIDATE_FLOAT);
    return ($number === false || !is_finite($number) || $number < 0 || $number > MAX_AMOUNT) ? null : round($number, $decimals);
}

/** Parses a whole quantity from 1 to MAX_QTY, or returns null if it isn't one. */
function parse_qty(string $value): ?int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => MAX_QTY]]);
    return $number === false ? null : $number;
}

/** Parses a stock quantity: negative for damaged stock, never 0. Null if invalid. */
function parse_stock_qty(string $value): ?int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => -MAX_QTY, 'max_range' => MAX_QTY]]);
    return ($number === false || $number === 0) ? null : $number;
}

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function require_csrf(): void
{
    $token = $_SESSION['csrf'] ?? '';
    if ($token === '' || !hash_equals($token, post('csrf'))) {
        http_response_code(403);
        exit('This page expired. Go back, refresh and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Works out the current page. Returns [page, page count, row offset]. */
function paginate(int $total): array
{
    $pages = max(1, (int) ceil($total / PER_PAGE));
    $page  = min($pages, max(1, (int) get('page')));
    return [$page, $pages, ($page - 1) * PER_PAGE];
}

function pager(int $page, int $pages, array $params): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = fn(int $to, string $label) => '<a class="btn btn-quiet" href="?'
        . h(http_build_query(['page' => $to] + $params)) . '">' . $label . '</a>';
    return '<nav class="pager" aria-label="Pages">'
        . ($page > 1 ? $link($page - 1, '&larr; Newer') : '')
        . '<span>Page ' . $page . ' of ' . $pages . '</span>'
        . ($page < $pages ? $link($page + 1, 'Older &rarr;') : '')
        . '</nav>';
}

/** <option> tags for a plain list of values. */
function options(array $values, string $selected): string
{
    $html = '';
    foreach ($values as $value) {
        $html .= '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . h($value) . '</option>';
    }
    return $html;
}

/** <option> tags for items, carrying the details the forms fill in from. */
function item_options(array $items, int $selected): string
{
    $html = '';
    foreach ($items as $item) {
        $html .= '<option value="' . (int) $item['id'] . '"'
            . ' data-description="' . h($item['description']) . '"'
            . ($item['selling_price'] !== null ? ' data-price="' . h(number_format((float) $item['selling_price'], 2, '.', '')) . '"' : '')
            . ($item['cost_price'] !== null ? ' data-cost="' . h(number_format((float) $item['cost_price'], 2, '.', '')) . '"' : '')
            . ' data-stock="' . (int) $item['in_stock'] . '"'
            . ((int) $item['id'] === $selected ? ' selected' : '') . '>' . h($item['code']) . '</option>';
    }
    return $html;
}

function slug(string $value): string
{
    return strtolower(str_replace(' ', '-', $value));
}

function show_errors(array $errors): void
{
    if ($errors) {
        echo '<div class="alert alert-error" role="alert"><ul>';
        foreach ($errors as $error) {
            echo '<li>' . h($error) . '</li>';
        }
        echo '</ul></div>';
    }
}

function fmt_date(string $date): string
{
    return date('d M Y', strtotime($date));
}

function monogram(string $text): string
{
    return mb_strtoupper(mb_substr(trim($text), 0, 1));
}

/** Stock level of an item as [css class, label, icon]. */
function stock_state(int $purchased, int $in_stock): array
{
    return match (true) {
        $purchased === 0     => ['none', 'No stock yet', 'box'],
        $in_stock <= 0       => ['out', 'Out of stock', 'alert'],
        $in_stock <= LOW_STOCK => ['low', 'Low stock', 'clock'],
        default              => ['ok', 'In stock', 'check'],
    };
}

function icon(string $name): string
{
    static $paths = [
        'sales'    => 'M6 8h12l1 12H5L6 8z M9 8V6a3 3 0 0 1 6 0v2',
        'box'      => 'M3 8l9-5 9 5v8l-9 5-9-5V8z M3 8l9 5 9-5 M12 13v8',
        'tag'      => 'M3 12V4h8l10 10-8 8L3 12z M7.5 7.5h.01',
        'logout'   => 'M9 4H5v16h4 M15 8l4 4-4 4 M19 12H9',
        'plus'     => 'M12 5v14 M5 12h14',
        'search'   => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z M21 21l-5-5',
        'wallet'   => 'M3 8h18v12H3z M16 14h2 M3 8l3-4h12l3 4',
        'clock'    => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M12 7v5l3 2',
        'check'    => 'M5 12l5 5 9-10',
        'alert'    => 'M12 3l10 18H2L12 3z M12 10v5 M12 18h.01',
        'whatsapp' => 'M4 20l1.5-4A8 8 0 1 1 9 19l-5 1z',
        'messenger'=> 'M4 5h16v11H9l-5 4V5z',
        'direct'   => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0',
        'receipt'  => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3z M9 8h6 M9 12h6',
        'edit'     => 'M4 20h4L19 9l-4-4L4 16v4z',
        'trash'    => 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13',
        'chart'    => 'M3 20h18 M6 20v-7 M12 20V5 M18 20v-11',
        'users'    => 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M2 21a7 7 0 0 1 14 0 M16 4.5a4 4 0 0 1 0 7 M18 14.5a7 7 0 0 1 4 6.5',
        'shield'   => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z M9 12l2 2 4-4',
        'eye'      => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $paths[$name] . '"/></svg>';
}

/** The brand flower: rings of petals, drawn in the current text colour. */
function bloom(string $class = ''): string
{
    $petals = '';
    foreach ([[48, 19, 0], [34, 14, 15], [20, 9, 0]] as [$length, $width, $offset]) {
        for ($i = 0; $i < 12; $i++) {
            $petals .= '<ellipse cx="100" cy="' . (100 - $length) . '" rx="' . $width . '" ry="' . $length
                . '" transform="rotate(' . ($i * 30 + $offset) . ' 100 100)"/>';
        }
    }
    return '<svg class="bloom ' . h($class) . '" viewBox="0 0 200 200" fill="none" stroke="currentColor" '
        . 'stroke-width="0.7" aria-hidden="true">' . $petals . '</svg>';
}

function referral_icon(string $type): string
{
    return icon(match ($type) {
        'WhatsApp'                           => 'whatsapp',
        'Messenger', 'Facebook', 'Instagram' => 'messenger',
        'Daraz'                              => 'sales',
        default                              => 'direct',
    });
}

/** Radio buttons drawn as a row of chips. */
function chips(string $name, array $values, string $selected): string
{
    $html = '<div class="chips">';
    foreach ($values as $value) {
        $symbol = in_array($value, REFERRAL_TYPES, true) ? referral_icon($value) : '';
        $html .= '<label class="chip"><input type="radio" name="' . h($name) . '" value="' . h($value) . '"'
            . ($value === $selected ? ' checked' : '') . ' required><span>' . $symbol . h($value) . '</span></label>';
    }
    return $html . '</div>';
}

function stat_tile(string $icon, string $label, string $value, string $note = ''): string
{
    return '<div class="stat"><span class="stat-icon">' . icon($icon) . '</span><div><dt>' . h($label)
        . '</dt><dd>' . h($value) . '</dd>' . ($note !== '' ? '<dd class="stat-note">' . h($note) . '</dd>' : '')
        . '</div></div>';
}

function empty_state(string $message): string
{
    return '<div class="empty">' . bloom() . '<p>' . $message . '</p></div>';
}

function hero(string $title, string $subtitle, string $action = '', bool $compact = false): void
{
    echo '<header class="hero' . ($compact ? ' hero-compact' : '') . '">' . bloom('hero-bloom')
        . '<div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>' . $action . '</header>';
}

function head_tags(string $title): void
{
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Bloomstyle</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
    <?php
}

function layout_top(string $title, string $section): void
{
    $nav = [
        'dashboard' => ['dashboard.php', 'Dashboard', 'chart'],
        'sales' => ['index.php', 'Sales', 'sales'],
        'stock' => ['stock.php', 'Stock', 'box'],
        'items' => ['items.php', 'Items', 'tag'],
    ];
    if (is_admin()) {
        $nav['accounts'] = ['accounts.php', 'Accounts', 'wallet'];
        $nav['users']    = ['users.php', 'Users', 'users'];
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!doctype html>
<html lang="en">
<head><?php head_tags($title); ?></head>
<body data-section="<?= h($section) ?>">
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="index.php"><img src="assets/logo.png" alt="Bloom Style"></a>
        <nav aria-label="Main">
            <?php foreach ($nav as $key => [$href, $label, $symbol]): ?>
                <a href="<?= $href ?>"<?= $key === $section ? ' class="active" aria-current="page"' : '' ?>><?= icon($symbol) ?><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <?= bloom('sidebar-bloom') ?>
        <div class="account">
            <a class="account-link<?= $section === 'account' ? ' active' : '' ?>" href="account.php" title="My account">
                <span class="avatar"><?= h(monogram($_SESSION['username'])) ?></span>
                <span class="account-name"><?= h($_SESSION['username']) ?><small><?= h($_SESSION['role']) ?></small></span>
            </a>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button class="icon-btn" title="Log out" aria-label="Log out"><?= icon('logout') ?></button>
            </form>
        </div>
    </aside>
    <main>
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
        <?php endif;
}

function layout_bottom(): void
{
    echo "    </main>\n</div>\n</body>\n</html>\n";
}

function sign_in(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['user_id']         = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    $_SESSION['last_seen']       = time();
}

function sign_out(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function signed_in(): bool
{
    return isset($_SESSION['user_id']) && time() - ($_SESSION['last_seen'] ?? 0) < IDLE_LIMIT;
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'Admin';
}

function require_admin(string $message = 'Only an admin can manage users.'): void
{
    if (!is_admin()) {
        flash($message, 'error');
        redirect('index.php');
    }
}

/** A password field exactly as typed; spaces are part of a password. */
function post_password(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Problems with a new password, as messages for the form. */
function password_errors(string $password, string $confirm): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72 || str_contains($password, "\0")) {
        $errors[] = 'Password must be at most 72 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    return $errors;
}

/** Saves a new password and signs that user out everywhere else. */
function set_password(int $user_id, string $password): void
{
    db()->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]);
    if ($user_id === $_SESSION['user_id']) {
        $_SESSION['session_version']++;
        session_regenerate_id(true);
    }
}

// Every page needs a signed-in user, except those that declare themselves public.
// The account is re-read on each request so that deleting a user, changing a role
// or resetting a password takes effect straight away.
if (!defined('PUBLIC_PAGE')) {
    $account = false;
    if (signed_in()) {
        $find = db()->prepare('SELECT username, role, session_version FROM users WHERE id = ?');
        $find->execute([$_SESSION['user_id']]);
        $account = $find->fetch();
    }
    if (!$account || (int) $account['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
        sign_out();
        redirect('login.php');
    }
    $_SESSION['username']  = $account['username'];
    $_SESSION['role']      = $account['role'];
    $_SESSION['last_seen'] = time();
}
