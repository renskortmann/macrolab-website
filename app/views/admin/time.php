<?php
/**
 * The read-only view of what everyone has logged, for the administrator (at
 * /admin/time) and for lab managers (at /time/overview).
 *
 * Read-only on purpose: there is no approval step, and nobody edits somebody
 * else's timesheet. See Macrolab\Time\TimeEntryPolicy.
 *
 * @var string                                     $basePath  /admin/time or /time/overview
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
    <h1>Time overview</h1>

    <form method="get" action="<?= e(path($basePath)) ?>" class="row">
        <label for="from">From</label>
        <input id="from" name="from" type="date" data-echo value="<?= e($filter->from->format('Y-m-d')) ?>">

        <label for="to">To</label>
        <input id="to" name="to" type="date" data-echo value="<?= e($filter->to->format('Y-m-d')) ?>">

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

        <button type="submit" class="primary">Show</button>
    </form>

    <dl class="facts">
        <dt>Entries</dt><dd><?= e($totals['entries']) ?></dd>
        <dt>Total hours</dt><dd><?= e(TimeRules::formatHours($totals['minutes'])) ?></dd>
    </dl>

    <p>
        Export these rows as CSV:
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

<?php if ($byProject !== []): ?>
    <section class="card">
        <h2>By activity</h2>
        <dl class="facts">
            <?php foreach ($byProject as $row): ?>
                <dt><?= e($row['project']) ?></dt>
                <dd><?= e(TimeRules::formatHours($row['minutes'])) ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Entries</h2>

    <?php if ($entries === []): ?>
        <p class="muted">Nothing logged in that range.</p>
    <?php else: ?>
        <table class="wide">
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
    <?php endif; ?>
</section>
