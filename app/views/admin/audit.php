<?php
/**
 * @var list<array<string, mixed>> $rows
 * @var list<string>               $actions
 * @var string                     $filter
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $total
 */

use Macrolab\Clock;
?>
<section class="card">
    <h1>Audit log</h1>

    <p class="muted small">
        <?= e($total) ?> entries. Every equipment booking change, every time entry and
        activity change, every change to the allowlist or the rules, and every
        sign-in - including refused ones.
    </p>

    <form method="get" action="<?= e(path('/admin/audit')) ?>" class="row">
        <label for="action">Show</label>
        <select id="action" name="action">
            <option value="">everything</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>" <?= $filter === $action ? 'selected' : '' ?>>
                    <?= e($action) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table class="wide">
        <thead>
        <tr><th>When</th><th>Who</th><th>What</th><th>Target</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="nowrap"><?= e(Clock::local(Clock::fromSql((string) $row['created_at']), 'j M Y H:i:s')) ?></td>
                <td>
                    <?= e($row['actor_label']) ?>
                    <br><span class="muted small"><?= e($row['actor_type']) ?><?php
                        $ip = $row['ip'] !== null ? @inet_ntop((string) $row['ip']) : false;
                        echo $ip !== false && $ip !== null ? ' from ' . e($ip) : '';
                    ?></span>
                </td>
                <td><code><?= e($row['action']) ?></code></td>
                <td class="muted small">
                    <?= e($row['target_type'] ?? '') ?><?= $row['target_id'] !== null ? ' #' . e($row['target_id']) : '' ?>
                </td>
                <td class="detail"><?= e($row['details'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <p class="pager">
            <?php if ($page > 1): ?>
                <a href="<?= e(path('/admin/audit?page=' . ($page - 1) . '&action=' . urlencode($filter))) ?>">Newer</a>
            <?php endif; ?>
            <span class="muted">page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= e(path('/admin/audit?page=' . ($page + 1) . '&action=' . urlencode($filter))) ?>">Older</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>
