<?php
/**
 * The read-only view of what everyone has logged, for the administrator (at
 * /admin/time) and for lab managers (at /time/overview).
 *
 * Two cards. On top, the time registrations table: activities down, people
 * across, one week or one day, with a toolbar styled after the booking
 * calendar's. Below, the CSV export: the filter, the entries it selects, and
 * the download links. The two are independent - the table has its own period
 * (period=, date=) and the filter its own range - but every link and the
 * filter form carry both, so neither is lost. Every card folds (app.js).
 *
 * Read-only on purpose: there is no approval step, and nobody edits somebody
 * else's timesheet. See Macrolab\Time\TimeEntryPolicy.
 *
 * @var string                                     $basePath  /admin/time or /time/overview
 * @var \Macrolab\Time\TimeFilter                  $filter
 * @var list<\Macrolab\Time\TimeEntry>             $entries
 * @var \Macrolab\Time\TimeMatrix                  $matrix
 * @var list<array<string, mixed>>                 $people
 * @var list<\Macrolab\Time\Project>               $projects
 */

use Macrolab\Time\TimeMatrix;
use Macrolab\Time\TimeRules;

/** A link to the table for $period around $date, keeping the filter below. */
$periodLink = static function (string $period, DateTimeImmutable $date) use ($basePath, $filter): string {
    return path($basePath . '?' . http_build_query(['period' => $period, 'date' => $date->format('Y-m-d')])
        . '&' . $filter->queryString());
};

$unit = $matrix->period === TimeMatrix::DAY ? 'day' : 'week';
$chevron = static fn (string $points): string =>
    '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor"'
    . ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="' . $points . '"/></svg>';
?>
<section class="card">
    <h1>Time registrations table</h1>

    <div class="period-toolbar">
        <div class="period-chunk">
            <span class="btn-group">
                <a class="btn-dark" href="<?= e($periodLink($matrix->period, $matrix->previous())) ?>"
                   aria-label="Previous <?= e($unit) ?>" title="Previous <?= e($unit) ?>"><?= $chevron('M15 18l-6-6 6-6') ?></a>
                <a class="btn-dark" href="<?= e($periodLink($matrix->period, $matrix->next())) ?>"
                   aria-label="Next <?= e($unit) ?>" title="Next <?= e($unit) ?>"><?= $chevron('M9 18l6-6-6-6') ?></a>
            </span>
            <?php if ($matrix->showsToday()): ?>
                <a class="btn-dark" aria-disabled="true">Today</a>
            <?php else: ?>
                <a class="btn-dark" href="<?= e($periodLink($matrix->period, TimeRules::today())) ?>">Today</a>
            <?php endif; ?>
        </div>

        <h2 class="period-title"><?= e($matrix->label()) ?></h2>

        <div class="period-chunk">
            <span class="btn-group">
                <?php foreach ([TimeMatrix::WEEK => 'Week', TimeMatrix::DAY => 'Day'] as $period => $label): ?>
                    <a class="btn-dark" href="<?= e($periodLink($period, $matrix->from)) ?>"
                        <?= $period === $matrix->period ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </span>
        </div>
    </div>

    <?php if ($matrix->isEmpty()): ?>
        <p class="muted">Nothing logged <?= $matrix->period === TimeMatrix::DAY ? 'on this day' : 'this week' ?>.</p>
    <?php else: ?>
        <div class="matrix-scroll">
            <table class="time-matrix">
                <thead>
                <tr>
                    <th>Activity</th>
                    <?php foreach ($matrix->people as $person): ?>
                        <th class="num" scope="col"><?= e($person['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix->rows as $row): ?>
                    <tr>
                        <td><?= e($row['label']) ?></td>
                        <?php foreach ($row['cells'] as $cell): ?>
                            <?php if ($cell === null): ?>
                                <td class="num"><span class="muted">-</span></td>
                            <?php else: ?>
                                <td class="num"><?= e(TimeRules::formatHours($cell['minutes'])) ?> <span class="muted">(<?= e($cell['percent']) ?>%)</span></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th scope="row">Total</th>
                    <?php foreach ($matrix->totals as $minutes): ?>
                        <td class="num"><?= e(TimeRules::formatHours($minutes)) ?> <span class="muted">(100%)</span></td>
                    <?php endforeach; ?>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Export to CSV</h2>

    <form method="get" action="<?= e(path($basePath)) ?>" class="filter filter-line">
        <?php /* The table above keeps its period when the filter is applied. */ ?>
        <input type="hidden" name="period" value="<?= e($matrix->period) ?>">
        <input type="hidden" name="date" value="<?= e($matrix->from->format('Y-m-d')) ?>">

        <div class="filter-fields">
            <div class="filter-field">
                <label for="from">From</label>
                <?= date_field('from', $filter->from->format('Y-m-d'), ['id' => 'from']) ?>
            </div>

            <div class="filter-field">
                <label for="to">To</label>
                <?= date_field('to', $filter->to->format('Y-m-d'), ['id' => 'to']) ?>
            </div>

            <div class="filter-field">
                <label for="user">Person</label>
                <select id="user" name="user">
                    <option value="">everyone</option>
                    <?php foreach ($people as $person): ?>
                        <option value="<?= e($person['id']) ?>"
                            <?= (int) $person['id'] === $filter->userId ? 'selected' : '' ?>>
                            <?= e($person['netid']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="project">Activity</label>
                <select id="project" name="project">
                    <option value="">all activities</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= e($project->id) ?>"
                            <?= $project->id === $filter->projectId ? 'selected' : '' ?>>
                            <?= e($project->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="primary">Show</button>
            </div>
        </div>
    </form>

    <?php if ($entries === []): ?>
        <p class="muted">Nothing logged in that range.</p>
    <?php else: ?>
        <?php /* Five rows at a time; app.js sizes the box to exactly five. */ ?>
        <div class="table-scroll" data-visible-rows="5">
            <table>
                <thead>
                <tr><th>Day</th><th>Who</th><th>Activity</th><th class="num">Hours</th><th>Note</th></tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="nowrap"><?= e($entry->workedOnLabel('j M Y')) ?></td>
                        <td>
                            <?= e($entry->ownerLabel()) ?>
                            <span class="muted">(<?= e($entry->ownerNetid) ?>)</span>
                        </td>
                        <td><?= e($entry->projectLabel()) ?></td>
                        <td class="num"><?= e($entry->hoursLabel()) ?></td>
                        <td><?= $entry->note === null ? '<span class="muted">-</span>' : e($entry->note) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">
            <?= e(count($entries)) ?> entries, oldest first<?= count($entries) > 5 ? ' - scroll the list to see them all' : '' ?>.
        </p>
    <?php endif; ?>

    <p>
        Download these rows as CSV:
        <a href="<?= e(path($basePath . '.csv?' . $filter->queryString() . '&sep=semicolon')) ?>">with semicolons</a>
        or
        <a href="<?= e(path($basePath . '.csv?' . $filter->queryString() . '&sep=comma')) ?>">with commas</a>
    </p>

    <p class="muted small">
        The export covers exactly what the filter above shows. Choose
        <em>semicolons</em> for Excel with Dutch or other European settings:
        the hours then use a decimal comma (3,50), so Excel can add them up.
        Choose <em>commas</em> for other spreadsheets and English settings
        (3.50). If a double-click does not split the columns, open the file
        through <em>Data &rarr; From Text/CSV</em>.
    </p>
</section>
