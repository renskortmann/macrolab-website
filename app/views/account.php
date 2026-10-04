<?php
/**
 * @var \Macrolab\User                  $user
 * @var list<\Macrolab\Booking\Booking> $bookings
 * @var bool                            $canChange
 * @var int                             $minimum
 * @var string|null                     $error
 */

use Macrolab\Clock;
use Macrolab\Csrf;
?>
<section class="card">
    <h1>My account</h1>

    <dl class="facts">
        <dt>netID</dt><dd><?= e($user->netid) ?></dd>
        <dt>Name</dt><dd><?= e($user->displayName ?? '-') ?></dd>
        <dt>Email</dt><dd><?= e($user->email ?? '-') ?></dd>
    </dl>
</section>

<section class="card">
    <h2>My upcoming bookings</h2>

    <?php if ($bookings === []): ?>
        <p class="muted">You have no upcoming bookings.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Date</th><th>Time</th><th>Equipment</th><th>Purpose</th></tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $booking): ?>
                <tr>
                    <td><?= e(Clock::local($booking->startsAt, 'D j M Y')) ?></td>
                    <td>
                        <?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e(Clock::local($booking->endsAt, 'H:i')) ?>
                    </td>
                    <td><?= e($booking->equipmentName ?? '-') ?></td>
                    <td><?= e($booking->purpose ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted small">Changes and cancellations are made on the <a href="<?= e(path('/booking')) ?>">calendar</a>.</p>
    <?php endif; ?>
</section>

<?php if ($canChange): ?>
    <section class="card">
        <h2>Change my password</h2>

        <?php if ($error !== null): ?>
            <p class="alert" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(path('/account')) ?>">
            <?= Csrf::field() ?>

            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password"
                   autocomplete="current-password" required>

            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password"
                   autocomplete="new-password" required minlength="<?= e($minimum) ?>">

            <label for="new_password_confirm">New password again</label>
            <input id="new_password_confirm" name="new_password_confirm" type="password"
                   autocomplete="new-password" required minlength="<?= e($minimum) ?>">

            <button type="submit" class="primary">Change password</button>
        </form>
    </section>
<?php else: ?>
    <section class="card">
        <h2>Password</h2>
        <p class="muted">
            Your account signs in with your TU Delft netID, so there is no
            password here to change.
        </p>
    </section>
<?php endif; ?>
