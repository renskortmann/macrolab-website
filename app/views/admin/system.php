<?php
/**
 * @var list<array{label: string, ok: bool, detail: string, fatal: bool}> $checks
 * @var list<string>                                  $applied
 * @var list<string>                                  $pending
 * @var int                                           $recoveryLeft
 * @var list<string>                                  $newCodes
 * @var array{secret: string, uri: string}|null       $newTotp
 * @var string|null                                   $newTotpQr
 * @var int                                           $minimum
 * @var string|null                                   $error
 */

use Macrolab\Csrf;
?>
<section class="card">
    <h1>System</h1>

    <p class="muted small">
        Maintenance that needs no shell: database updates, a check of this
        server, and your own sign-in.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
</section>

<?php if ($newCodes !== []): ?>
    <section class="card">
        <h2>Your new recovery codes</h2>
        <p class="alert">
            Shown once. The previous set no longer works. Store these somewhere
            other than alongside your password.
        </p>
        <ul class="codes">
            <?php foreach ($newCodes as $code): ?>
                <li><code><?= e($code) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($newTotp !== null): ?>
    <section class="card">
        <h2>Re-enrol your authenticator</h2>
        <p class="alert">
            Scan this now. Your old authenticator entry no longer works, and
            this code is shown once.
        </p>
        <div class="qr"><?= $newTotpQr /* generated SVG */ ?></div>
        <p>Or enter the key by hand: <code class="secret"><?= e($newTotp['secret']) ?></code></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Database schema</h2>

    <p class="muted small">
        An update to the application occasionally brings a schema change. This
        is where it is applied - no shell needed.
    </p>

    <?php if ($pending === []): ?>
        <p>Up to date<?= $applied === [] ? '' : ' (' . e(implode(', ', $applied)) . ')' ?>.</p>
    <?php else: ?>
        <p class="alert">
            Waiting to be applied: <?= e(implode(', ', $pending)) ?>
        </p>
        <form method="post" action="<?= e(path('/admin/system')) ?>"
              data-confirm="Apply the pending database migrations now? Take a backup first.">
            <?= Csrf::field() ?>
            <button type="submit" name="action" value="migrate" class="primary">Apply migrations</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>This server</h2>

    <table>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td class="nowrap"><?= e($check['label']) ?></td>
                <td class="nowrap">
                    <?php if ($check['ok']): ?>
                        ok
                    <?php elseif ($check['fatal']): ?>
                        <strong class="alert-inline">problem</strong>
                    <?php else: ?>
                        <span class="muted">note</span>
                    <?php endif; ?>
                </td>
                <td class="muted small"><?= e($check['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2>My sign-in</h2>

    <h3>Password</h3>

    <form method="post" action="<?= e(path('/admin/system')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="change_password">

        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password"
               autocomplete="current-password" required>

        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password"
               autocomplete="new-password" required minlength="<?= e($minimum) ?>">

        <label for="new_password_confirm">New password again</label>
        <input id="new_password_confirm" name="new_password_confirm" type="password"
               autocomplete="new-password" required minlength="<?= e($minimum) ?>">

        <button type="submit" class="primary">Change password</button>
    </form>

    <h3>Recovery codes</h3>

    <p>
        <?= e($recoveryLeft) ?> unused code<?= $recoveryLeft === 1 ? '' : 's' ?> left.
        <?php if ($recoveryLeft <= 2): ?>
            <span class="alert-inline">Issue a new set.</span>
        <?php endif; ?>
    </p>

    <form method="post" action="<?= e(path('/admin/system')) ?>"
          data-confirm="Issue a new set of recovery codes? The current ones stop working.">
        <?= Csrf::field() ?>
        <button type="submit" name="action" value="regenerate_codes">Issue new recovery codes</button>
    </form>

    <h3>Authenticator</h3>

    <p class="muted small">
        Use this if you change phone, or think the secret may have been exposed.
        Your recovery codes are unaffected.
    </p>

    <form method="post" action="<?= e(path('/admin/system')) ?>"
          data-confirm="Re-enrol your authenticator? The current entry stops working immediately.">
        <?= Csrf::field() ?>
        <button type="submit" name="action" value="reset_totp" class="danger">Re-enrol authenticator</button>
    </form>
</section>
