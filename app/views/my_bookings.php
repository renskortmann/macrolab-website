<?php
/**
 * The signed-in member's upcoming bookings, under the calendar on /booking.
 * Rendered with the page, and again by GET /api/bookings/mine after the
 * calendar changes something, so the list keeps up without a reload.
 *
 * @var list<\Macrolab\Booking\Booking> $bookings
 */

use Macrolab\Clock;
?>
<?php if ($bookings === []): ?>
    <p class="muted">You have no upcoming equipment bookings.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Date</th><th>Time</th><th>Equipment</th><th>Purpose</th></tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $booking):
            // A booking may run over several days; then the end names its day.
            $sameDay = Clock::local($booking->startsAt, 'Y-m-d') === Clock::local($booking->endsAt, 'Y-m-d');
            ?>
            <tr>
                <td><?= e(Clock::local($booking->startsAt, 'D j M Y')) ?></td>
                <td>
                    <?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e($sameDay
                        ? Clock::local($booking->endsAt, 'H:i')
                        : Clock::local($booking->endsAt, 'D j M H:i')) ?>
                </td>
                <td>
                    <?php if ($booking->equipmentSlug !== null): ?>
                        <a href="<?= e(path('/booking?equipment=' . rawurlencode($booking->equipmentSlug))) ?>"><?= e($booking->equipmentName) ?></a>
                    <?php else: ?>
                        <?= e($booking->equipmentName ?? '-') ?>
                    <?php endif; ?>
                </td>
                <td><?= e($booking->purpose ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small">
        To change or cancel a booking, open its equipment (the link) and click
        the booking in the calendar.
    </p>
<?php endif; ?>
