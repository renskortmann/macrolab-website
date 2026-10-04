<?php
/**
 * The calendar page. All behaviour lives in assets/app.js; the configuration
 * it needs is handed over in a data attribute, because the content security
 * policy allows no inline script.
 *
 * @var array<string, mixed>|null  $equipment     null until a piece is picked
 * @var list<array<string, mixed>> $equipmentList
 * @var \Macrolab\Actor            $actor
 * @var \Macrolab\Booking\RuleSet  $rules
 * @var string                     $csrf
 * @var array<string, mixed>       $clientRules
 */

use Macrolab\Booking\BookingRules;
use Macrolab\Clock;
?>
<section class="card">
    <div class="calendar-head">
        <div>
            <h1><?= e($equipment === null ? 'Booking' : $equipment['name']) ?></h1>
            <?php if ($equipment !== null && !empty($equipment['description'])): ?>
                <p class="muted"><?= e($equipment['description']) ?></p>
            <?php endif; ?>

            <?php /* Always shown, even for a single piece: nothing is preselected. */ ?>
            <form method="get" action="<?= e(path('/booking')) ?>" class="equipment-picker">
                <label for="equipment">Equipment</label>
                <select id="equipment" name="equipment" data-auto-submit>
                    <?php if ($equipment === null): ?>
                        <option value="" selected disabled>Choose equipment&hellip;</option>
                    <?php endif; ?>
                    <?php foreach ($equipmentList as $piece): ?>
                        <option value="<?= e($piece['slug']) ?>"
                            <?= $equipment !== null && $piece['slug'] === $equipment['slug'] ? 'selected' : '' ?>>
                            <?= e($piece['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Show</button>
            </form>

            <?php if ($equipment === null): ?>
                <p class="muted small">Choose a piece of equipment to see and make bookings.</p>
            <?php endif; ?>
        </div>

        <ul class="legend">
            <li><span class="swatch own"></span> Your bookings</li>
            <li><span class="swatch other"></span> Someone else</li>
        </ul>
    </div>

    <p class="muted small rules-summary">
        <?php if ($actor->isAdmin): ?>
            You are signed in as the administrator: the booking rules below do not
            apply to you, but two bookings still cannot overlap.
        <?php else: ?>
            Bookable <?= e(BookingRules::humanDays($rules->openDays)) ?>,
            <?= e($rules->openTime) ?>-<?= e($rules->closeTime) ?>,
            in blocks of <?= e($rules->slotMinutes) ?> minutes,
            up to <?= e(Clock::humanDuration($rules->maxMinutes)) ?> at a time,
            <?= e($rules->maxAdvanceDays) ?> days ahead,
            <?= $rules->maxActivePerUser > 0
                ? 'at most ' . e($rules->maxActivePerUser) . ' upcoming booking(s) each on this equipment'
                : 'with no limit on how many you may hold' ?>.
        <?php endif; ?>
    </p>

    <p id="calendar-notice" class="flash calendar-notice" role="status" hidden></p>

    <div id="calendar"
         data-feed="<?= e(path('/api/bookings')) ?>"
         data-csrf="<?= e($csrf) ?>"
         data-config="<?= e(json_encode($clientRules, JSON_THROW_ON_ERROR)) ?>"></div>
</section>

<script src="<?= e(path('/assets/vendor/fullcalendar-6.1.15.global.min.js')) ?>"></script>

<dialog id="booking-dialog">
    <form id="booking-form" method="dialog">
        <h2 id="booking-dialog-title">Book the equipment</h2>

        <p id="booking-dialog-error" class="alert" hidden role="alert"></p>

        <dl class="facts">
            <dt>Equipment</dt>
            <dd><?= e($equipment === null ? '' : $equipment['name']) ?></dd>
            <dt>Booked for</dt>
            <dd id="booking-dialog-for"></dd>
        </dl>

        <label for="booking-start-date">Start</label>
        <div class="when">
            <?= date_field('start_date', null, ['id' => 'booking-start-date', 'required' => true]) ?>
            <select id="booking-start-time" name="start_time" aria-label="Start time" required></select>
        </div>
        <p class="muted small when-echo" id="booking-start-echo"></p>

        <label for="booking-end-date">End</label>
        <div class="when">
            <?= date_field('end_date', null, ['id' => 'booking-end-date', 'required' => true]) ?>
            <select id="booking-end-time" name="end_time" aria-label="End time" required></select>
        </div>
        <p class="muted small when-echo" id="booking-end-echo"></p>

        <label for="booking-purpose">Purpose <span class="muted">(optional, only you and the administrator see it)</span></label>
        <input id="booking-purpose" name="purpose" type="text" maxlength="255">

        <?php if ($actor->isAdmin): ?>
            <label for="booking-owner">Book on behalf of (netID)</label>
            <input id="booking-owner" name="owner_netid" type="text"
                   autocapitalize="none" spellcheck="false"
                   placeholder="netID of the person this booking is for">
        <?php endif; ?>

        <menu>
            <button type="button" value="cancel" id="booking-close">Close</button>
            <button type="button" value="delete" id="booking-delete" class="danger" hidden>Cancel this booking</button>
            <button type="button" value="save" id="booking-save" class="primary">Save</button>
        </menu>
    </form>
</dialog>
