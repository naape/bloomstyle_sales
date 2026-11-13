<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$form   = ['first_no' => '', 'last_no' => '', 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM waybill_ranges WHERE id = ?')->execute([(int) post('id')]);
        flash('Waybill range deleted. Sales keep the waybill numbers they already have.');
        redirect('waybills.php');
    }

    $form = ['first_no' => strtoupper(post('first_no')), 'last_no' => strtoupper(post('last_no')), 'note' => post('note')];
    if ($form['first_no'] === '') {
        $errors[] = 'Enter the first waybill number.';
    } elseif (mb_strlen($form['first_no']) > 50 || mb_strlen($form['last_no']) > 50 || mb_strlen($form['note']) > 255) {
        $errors[] = 'One of the fields is too long.';
    } else {
        $numbers = waybill_range($form['first_no'], $form['last_no']);
        if (is_string($numbers)) {
            $errors[] = $numbers;
        } else {
            $taken = db()->prepare('SELECT number FROM waybills WHERE number IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')
                                    ORDER BY LENGTH(number), number');
            $taken->execute($numbers);
            $taken = $taken->fetchAll(PDO::FETCH_COLUMN);
            if ($taken) {
                $errors[] = count($taken) === 1
                    ? "Waybill {$taken[0]} is already in another range."
                    : count($taken) . " of these numbers are already in another range ({$taken[0]} to " . end($taken) . ').';
            }
        }
    }

    if (!$errors) {
        $last = end($numbers);
        db()->beginTransaction();
        db()->prepare('INSERT INTO waybill_ranges (first_no, last_no, note, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$numbers[0], $last, $form['note'], $_SESSION['username']]);
        $range_id = (int) db()->lastInsertId();
        $add = db()->prepare('INSERT INTO waybills (number, range_id) VALUES (?, ?)');
        foreach ($numbers as $number) {
            $add->execute([$number, $range_id]);
        }
        db()->commit();
        flash(count($numbers) === 1 ? "Waybill {$numbers[0]} added." : count($numbers) . " waybill numbers added, $numbers[0] to $last.");
        redirect('waybills.php');
    }
}

$ranges = db()->query('SELECT r.*, COUNT(w.number) AS total, COUNT(s.waybill_no) AS used,
                              MIN(CASE WHEN s.waybill_no IS NULL THEN LPAD(w.number, 50, " ") END) AS next_no
                       FROM waybill_ranges r
                       JOIN waybills w ON w.range_id = r.id
                       LEFT JOIN (SELECT DISTINCT waybill_no FROM sales) s ON s.waybill_no = w.number
                       GROUP BY r.id
                       ORDER BY r.created_at DESC, r.id DESC')->fetchAll();
$total = array_sum(array_column($ranges, 'total'));
$used  = array_sum(array_column($ranges, 'used'));

layout_top('Waybills', 'waybills');
hero('Waybills', 'Enter the waybill numbers you get from the courier. Sales pick from the unused ones.');
?>
<dl class="stats stats-3">
    <?= stat_tile('waybill', 'Available', (string) ($total - $used)) ?>
    <?= stat_tile('check', 'Used on sales', (string) $used) ?>
    <?= stat_tile('box', 'Ranges', (string) count($ranges)) ?>
</dl>

<div class="split">
    <section class="card sticky">
        <h2>Add waybill numbers</h2>
        <?php show_errors($errors); ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="row-2">
                <label>First number
                    <input name="first_no" maxlength="50" value="<?= h($form['first_no']) ?>" placeholder="e.g. 60685901" required autofocus autocomplete="off">
                </label>
                <label>Last number
                    <input name="last_no" maxlength="50" value="<?= h($form['last_no']) ?>" placeholder="e.g. 60686000" autocomplete="off">
                </label>
            </div>
            <small class="hint form-hint">Leave the last number blank to add a single waybill. Up to <?= MAX_WAYBILL_RANGE ?> numbers per range.</small>
            <label>Note
                <input name="note" maxlength="255" value="<?= h($form['note']) ?>" placeholder="Courier or book">
            </label>
            <div class="actions">
                <button class="btn btn-primary">Add numbers</button>
            </div>
        </form>
    </section>

    <section class="card card-table">
        <?php if (!$ranges): ?>
            <?= empty_state('No waybill numbers yet. Add the range from your courier to choose waybills on sales.') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Range</th><th>Next available</th><th class="num">Numbers</th>
                    <th class="num">Used</th><th class="num">Available</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($ranges as $range):
                    $left = (int) $range['total'] - (int) $range['used']; ?>
                    <tr>
                        <td>
                            <span class="strong"><?= h($range['first_no']) ?><?= $range['last_no'] !== $range['first_no'] ? ' – ' . h($range['last_no']) : '' ?></span>
                            <small><?= h(implode(' · ', array_filter([$range['note'], 'Added ' . fmt_date($range['created_at']), $range['created_by']]))) ?></small>
                        </td>
                        <td><?= $range['next_no'] !== null ? h(ltrim($range['next_no'])) : '<small>All used</small>' ?></td>
                        <td class="num"><?= (int) $range['total'] ?></td>
                        <td class="num"><?= (int) $range['used'] ?></td>
                        <td class="num strong"><?= $left ?></td>
                        <td class="row-actions">
                            <form method="post" data-confirm="Delete this range? Its <?= $left ?> unused numbers can no longer be chosen. Sales keep their waybill numbers.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $range['id'] ?>">
                                <button class="icon-link danger" title="Delete" aria-label="Delete range"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php layout_bottom();
