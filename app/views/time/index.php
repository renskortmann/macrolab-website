<?php
/**
 * An employee's own timesheet: a day sheet on top, the month's list below.
 *
 * The day sheet has one row per project. Each row saves itself through
 * /api/time/cell when a cell in it is left (see wireDaySheet() in app.js), so
 * there is no submit button.
 *
 * @var \DateTimeImmutable                                                                 $day
 * @var string                                                                             $dayLabel   "Friday - 18-09-2026"
 * @var string                                                                             $prevDay
 * @var string                                                                             $nextDay
 * @var bool                                                                               $isWeekend
 * @var bool                                                                               $isOpen     inside the logging window
 * @var list<array{project: \Macrolab\Time\Project, entry: \Macrolab\Time\TimeEntry|null}> $rows
 * @var \Macrolab\Time\TimeRuleSet                                                         $rules
 * @var array<string, mixed>                                                               $month      see TimeController::monthData()
 */

use Macrolab\Csrf;
use Macrolab\Time\TimeRules;
use Macrolab\View;
?>
<section class="card">
    <h1>Time registration</h1>

    <?php if ($rows === []): ?>
        <p class="muted">
            There are no activities under the general lab code to log time
            against yet. Ask the administrator to add one.
        </p>
    <?php else: ?>
        <p class="muted small">
            Log the hours you spent on each activity under the general lab code.
        </p>

        <form method="get" action="<?= e(path('/time')) ?>" class="day-nav" id="day-nav">
            <a class="day-step" href="<?= e(path('/time?day=' . $prevDay)) ?>"
               aria-label="Previous day" title="Previous day">&larr;</a>

            <span class="day-pick">
                <button type="button" class="day-label" id="day-label" hidden
                        aria-label="<?= e($dayLabel) ?> - choose another day"><?= e($dayLabel) ?></button>
                <span class="day-label day-label-static"><?= e($dayLabel) ?></span>
                <input id="day-input" name="day" type="date" data-auto-submit
                       value="<?= e($day->format('Y-m-d')) ?>" aria-label="Choose a day">
            </span>

            <a class="day-step" href="<?= e(path('/time?day=' . $nextDay)) ?>"
               aria-label="Next day" title="Next day">&rarr;</a>

            <noscript><button type="submit">Go</button></noscript>
        </form>

        <?php if (!$isOpen): ?>
            <p class="muted small">
                This day is outside the period you can log time for, which runs
                from <?= e($rules->maxBackdateDays) ?> days back to
                <?= e($rules->maxFutureDays) ?> days ahead. Ask the administrator
                if something here needs correcting.
            </p>
        <?php endif; ?>

        <div class="day-sheet-wrap">
        <table class="day-sheet<?= $isWeekend ? ' is-weekend' : '' ?>" id="day-sheet"
               data-day="<?= e($day->format('Y-m-d')) ?>"
               data-csrf="<?= e(Csrf::token()) ?>"
               data-cell-url="<?= e(path('/api/time/cell')) ?>">
            <thead>
            <tr>
                <th>Activity</th>
                <th>Code</th>
                <th class="num">Hours</th>
                <th>Remarks</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $project = $row['project'];
                $entry = $row['entry'];
                $locked = !$isOpen || !$project->isActive;
                ?>
                <tr data-project-id="<?= e($project->id) ?>">
                    <td>
                        <?= e($project->name) ?>
                        <?php if (!$project->isActive): ?>
                            <span class="muted small">(retired)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $project->code === null ? '<span class="muted">&mdash;</span>' : e($project->code) ?></td>
                    <td class="num">
                        <input type="text" class="day-hours" inputmode="decimal" autocomplete="off"
                               value="<?= e($entry === null ? '' : $entry->hoursLabel()) ?>"
                               aria-label="Hours on <?= e($project->name) ?>"
                               aria-describedby="hours-help"
                               <?= $locked ? 'disabled' : '' ?>>
                    </td>
                    <td>
                        <input type="text" class="day-note" maxlength="<?= e(TimeRules::NOTE_MAX) ?>" autocomplete="off"
                               value="<?= e($entry?->note ?? '') ?>"
                               aria-label="Remarks on <?= e($project->name) ?>"
                               <?= $locked ? 'disabled' : '' ?>>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="day-status small" id="day-sheet-status" role="status" aria-live="polite"></p>

        <noscript>
            <p class="alert">Saving hours needs JavaScript, which is switched off in this browser.</p>
        </noscript>

        <p class="muted small" id="hours-help">
            Each row saves itself when you leave it. Hours can be written as
            <code>3.5</code>, <code>3,5</code>, <code>3:30</code> or
            <code>3h30</code>; clear the hours to remove them. The most you can
            log on one activity in a day is
            <?= e(TimeRules::formatHours($rules->maxMinutesPerEntry)) ?>, and
            on one day altogether
            <?= e(TimeRules::formatHours($rules->maxMinutesPerDay)) ?>.
        </p>
    <?php endif; ?>
</section>

<?= View::render('time/month', $month) ?>
