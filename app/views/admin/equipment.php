<?php
/**
 * @var list<array<string, mixed>> $equipmentList
 * @var string|null                $error
 */

use Macrolab\Csrf;
?>
<section class="card">
    <h1>Equipment</h1>

    <p class="muted small">
        Every booking belongs to one piece of equipment. Members choose which
        calendar they are looking at from the list at the top of the booking
        page.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Add a piece of equipment</h2>

    <form method="post" action="<?= e(path('/admin/equipment')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">

        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="128" placeholder="Optical bench 2">

        <label for="description">Description <span class="muted">(optional)</span></label>
        <input id="description" name="description" type="text" placeholder="second optical bench">

        <button type="submit" class="primary">Add</button>
    </form>
</section>

<section class="card">
    <h2>On the list</h2>

    <?php if ($equipmentList === []): ?>
        <p class="muted">No equipment yet.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>Name</th><th>Address</th><th>Status</th><th>Bookings</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($equipmentList as $piece): ?>
                <?php $active = (int) $piece['is_active'] === 1; ?>
                <tr class="<?= $active ? '' : 'row-suspended' ?>">
                    <td>
                        <form method="post" action="<?= e(path('/admin/equipment')) ?>" class="inline-edit">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="equipment_id" value="<?= e($piece['id']) ?>">
                            <input type="text" name="name" aria-label="Name" required maxlength="128"
                                   value="<?= e($piece['name']) ?>">
                            <input type="text" name="description" aria-label="Description"
                                   value="<?= e($piece['description'] ?? '') ?>">
                            <button type="submit" name="action" value="update" class="link">Save</button>
                        </form>
                    </td>
                    <td><code class="small">?equipment=<?= e($piece['slug']) ?></code></td>
                    <td><?= $active ? 'in use' : 'retired' ?></td>
                    <td><?= e($piece['booking_count']) ?></td>
                    <td class="actions">
                        <form method="post" action="<?= e(path('/admin/equipment')) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="equipment_id" value="<?= e($piece['id']) ?>">
                            <?php if ($active): ?>
                                <button type="submit" name="action" value="deactivate" class="link"
                                        data-confirm="Retire <?= e($piece['name']) ?>? Its bookings stay on record, but nobody can make new ones.">
                                    Retire
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="reactivate" class="link">Put back in use</button>
                            <?php endif; ?>
                        </form>

                        <?php if ((int) $piece['booking_count'] === 0): ?>
                            <form method="post" action="<?= e(path('/admin/equipment')) ?>" class="inline"
                                  data-confirm="Remove <?= e($piece['name']) ?> completely?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="equipment_id" value="<?= e($piece['id']) ?>">
                                <button type="submit" name="action" value="delete" class="link danger">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted small">
            A piece of equipment with bookings on record cannot be removed -
            retire it instead, so the history of who used what stays intact.
        </p>
    <?php endif; ?>
</section>
