<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/accounts.php';
require_admin('Only an admin can use Accounts.');

$months = [];
for ($month = 1; $month <= 12; $month++) {
    $months[$month] = date('F', mktime(0, 0, 0, $month, 1));
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $chosen = (int) post('start_month');
    if (!isset($months[$chosen])) {
        $errors[] = 'Select a month.';
    } else {
        save_setting('financial_year_start_month', (string) $chosen);
        flash('Financial year saved.');
        redirect('accounts_settings.php');
    }
}

$start_month = fy_start_month();
$year        = fy_start_year(date('Y-m-d'));
[$from, $to] = fy_range($year);

layout_top('Accounts settings', 'accounts');
hero('Accounts settings', 'Set how your accounting year runs.', '', true);
accounts_nav('settings');
?>
<section class="card narrow">
    <h2>Financial year</h2>
    <?php show_errors($errors); ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Financial year starts on the 1st of
            <select name="start_month">
                <?php foreach ($months as $number => $name): ?>
                    <option value="<?= $number ?>"<?= $number === $start_month ? ' selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="fy-now">Current financial year: <strong><?= h(fy_label($year)) ?></strong>, <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?></p>
        <small class="hint form-hint">Changing this only changes how reports group dates. It does not change any of your records.</small>
        <button class="btn btn-primary">Save</button>
    </form>
</section>
<?php layout_bottom();
