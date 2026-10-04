<?php
/**
 * @var \Macrolab\Time\TimeEntry     $entry
 * @var list<\Macrolab\Time\Project> $projects
 * @var \Macrolab\Time\TimeRuleSet   $rules
 * @var string|null                  $error
 */

use Macrolab\Csrf;
use Macrolab\Time\TimeRules;
?>
<section class="card narrow">
    <h1>Change a time entry</h1>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/time/' . $entry->id)) ?>">
        <?= Csrf::field() ?>

        <label for="worked_on">Day</label>
        <?= date_field('worked_on', $entry->workedOnDate(), ['id' => 'worked_on', 'required' => true]) ?>

        <label for="project_id">Activity</label>
        <select id="project_id" name="project_id" required>
            <?php foreach ($projects as $project): ?>
                <option value="<?= e($project->id) ?>"
                    <?= $project->id === $entry->projectId ? 'selected' : '' ?>>
                    <?= e($project->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="hours">Hours</label>
        <input id="hours" name="hours" type="text" required inputmode="decimal"
               value="<?= e($entry->hoursLabel()) ?>">

        <label for="note">Note <span class="muted">(optional)</span></label>
        <input id="note" name="note" type="text" maxlength="<?= e(TimeRules::NOTE_MAX) ?>"
               value="<?= e($entry->note ?? '') ?>">

        <div class="actions">
            <button type="submit" class="primary">Save</button>
            <a href="<?= e(path('/time?month=' . $entry->workedOn->format('Y-m'))) ?>">Cancel</a>
        </div>
    </form>
</section>
