<?php
/**
 * @var list<array<string, mixed>>      $equipmentList
 * @var int                             $equipmentCount
 * @var list<\Macrolab\Booking\Booking> $upcoming
 * @var int                             $userCount
 * @var int                             $suspended
 * @var int                             $noPassword
 * @var string                          $authMode
 * @var int                             $recoveryLeft
 * @var string                          $passwordAlgo
 */

use Macrolab\Clock;
?>
<?php /* The administration pages are the tabs under the top bar (layout.php). */ ?>
<section class="card">
    <h1>At a glance</h1>

    <dl class="facts">
        <dt>Equipment in use</dt>
        <dd>
            <?= e($equipmentCount) ?>
            <span class="muted">
                - <?= e(implode(', ', array_column($equipmentList, 'name'))) ?>
            </span>
        </dd>
        <dt>People on the allowlist</dt>
        <dd>
            <?= e($userCount) ?>
            <?php if ($suspended > 0): ?>
                <span class="muted">(<?= e($suspended) ?> suspended)</span>
            <?php endif; ?>
            <?php if ($noPassword > 0): ?>
                <span class="muted">- <?= e($noPassword) ?> have not set a password yet</span>
            <?php endif; ?>
        </dd>
        <dt>Sign-in mode</dt>
        <dd>
            <?= e($authMode) ?>
            <?php if ($authMode === 'local'): ?>
                <span class="muted">- netID and password, managed here</span>
            <?php endif; ?>
        </dd>
        <dt>Password hashing</dt><dd><?= e($passwordAlgo) ?></dd>
        <dt>Your recovery codes left</dt>
        <dd>
            <?= e($recoveryLeft) ?>
            <?php if ($recoveryLeft <= 2): ?>
                <span class="alert-inline">running low</span>
            <?php endif; ?>
        </dd>
    </dl>
</section>

<section class="card">
    <h2>Today and tomorrow</h2>

    <?php if ($upcoming === []): ?>
        <p class="muted">Nothing booked.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Date</th><th>Time</th><th>Equipment</th><th>Who</th><th>Purpose</th></tr>
            </thead>
            <tbody>
            <?php foreach ($upcoming as $booking): ?>
                <tr>
                    <td><?= e(Clock::local($booking->startsAt, 'D j M')) ?></td>
                    <td><?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e(Clock::local($booking->endsAt, 'H:i')) ?></td>
                    <td><?= e($booking->equipmentName ?? '-') ?></td>
                    <td><?= e($booking->ownerLabel()) ?> <span class="muted">(<?= e($booking->ownerNetid) ?>)</span></td>
                    <td><?= e($booking->purpose ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
