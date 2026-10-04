<?php
/**
 * The read-only view of what everyone has logged, for the administrator (at
 * /admin/time) and for lab managers (at /time/overview).
 *
 * Top to bottom: the entries (folded until opened, or open right after the
 * filter at the bottom was used), the hours per activity (folded), and the
 * filter with its totals and the CSV export.
 *
 * Read-only on purpose: there is no approval step, and nobody edits somebody
 * else's timesheet. See Macrolab\Time\TimeEntryPolicy.
 *
 * @var string                                     $basePath  /admin/time or /time/overview
 * @var bool                                       $filtered  the filter was just used
 * @var \Macrolab\Time\TimeFilter                  $filter
 * @var list<\Macrolab\Time\TimeEntry>             $entries
 * @var array{entries: int, minutes: int}          $totals
 * @var list<array{project: string, minutes: int}> $byProject
 * @var list<array<string, mixed>>                 $people
 * @var list<\Macrolab\Time\Project>               $projects
 */

use Macrolab\Time\TimeRules;
?>
<section class="card">
<details class="foldable"<?= $filtered ? ' open' : '' ?>>
    <summary>
        <h1>Time registrations overview</h1>
        <span class="fold-arrow" aria-hidden="true"></span>
    </summary>

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
                        <td><?= e($entry->workedOnLabel('j M Y')) ?></td>
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
        <p class="muted small"><?= e(count($entries)) ?> entries, oldest first.</p>
    <?php endif; ?>
</details>
</section>

<?php if ($byProject !== []): ?>
    <section class="card">
    <details class="foldable">
        <summary>
            <h2>By activity</h2>
            <span class="fold-arrow" aria-hidden="true"></span>
        </summary>
        <dl class="facts">
            <?php foreach ($byProject as $row): ?>
                <dt><?= e($row['project']) ?></dt>
                <dd><?= e(TimeRules::formatHours($row['minutes'])) ?></dd>
            <?php endforeach; ?>
        </dl>
    </details>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Export to CSV</h2>

    <form method="get" action="<?= e(path($basePath)) ?>" class="filter">
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
        </div>

        <div class="filter-actions">
            <button type="submit" class="primary">Show</button>
        </div>
    </form>

    <div class="overview-results">
        <h3>Totals</h3>
        <dl class="facts">
            <dt>Entries</dt><dd><?= e($totals['entries']) ?></dd>
            <dt>Total hours</dt><dd><?= e(TimeRules::formatHours($totals['minutes'])) ?></dd>
        </dl>

        <h3>Download</h3>
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
    </div>
</section>
