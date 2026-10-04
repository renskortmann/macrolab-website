<?php
/**
 * @var array<string, string> $settings
 * @var string                $authMode      the mode in effect, see Settings::authMode()
 * @var bool                  $ssoAvailable
 * @var string|null           $error
 */

use Macrolab\Clock;
use Macrolab\Csrf;

$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$openDays = array_map('intval', array_filter(explode(',', $settings['open_days'] ?? '')));

$times = Clock::timeOptions(15);

/** A 24h time dropdown; the native time input would follow the browser's locale. */
$timeField = static function (string $name, string $selected) use ($times): string {
    $choices = in_array($selected, $times, true) ? $times : [...$times, $selected];
    sort($choices);

    $html = '<select id="' . e($name) . '" name="' . e($name) . '" required>';

    foreach ($choices as $time) {
        $html .= '<option value="' . e($time) . '"'
            . ($time === $selected ? ' selected' : '') . '>' . e($time) . '</option>';
    }

    return $html . '</select>';
};
?>
<section class="card">
    <h1>Rules</h1>

    <p class="muted small">
        The booking rules and the time registration rules, which have nothing to
        do with each other. Both apply to lab members. Neither applies to you.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/admin/settings')) ?>">
        <?= Csrf::field() ?>

        <fieldset>
            <legend>When the equipment can be booked</legend>

            <label>Days</label>
            <div class="checkrow">
                <?php foreach ($days as $number => $name): ?>
                    <label class="check">
                        <input type="checkbox" name="open_days[]" value="<?= e($number) ?>"
                            <?= in_array($number, $openDays, true) ? 'checked' : '' ?>>
                        <?= e($name) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label for="open_time">Opens</label>
            <?= $timeField('open_time', $settings['open_time'] ?? '08:00') ?>

            <label for="close_time">Closes</label>
            <?= $timeField('close_time', $settings['close_time'] ?? '18:00') ?>
        </fieldset>

        <fieldset>
            <legend>Size and shape of a booking</legend>

            <label for="slot_minutes">Slot length (minutes)</label>
            <input id="slot_minutes" name="slot_minutes" type="number" min="5" max="1440" required
                   value="<?= e($settings['slot_minutes'] ?? '30') ?>">

            <label for="min_booking_minutes">Shortest booking (minutes)</label>
            <input id="min_booking_minutes" name="min_booking_minutes" type="number" min="5" max="1440" required
                   value="<?= e($settings['min_booking_minutes'] ?? '30') ?>">

            <label for="max_booking_days">Longest booking (days)</label>
            <input id="max_booking_days" name="max_booking_days" type="number" min="1" max="31" required
                   value="<?= e($settings['max_booking_days'] ?? '1') ?>">
            <p class="muted small">
                A booking may run across several days. Every day it touches must
                be a day the equipment is available, and it must still start after
                opening time and finish before closing time.
            </p>
        </fieldset>

        <fieldset>
            <legend>Limits per person</legend>

            <label for="max_advance_days">Book at most this many days ahead</label>
            <input id="max_advance_days" name="max_advance_days" type="number" min="1" max="1095" required
                   value="<?= e($settings['max_advance_days'] ?? '60') ?>">

            <label for="max_active_bookings_per_user">Upcoming bookings per person (0 = no limit)</label>
            <input id="max_active_bookings_per_user" name="max_active_bookings_per_user"
                   type="number" min="0" max="100" required
                   value="<?= e($settings['max_active_bookings_per_user'] ?? '3') ?>">

            <label for="min_change_notice_minutes">Notice needed to change or cancel (minutes)</label>
            <input id="min_change_notice_minutes" name="min_change_notice_minutes"
                   type="number" min="0" max="10080" required
                   value="<?= e($settings['min_change_notice_minutes'] ?? '60') ?>">

            <label class="check">
                <input type="checkbox" name="allow_booking_in_past" value="1"
                    <?= ($settings['allow_booking_in_past'] ?? '0') === '1' ? 'checked' : '' ?>>
                Allow lab members to book times in the past
            </label>
        </fieldset>

        <fieldset>
            <legend>Sign-in</legend>

            <label for="auth_mode">How lab members sign in</label>
            <select id="auth_mode" name="auth_mode">
                <?php foreach (['local' => 'netID and password (managed here)',
                                 'both'  => 'Either password or TU Delft SSO (cutover)',
                                 'saml'  => 'TU Delft SSO only'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"
                        <?= $authMode === $value ? 'selected' : '' ?>
                        <?= $value !== 'local' && !$ssoAvailable ? 'disabled' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <p class="muted small">
                <?php if ($ssoAvailable): ?>
                    TU Delft SSO works only once the service provider has been
                    registered with ICT and configured.
                <?php else: ?>
                    TU Delft SSO is planned but not yet available in this version
                    of the application, so lab members sign in with a password.
                <?php endif; ?>
                Your own sign-in is never affected by this setting.
            </p>

            <label for="audit_retention_days">Keep audit log entries for (days)</label>
            <input id="audit_retention_days" name="audit_retention_days" type="number"
                   min="30" max="3650" required
                   value="<?= e($settings['audit_retention_days'] ?? '365') ?>">
        </fieldset>

        <fieldset>
            <legend>Time registration</legend>

            <p class="muted small">
                These govern the hours technicians log. They are unrelated to
                the booking rules above: time is logged against an activity
                under the general lab code, never against equipment.
            </p>

            <label for="time_min_entry_minutes">Shortest entry (minutes)</label>
            <input id="time_min_entry_minutes" name="time_min_entry_minutes" type="number"
                   min="1" max="1440" required
                   value="<?= e($settings['time_min_entry_minutes'] ?? '5') ?>">

            <label for="time_max_entry_minutes">Longest single entry (minutes)</label>
            <input id="time_max_entry_minutes" name="time_max_entry_minutes" type="number"
                   min="1" max="1440" required
                   value="<?= e($settings['time_max_entry_minutes'] ?? '720') ?>">

            <label for="time_max_day_minutes">Most that can be logged on one day (minutes)</label>
            <input id="time_max_day_minutes" name="time_max_day_minutes" type="number"
                   min="1" max="1440" required
                   value="<?= e($settings['time_max_day_minutes'] ?? '960') ?>">

            <label for="time_max_future_days">How far ahead time may be logged (days)</label>
            <input id="time_max_future_days" name="time_max_future_days" type="number"
                   min="0" max="365" required
                   value="<?= e($settings['time_max_future_days'] ?? '7') ?>">

            <label for="time_max_backdate_days">How far back time may be logged (days)</label>
            <input id="time_max_backdate_days" name="time_max_backdate_days" type="number"
                   min="0" max="3650" required
                   value="<?= e($settings['time_max_backdate_days'] ?? '90') ?>">
        </fieldset>

        <button type="submit" class="primary">Save settings</button>
    </form>
</section>
