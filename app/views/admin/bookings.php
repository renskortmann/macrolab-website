<?php
/**
 * @var list<\Macrolab\Booking\Booking> $bookings
 * @var list<array<string, mixed>>      $users
 * @var list<array<string, mixed>>      $equipmentList
 * @var string                          $filter
 * @var string|null                     $error
 */

use Macrolab\Clock;
use Macrolab\Csrf;

$times = Clock::timeOptions(15);

/** A 24h time dropdown; the native time input would follow the browser's locale. */
$timeField = static function (string $name, string $label, string $selected) use ($times): string {
    // A booking made under a different slot length may sit off this grid. Keep
    // its own time on the list rather than silently moving it to midnight.
    $choices = in_array($selected, $times, true) ? $times : [...$times, $selected];
    sort($choices);

    $html = '<select name="' . e($name) . '" aria-label="' . e($label) . '" required>';

    foreach ($choices as $time) {
        $html .= '<option value="' . e($time) . '"'
            . ($time === $selected ? ' selected' : '') . '>' . e($time) . '</option>';
    }

    return $html . '</select>';
};

/** A day-first date field that keeps a written-out echo of its value beside it. */
$dateField = static function (string $name, string $label, string $value, string $echoId): string {
    return date_field($name, $value, ['aria-label' => $label, 'data-echo' => $echoId, 'required' => true]);
};
?>
<section class="card">
    <h1>All equipment bookings</h1>

    <p class="muted small">
        As administrator you may create, change and delete any equipment booking, and
        the equipment booking rules do not restrict you. Two bookings still cannot overlap.
        Every change here is recorded in the <a href="<?= e(path('/admin/audit')) ?>">audit log</a>.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Create an equipment booking for someone</h2>

    <form method="post" action="<?= e(path('/admin/bookings')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">

        <label for="equipment">Equipment</label>
        <select id="equipment" name="equipment" required>
            <?php foreach ($equipmentList as $piece): ?>
                <?php if ((int) $piece['is_active'] === 1): ?>
                    <option value="<?= e($piece['slug']) ?>"><?= e($piece['name']) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>

        <label for="owner_netid">netID</label>
        <input id="owner_netid" name="owner_netid" list="netids" required
               autocapitalize="none" spellcheck="false">
        <datalist id="netids">
            <?php foreach ($users as $user): ?>
                <option value="<?= e($user['netid']) ?>"><?= e($user['display_name'] ?? $user['netid']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <label for="start_date">Start</label>
        <span class="when">
            <?= date_field('start_date', null, ['id' => 'start_date', 'data-echo' => 'new-start-echo', 'required' => true]) ?>
            <?= $timeField('start_time', 'Start time', '09:00') ?>
            <span class="muted small" id="new-start-echo"></span>
        </span>

        <label for="end_date">End</label>
        <span class="when">
            <?= date_field('end_date', null, ['id' => 'end_date', 'data-echo' => 'new-end-echo', 'required' => true]) ?>
            <?= $timeField('end_time', 'End time', '17:00') ?>
            <span class="muted small" id="new-end-echo"></span>
        </span>

        <label for="purpose">Purpose</label>
        <input id="purpose" name="purpose" type="text" maxlength="255">

        <button type="submit" class="primary">Create</button>
    </form>
</section>

<section class="card">
    <h2>Equipment bookings</h2>

    <form method="get" action="<?= e(path('/admin/bookings')) ?>" class="row">
        <label for="equipment_filter">Show</label>
        <select id="equipment_filter" name="equipment" data-auto-submit>
            <option value="">all equipment</option>
            <?php foreach ($equipmentList as $piece): ?>
                <option value="<?= e($piece['slug']) ?>" <?= $filter === $piece['slug'] ? 'selected' : '' ?>>
                    <?= e($piece['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <?php if ($bookings === []): ?>
        <p class="muted">No equipment bookings yet.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>When</th><th>Equipment</th><th>Who</th><th>Purpose</th><th>Status</th><th>Change</th></tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $booking): ?>
                <tr class="<?= $booking->isConfirmed() ? '' : 'row-cancelled' ?>">
                    <td>
                        <?= e(Clock::local($booking->startsAt, 'D j M Y')) ?><br>
                        <?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e(Clock::local($booking->endsAt, 'H:i')) ?>
                    </td>
                    <td><?= e($booking->equipmentName ?? '-') ?></td>
                    <td>
                        <?= e($booking->ownerLabel()) ?><br>
                        <code class="small"><?= e($booking->ownerNetid) ?></code>
                    </td>
                    <td><?= e($booking->purpose ?? '-') ?></td>
                    <td>
                        <?= e($booking->status) ?>
                        <?php if ($booking->createdByAdmin): ?>
                            <br><span class="muted small">created by admin</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="post" action="<?= e(path('/admin/bookings')) ?>" class="inline-edit">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="booking_id" value="<?= e($booking->id) ?>">

                            <span class="when">
                                <?= $dateField('start_date', 'New start date',
                                    Clock::local($booking->startsAt, 'Y-m-d'),
                                    'start-echo-' . $booking->id) ?>
                                <?= $timeField('start_time', 'New start time',
                                    Clock::local($booking->startsAt, 'H:i')) ?>
                                <span class="muted small" id="start-echo-<?= e($booking->id) ?>">
                                    <?= e(Clock::local($booking->startsAt, 'D j M Y')) ?>
                                </span>
                            </span>
                            <span class="when">
                                <?= $dateField('end_date', 'New end date',
                                    Clock::local($booking->endsAt, 'Y-m-d'),
                                    'end-echo-' . $booking->id) ?>
                                <?= $timeField('end_time', 'New end time',
                                    Clock::local($booking->endsAt, 'H:i')) ?>
                                <span class="muted small" id="end-echo-<?= e($booking->id) ?>">
                                    <?= e(Clock::local($booking->endsAt, 'D j M Y')) ?>
                                </span>
                            </span>
                            <input type="text" name="purpose" aria-label="Purpose" maxlength="255"
                                   value="<?= e($booking->purpose) ?>">

                            <button type="submit" name="action" value="update" class="link">Save</button>
                            <?php if ($booking->isConfirmed()): ?>
                                <button type="submit" name="action" value="cancel" class="link">Cancel</button>
                            <?php endif; ?>
                            <button type="submit" name="action" value="delete" class="link danger"
                                    data-confirm="Delete this booking outright? The audit log keeps a record.">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
