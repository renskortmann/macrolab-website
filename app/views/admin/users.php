<?php
/**
 * @var list<array<string, mixed>> $users
 * @var string|null                $inviteLink
 * @var string|null                $error
 * @var string                     $authMode
 */

use Macrolab\Clock;
use Macrolab\Csrf;
use Macrolab\Role;
?>
<section class="card">
    <h1>Who may sign in</h1>

    <p class="muted small">
        This list is the access control. A netID that is not here cannot sign
        in - by password today, and by TU Delft SSO later. The role decides what
        a person may use: every role books machines, a <em>lab technician</em>
        also registers their own time, and a <em>lab manager</em> also sees and
        exports everyone's time.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($inviteLink !== null): ?>
        <div class="invite">
            <h2>Sign-in link</h2>
            <p>
                Send this to the person it belongs to, however you like. It works
                once, expires in a few days, and is not shown again.
            </p>
            <input class="invite-link" type="text" readonly value="<?= e($inviteLink) ?>"
                   aria-label="Single-use sign-in link">
        </div>
    <?php endif; ?>

    <h2>Add someone</h2>

    <form method="post" action="<?= e(path('/admin/users')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">

        <label for="netid">netID</label>
        <input id="netid" name="netid" type="text" required
               autocapitalize="none" spellcheck="false" placeholder="jdoe">

        <label for="display_name">Name <span class="muted">(optional)</span></label>
        <input id="display_name" name="display_name" type="text" placeholder="J. Doe">

        <label for="note">Note <span class="muted">(optional)</span></label>
        <input id="note" name="note" type="text" placeholder="trained 2026-09-01">

        <label for="role">Role</label>
        <select id="role" name="role">
            <?php foreach (Role::cases() as $role): ?>
                <option value="<?= e($role->value) ?>" <?= $role === Role::LabUser ? 'selected' : '' ?>>
                    <?= e($role->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="primary">Add and create a link</button>
    </form>
</section>

<section class="card">
    <h2>On the list</h2>

    <?php if ($users === []): ?>
        <p class="muted">Nobody yet.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr>
                <th>netID</th><th>Name</th><th>Role</th><th>Status</th><th>Password</th>
                <th>Bookings</th><th>Time entries</th><th>Last signed in</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <tr class="<?= $row['status'] === 'suspended' ? 'row-suspended' : '' ?>">
                    <td><code><?= e($row['netid']) ?></code></td>
                    <td>
                        <?= e($row['display_name'] ?? '-') ?>
                        <?php if (!empty($row['note'])): ?>
                            <br><span class="muted small"><?= e($row['note']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= e(path('/admin/users')) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= e($row['id']) ?>">
                            <select name="role" aria-label="Role of <?= e($row['netid']) ?>">
                                <?php foreach (Role::cases() as $role): ?>
                                    <option value="<?= e($role->value) ?>"
                                        <?= $role->value === $row['role'] ? 'selected' : '' ?>>
                                        <?= e($role->label()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="action" value="role" class="link">Change</button>
                        </form>
                    </td>
                    <td><?= e($row['status']) ?></td>
                    <td>
                        <?php if ((int) $row['has_password'] === 1): ?>
                            set
                        <?php elseif (!empty($row['pending_invite_expires'])): ?>
                            <span class="muted">link valid until
                                <?= e(Clock::local(Clock::fromSql((string) $row['pending_invite_expires']), 'j M H:i')) ?></span>
                        <?php else: ?>
                            <span class="alert-inline">no password, no link</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['booking_count']) ?></td>
                    <td><?= e($row['time_entry_count']) ?></td>
                    <td><?= $row['last_login_at'] !== null
                            ? e(Clock::local(Clock::fromSql((string) $row['last_login_at']), 'j M Y H:i'))
                            : '<span class="muted">never</span>' ?></td>
                    <td class="actions">
                        <form method="post" action="<?= e(path('/admin/users')) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= e($row['id']) ?>">
                            <button type="submit" name="action" value="invite" class="link">
                                <?= (int) $row['has_password'] === 1 ? 'Reset password' : 'New link' ?>
                            </button>
                        </form>

                        <form method="post" action="<?= e(path('/admin/users')) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= e($row['id']) ?>">
                            <?php if ($row['status'] === 'suspended'): ?>
                                <button type="submit" name="action" value="reinstate" class="link">Reinstate</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="suspend" class="link">Suspend</button>
                            <?php endif; ?>
                        </form>

                        <?php /* Both restrict at the database, so both gate the button. */ ?>
                        <?php if ((int) $row['booking_count'] === 0 && (int) $row['time_entry_count'] === 0): ?>
                            <form method="post" action="<?= e(path('/admin/users')) ?>" class="inline"
                                  data-confirm="Remove <?= e($row['netid']) ?> from the allowlist?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="user_id" value="<?= e($row['id']) ?>">
                                <button type="submit" name="action" value="delete" class="link danger">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
