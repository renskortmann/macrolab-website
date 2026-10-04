<?php
/**
 * "My time registrations": one month of the employee's own entries, oldest
 * first. Rendered inside the time registration page and on its own by
 * /api/time/month, which the page calls after each save on the day sheet and
 * when a month arrow is clicked (app.js keeps the card open or folded across
 * both).
 *
 * @var list<\Macrolab\Time\TimeEntry>             $entries
 * @var \DateTimeImmutable                         $month
 * @var string                                     $day        Y-m-d on the sheet above
 * @var string                                     $prevMonth  Y-m
 * @var string                                     $nextMonth  Y-m
 * @var string                                     $prevLabel  "September 2026"
 * @var string                                     $nextLabel
 * @var int                                        $totalMinutes
 * @var list<array{project: string, minutes: int}> $byProject
 */

use Macrolab\Csrf;
use Macrolab\Time\TimeRules;
?>
<section class="card" id="time-month"
         data-url="<?= e(path('/api/time/month')) ?>" data-day="<?= e($day) ?>">
    <h2>My time registrations</h2>

    <nav class="day-nav month-nav" aria-label="Month">
        <a class="day-step" data-month="<?= e($prevMonth) ?>"
           href="<?= e(path('/time?day=' . $day . '&month=' . $prevMonth)) ?>"
           aria-label="Previous month: <?= e($prevLabel) ?>" title="<?= e($prevLabel) ?>">&larr;</a>
        <span class="day-label month-label"><?= e($month->format('F Y')) ?></span>
        <a class="day-step" data-month="<?= e($nextMonth) ?>"
           href="<?= e(path('/time?day=' . $day . '&month=' . $nextMonth)) ?>"
           aria-label="Next month: <?= e($nextLabel) ?>" title="<?= e($nextLabel) ?>">&rarr;</a>
    </nav>

    <?php if ($entries === []): ?>
        <p class="muted">Nothing logged this month.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>Day</th><th>Activity</th><th class="num">Hours</th><th>Note</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e($entry->workedOnLabel()) ?></td>
                    <td><?= e($entry->projectLabel()) ?></td>
                    <td class="num"><?= e($entry->hoursLabel()) ?></td>
                    <td><?= $entry->note === null ? '<span class="muted">-</span>' : e($entry->note) ?></td>
                    <td class="actions">
                        <a href="<?= e(path('/time/' . $entry->id)) ?>">Change</a>
                        <form method="post" action="<?= e(path('/time/' . $entry->id . '/delete')) ?>" class="inline"
                              data-confirm="Remove the <?= e($entry->hoursLabel()) ?> logged on <?= e($entry->workedOnLabel()) ?>?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th class="num"><?= e(TimeRules::formatHours($totalMinutes)) ?></th>
                <th colspan="2"></th>
            </tr>
            </tfoot>
        </table>

        <h3>By activity</h3>
        <dl class="facts">
            <?php foreach ($byProject as $row): ?>
                <dt><?= e($row['project']) ?></dt>
                <dd><?= e(TimeRules::formatHours($row['minutes'])) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>
