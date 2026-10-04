<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use DateTimeImmutable;
use Macrolab\Actor;
use Macrolab\AdminAuth;
use Macrolab\Audit;
use Macrolab\Auth;
use Macrolab\Booking\BookingException;
use Macrolab\Booking\BookingService;
use Macrolab\Booking\Bookings;
use Macrolab\Clock;
use Macrolab\Config;
use Macrolab\Csrf;
use Macrolab\Db;
use Macrolab\Environment;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Invite;
use Macrolab\Migrator;
use Macrolab\Password;
use Macrolab\Qr;
use Macrolab\RateLimit;
use Macrolab\Role;
use Macrolab\Booking\Resources;
use Macrolab\Session;
use Macrolab\Settings;
use Macrolab\Users;
use Macrolab\View;
use RuntimeException;

/**
 * The administrator's side of the system: the allowlist, every booking, the
 * booking rules and the audit log.
 *
 * The admin signs in here with a password and a one-time code, never through
 * TU Delft SSO, so the lab keeps a way in that does not depend on a service
 * outside the lab.
 */
final class AdminController
{
    // ------------------------------------------------------------------ login

    public function login(Request $request): Response
    {
        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }

        if (!AdminAuth::exists()) {
            return View::page('admin/no_account', ['title' => 'No administrator yet'], 503);
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            $username = $request->post('username', '') ?? '';
            $password = (string) ($request->post['password'] ?? '');

            RateLimit::assertAllowed('admin:' . strtolower($username));

            $account = AdminAuth::verifyPassword($username, $password);

            if ($account === null) {
                $error = 'Incorrect username or password.';
            } else {
                // The password alone establishes nothing: the session is only
                // marked as "owes a one-time code".
                Auth::setAdminPending((int) $account['id']);

                return Response::redirect('/admin/login/2fa');
            }
        }

        return View::page('admin/login', [
            'title'    => 'Administrator sign-in',
            'username' => $request->post('username', '') ?? '',
            'error'    => $error,
        ], $error !== null ? 422 : 200);
    }

    public function twoFactor(Request $request): Response
    {
        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }

        $adminId = Auth::adminPendingId();

        if ($adminId === null) {
            return Response::redirect('/admin/login');
        }

        $account = AdminAuth::findById($adminId);

        if ($account === null) {
            Auth::logout();

            return Response::redirect('/admin/login');
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            $code = (string) ($request->post['code'] ?? '');
            $username = (string) $account['username'];

            RateLimit::assertAllowed('admin:' . $username);

            $ok = AdminAuth::verifyTotp($adminId, $code)
                || AdminAuth::verifyRecoveryCode($adminId, $code);

            if ($ok) {
                AdminAuth::markTotpConfirmed($adminId);
                Auth::completeAdminLogin($adminId, $username);
                RateLimit::record('admin:' . $username, true);
                RateLimit::clear('admin:' . $username);
                Audit::log('admin_login', 'admin', $adminId, ['username' => $username],
                    actorType: 'admin', actorLabel: 'admin:' . $username);

                return Response::redirect('/admin');
            }

            $error = 'That code is not valid. Check your authenticator app, or use a recovery code.';
        }

        return View::page('admin/two_factor', [
            'title' => 'One-time code',
            'error' => $error,
        ], $error !== null ? 422 : 200);
    }

    public function logout(Request $request): Response
    {
        Csrf::verify($request);
        Auth::logout();

        return Response::redirect('/admin/login');
    }

    // -------------------------------------------------------------- dashboard

    public function dashboard(Request $request): Response
    {
        Auth::requireAdmin();

        $from = Clock::now()->setTimezone(Clock::displayZone())->setTime(0, 0)->setTimezone(Clock::utc());
        $to = Clock::now()->setTimezone(Clock::displayZone())
            ->setTime(0, 0)->modify('+2 days')->setTimezone(Clock::utc());

        $upcoming = [];
        foreach (Resources::allActive() as $machine) {
            foreach (Bookings::inWindow((int) $machine['id'], $from, $to) as $booking) {
                $upcoming[] = $booking;
            }
        }

        usort($upcoming, static fn ($a, $b): int => $a->startsAt <=> $b->startsAt);

        return View::page('admin/dashboard', [
            'title'         => 'Administration',
            'machines'      => Resources::allActive(),
            'machineCount'  => Resources::countActive(),
            'upcoming'      => $upcoming,
            'userCount'     => (int) Db::get()->value('SELECT COUNT(*) FROM users'),
            'suspended'     => (int) Db::get()->value('SELECT COUNT(*) FROM users WHERE status = "suspended"'),
            'noPassword'    => (int) Db::get()->value('SELECT COUNT(*) FROM users WHERE password_hash IS NULL'),
            'authMode'      => Settings::authMode(),
            'recoveryLeft'  => AdminAuth::countUnusedRecoveryCodes((int) (Auth::adminId() ?? 0)),
            'passwordAlgo'  => Password::algorithm(),
        ]);
    }

    // --------------------------------------------------------------- allowlist

    public function users(Request $request): Response
    {
        Auth::requireAdmin();

        $inviteLink = null;
        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $inviteLink = $this->handleUserAction($request);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }

            if ($error === null && $inviteLink === null) {
                return Response::redirect('/admin/users');
            }
        }

        return View::page('admin/users', [
            'title'      => 'Who may sign in',
            'users'      => Users::listAll(),
            'inviteLink' => $inviteLink,
            'error'      => $error,
            'authMode'   => Settings::authMode(),
        ], $error !== null ? 422 : 200);
    }

    /**
     * @return string|null an invite link to show once, when one was issued
     */
    private function handleUserAction(Request $request): ?string
    {
        $action = $request->post('action', '') ?? '';

        if ($action === 'add') {
            $netid = Users::normaliseNetid($request->post('netid', '') ?? '');

            if (!Users::isValidNetid($netid)) {
                throw new RuntimeException('That does not look like a netID.');
            }

            if (Users::findByNetid($netid) !== null) {
                throw new RuntimeException('"' . $netid . '" is already on the list.');
            }

            $role = self::roleFrom($request);
            $user = Users::create($netid, $request->post('display_name'), $request->post('note'), $role);
            Audit::log('user_added', 'user', $user->id, ['netid' => $netid, 'role' => $role->value]);

            // Straight into an invite link, because an account with no password
            // and no link is of no use to anyone.
            $token = Invite::issue($user->id, Invite::PURPOSE_SETUP);
            Session::flash('success', 'Added ' . $netid . '. Send them the link below.');

            return Invite::urlFor($token);
        }

        $userId = (int) ($request->post('user_id', '0') ?? '0');
        $user = $userId > 0 ? Users::findById($userId) : null;

        if ($user === null) {
            throw new RuntimeException('That user no longer exists.');
        }

        switch ($action) {
            case 'invite':
                $token = Invite::issue($user->id,
                    $user->hasPassword() ? Invite::PURPOSE_RESET : Invite::PURPOSE_SETUP);
                Session::flash('success',
                    'New link for ' . $user->netid . '. Any earlier link no longer works.');

                return Invite::urlFor($token);

            case 'suspend':
                Users::setStatus($user->id, 'suspended');
                Audit::log('user_suspended', 'user', $user->id, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' can no longer sign in.');

                return null;

            case 'reinstate':
                Users::setStatus($user->id, 'approved');
                Audit::log('user_reinstated', 'user', $user->id, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' can sign in again.');

                return null;

            case 'role':
                $role = self::roleFrom($request);

                if ($role !== $user->role) {
                    Users::setRole($user->id, $role);
                    Audit::log('user_role_changed', 'user', $user->id, [
                        'netid' => $user->netid,
                        'from'  => $user->role->value,
                        'to'    => $role->value,
                    ]);
                }
                Session::flash('success', $user->netid . ' is now a ' . strtolower($role->label()) . '.');

                return null;

            case 'update':
                Users::updateProfile($user->id, $request->post('display_name'), $request->post('note'));
                Audit::log('user_updated', 'user', $user->id, ['netid' => $user->netid]);

                return null;

            case 'delete':
                // Bookings and time entries both name their owner, so an
                // account with either kind of history is suspended rather than
                // deleted. Both foreign keys restrict, so skipping one of these
                // checks means the database refuses and the admin gets a 500
                // instead of an explanation.
                if (Users::countBookings($user->id) > 0) {
                    throw new RuntimeException(
                        $user->netid . ' has bookings on record. Suspend the account instead of deleting it, '
                        . 'or delete those bookings first.'
                    );
                }

                if (Users::countTimeEntries($user->id) > 0) {
                    throw new RuntimeException(
                        $user->netid . ' has time registered. Suspend the account instead of deleting it: '
                        . 'those hours are a business record and are not thrown away with the account.'
                    );
                }

                Users::delete($user->id);
                Audit::log('user_deleted', 'user', null, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' has been removed.');

                return null;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }

    /** The role chosen in a form, refusing anything that is not one. */
    private static function roleFrom(Request $request): Role
    {
        $role = Role::tryFrom((string) ($request->post('role', '') ?? ''));

        if ($role === null) {
            throw new RuntimeException('Choose a role: lab user, lab technician or lab manager.');
        }

        return $role;
    }

    // ---------------------------------------------------------------- machines

    /**
     * The bookable machines. A machine with bookings on record is deactivated
     * rather than deleted, for the same reason a user with bookings is
     * suspended: the bookings must keep naming what they were for.
     */
    public function machines(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->handleMachineAction($request);

                return Response::redirect('/admin/machines');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/machines', [
            'title'    => 'Machines',
            'machines' => Resources::all(),
            'error'    => $error,
        ], $error !== null ? 422 : 200);
    }

    private function handleMachineAction(Request $request): void
    {
        $action = $request->post('action', '') ?? '';

        if ($action === 'add') {
            $machine = Resources::create(
                $request->post('name', '') ?? '',
                $request->post('description'),
            );
            Audit::log('machine_added', 'resource', (int) $machine['id'],
                ['name' => $machine['name'], 'slug' => $machine['slug']]);
            Session::flash('success', $machine['name'] . ' can now be booked.');

            return;
        }

        $machineId = (int) ($request->post('machine_id', '0') ?? '0');
        $machine = $machineId > 0 ? Resources::find($machineId) : null;

        if ($machine === null) {
            throw new RuntimeException('That machine no longer exists.');
        }

        $name = (string) $machine['name'];

        switch ($action) {
            case 'update':
                Resources::update($machineId, $request->post('name', '') ?? '', $request->post('description'));
                Audit::log('machine_updated', 'resource', $machineId, ['name' => $name]);
                Session::flash('success', 'Saved.');
                break;

            case 'deactivate':
                if (Resources::countActive() <= 1) {
                    throw new RuntimeException(
                        'This is the only machine still in use. Add another one before retiring ' . $name . '.'
                    );
                }

                Resources::setActive($machineId, false);
                Audit::log('machine_deactivated', 'resource', $machineId, ['name' => $name]);
                Session::flash('success', $name . ' can no longer be booked. Its bookings are untouched.');
                break;

            case 'reactivate':
                Resources::setActive($machineId, true);
                Audit::log('machine_reactivated', 'resource', $machineId, ['name' => $name]);
                Session::flash('success', $name . ' can be booked again.');
                break;

            case 'delete':
                if (Resources::countBookings($machineId) > 0) {
                    throw new RuntimeException(
                        $name . ' has bookings on record. Retire it instead of deleting it, '
                        . 'or delete those bookings first.'
                    );
                }

                Resources::delete($machineId);
                Audit::log('machine_deleted', 'resource', null, ['name' => $name]);
                Session::flash('success', $name . ' has been removed.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }

    // ---------------------------------------------------------------- bookings

    public function bookings(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->handleBookingAction($request);

                return Response::redirect('/admin/bookings');
            } catch (BookingException $e) {
                $error = implode(' ', $e->errors);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        // An empty filter means every machine.
        $filter = $request->query('machine', '') ?? '';
        $filtered = $filter === '' ? null : Resources::findBySlug($filter);

        return View::page('admin/bookings', [
            'title'    => 'All bookings',
            'bookings' => Bookings::recent($filtered === null ? null : (int) $filtered['id'], 300, true),
            'users'    => Users::listAll(),
            'machines' => Resources::all(),
            'filter'   => $filtered === null ? '' : (string) $filtered['slug'],
            'error'    => $error,
        ], $error !== null ? 422 : 200);
    }

    private function handleBookingAction(Request $request): void
    {
        $actor = Actor::forAdmin();
        $action = $request->post('action', '') ?? '';

        if ($action === 'create') {
            $start = self::readMoment($request, 'start');
            $end = self::readMoment($request, 'end');

            if ($start === null || $end === null) {
                throw new RuntimeException('Please give a start and an end time.');
            }

            $netid = $request->post('owner_netid', '') ?? '';
            $owner = Users::findByNetid($netid);

            if ($owner === null) {
                throw new RuntimeException('No user with netID "' . $netid . '" is on the allowlist.');
            }

            $machine = Resources::requireActive($request->post('machine'));

            BookingService::create($actor, (int) $machine['id'], $start, $end,
                $request->post('purpose'), $owner->id);
            Session::flash('success', 'Booking created for ' . $owner->netid . '.');

            return;
        }

        $booking = Bookings::find((int) ($request->post('booking_id', '0') ?? '0'));

        if ($booking === null) {
            throw new RuntimeException('That booking no longer exists.');
        }

        switch ($action) {
            case 'update':
                $start = self::readMoment($request, 'start');
                $end = self::readMoment($request, 'end');

                if ($start === null || $end === null) {
                    throw new RuntimeException('Please give a start and an end time.');
                }

                BookingService::update($actor, $booking, $start, $end, $request->post('purpose'));
                Session::flash('success', 'Booking updated.');
                break;

            case 'cancel':
                BookingService::cancel($actor, $booking);
                Session::flash('success', 'Booking cancelled.');
                break;

            case 'delete':
                BookingService::delete($actor, $booking);
                Session::flash('success', 'Booking deleted.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }

    /**
     * A moment from the split date and time fields the forms post. The time
     * comes from a dropdown the application renders, rather than a native time
     * input, so that it always reads as 24h whatever the browser's locale is.
     */
    private static function readMoment(Request $request, string $name): ?DateTimeImmutable
    {
        $date = $request->post($name . '_date', '') ?? '';
        $time = $request->post($name . '_time', '') ?? '';

        if ($date === '' || $time === '') {
            return null;
        }

        return Clock::parseInstant($date . ' ' . $time);
    }

    // ---------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->saveSettings($request);
                Session::flash('success', 'Settings saved.');

                return Response::redirect('/admin/settings');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/settings', [
            'title'    => 'Rules',
            'settings'     => Settings::all(),
            'authMode'     => Settings::authMode(),
            'ssoAvailable' => Settings::ssoAvailable(),
            'error'        => $error,
        ], $error !== null ? 422 : 200);
    }

    private function saveSettings(Request $request): void
    {
        $integers = [
            'slot_minutes'                 => [5, 24 * 60],
            'min_booking_minutes'          => [5, 24 * 60],
            // 31 days is the hard ceiling BookingService::assertSane() enforces.
            'max_booking_days'             => [1, 31],
            'max_advance_days'             => [1, 1095],
            'max_active_bookings_per_user' => [0, 100],
            'min_change_notice_minutes'    => [0, 7 * 24 * 60],
            'audit_retention_days'         => [30, 3650],

            // Time registration. Unrelated to the booking rules above.
            'time_min_entry_minutes'       => [1, 24 * 60],
            'time_max_entry_minutes'       => [1, 24 * 60],
            'time_max_day_minutes'         => [1, 24 * 60],
            'time_max_future_days'         => [0, 365],
            'time_max_backdate_days'       => [0, 3650],
        ];

        $values = [];

        foreach ($integers as $key => [$min, $max]) {
            $raw = $request->post($key);

            if ($raw === null || !ctype_digit($raw)) {
                throw new RuntimeException('"' . $key . '" must be a whole number.');
            }

            $value = (int) $raw;

            if ($value < $min || $value > $max) {
                throw new RuntimeException('"' . $key . '" must be between ' . $min . ' and ' . $max . '.');
            }

            $values[$key] = (string) $value;
        }

        if ((int) $values['min_booking_minutes'] > (int) $values['max_booking_days'] * 24 * 60) {
            throw new RuntimeException('The shortest booking cannot be longer than the longest booking.');
        }

        if ((int) $values['time_min_entry_minutes'] > (int) $values['time_max_entry_minutes']) {
            throw new RuntimeException('The shortest time entry cannot be longer than the longest one.');
        }

        if ((int) $values['time_max_entry_minutes'] > (int) $values['time_max_day_minutes']) {
            throw new RuntimeException('A single time entry cannot be longer than a whole day\'s limit.');
        }

        foreach (['open_time', 'close_time'] as $key) {
            $raw = $request->post($key, '') ?? '';

            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw) !== 1) {
                throw new RuntimeException('"' . $key . '" must be a time such as 08:00.');
            }

            $values[$key] = $raw;
        }

        if ($values['open_time'] >= $values['close_time']) {
            throw new RuntimeException('The opening time must be earlier than the closing time.');
        }

        $days = [];
        foreach ((array) ($request->post['open_days'] ?? []) as $day) {
            if (is_scalar($day) && (int) $day >= 1 && (int) $day <= 7) {
                $days[] = (int) $day;
            }
        }

        if ($days === []) {
            throw new RuntimeException('Please open at least one day of the week.');
        }

        sort($days);
        $values['open_days'] = implode(',', array_unique($days));
        $values['allow_booking_in_past'] = ($request->post['allow_booking_in_past'] ?? '') !== '' ? '1' : '0';

        $mode = $request->post('auth_mode', 'local') ?? 'local';

        if (!in_array($mode, ['local', 'saml', 'both'], true)) {
            throw new RuntimeException('Unknown sign-in mode.');
        }

        // Stage 2 is not built yet; refusing here prevents locking every lab
        // member out by selecting a mode the code cannot honour.
        if ($mode !== 'local' && !Settings::ssoAvailable()) {
            throw new RuntimeException(
                'TU Delft SSO is not available in this version of the application yet, '
                . 'so only password sign-in can be selected.'
            );
        }

        $values['auth_mode'] = $mode;

        $before = Settings::all();
        Settings::setMany($values);

        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        if ($changed !== []) {
            Audit::log('settings_changed', 'settings', null, $changed);
        }
    }

    // ------------------------------------------------------------------- audit

    public function audit(Request $request): Response
    {
        Auth::requireAdmin();

        $action = $request->query('action', '') ?? '';
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $perPage = 100;

        $where = '';
        $params = [];

        if ($action !== '') {
            $where = ' WHERE action = ?';
            $params[] = $action;
        }

        $total = (int) Db::get()->value('SELECT COUNT(*) FROM audit_log' . $where, $params);
        $rows = Db::get()->all(
            'SELECT id, actor_type, actor_id, actor_label, action, target_type, target_id,
                    details, ip, created_at
               FROM audit_log' . $where . '
              ORDER BY id DESC
              LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return View::page('admin/audit', [
            'title'   => 'Audit log',
            'rows'    => $rows,
            'actions' => array_column(
                Db::get()->all('SELECT DISTINCT action FROM audit_log ORDER BY action'),
                'action'
            ),
            'filter'  => $action,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / $perPage)),
            'total'   => $total,
        ]);
    }

    // ------------------------------------------------------------------ system

    /**
     * Maintenance the administrator can do without a shell: apply a migration
     * that came with an update, change their own password, re-enrol their
     * authenticator, and issue fresh recovery codes.
     *
     * This is behind the administrator sign-in, unlike /install, so it needs no
     * token and does not go away.
     */
    public function system(Request $request): Response
    {
        Auth::requireAdmin();

        $adminId = Auth::adminId();
        $migrator = new Migrator(Db::get());

        $error = null;
        $newCodes = [];
        $newTotp = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                switch ($request->post('action', '') ?? '') {
                    case 'migrate':
                        $applied = $migrator->migrate();
                        Audit::log('migrations_applied', 'system', null, ['migrations' => $applied]);
                        Session::flash('success', $applied === []
                            ? 'The schema was already up to date.'
                            : 'Applied: ' . implode(', ', $applied) . '.');

                        return Response::redirect('/admin/system');

                    case 'change_password':
                        $current = (string) ($request->post['current_password'] ?? '');
                        $account = AdminAuth::findById((int) $adminId);

                        if ($account === null
                            || !Password::verify($current, (string) $account['password_hash'])) {
                            throw new RuntimeException('Your current password is not correct.');
                        }

                        $new = (string) ($request->post['new_password'] ?? '');

                        if ($new !== (string) ($request->post['new_password_confirm'] ?? '')) {
                            throw new RuntimeException('The two new passwords do not match.');
                        }

                        AdminAuth::changePassword((int) $adminId, $new);
                        Session::flash('success', 'Your password has been changed.');

                        return Response::redirect('/admin/system');

                    case 'regenerate_codes':
                        $newCodes = AdminAuth::regenerateRecoveryCodes((int) $adminId);
                        Audit::log('admin_recovery_codes_regenerated', 'admin', $adminId);
                        break;

                    case 'reset_totp':
                        $newTotp = AdminAuth::resetTotp((int) $adminId, (string) Auth::adminUsername());
                        break;

                    default:
                        throw new RuntimeException('Unknown action.');
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/system', [
            'title'        => 'System',
            'checks'       => Environment::checks(),
            'applied'      => $migrator->applied(),
            'pending'      => $migrator->pending(),
            'recoveryLeft' => AdminAuth::countUnusedRecoveryCodes((int) $adminId),
            'newCodes'     => $newCodes,
            'newTotp'      => $newTotp,
            'newTotpQr'    => $newTotp === null ? null : Qr::svg($newTotp['uri']),
            'minimum'      => Config::int('auth.password_min_length', 12),
            'error'        => $error,
        ], $error !== null ? 422 : 200);
    }

    // --------------------------------------------------------------- installer

    /**
     * The browser installer: loads the schema and creates the administrator.
     *
     * The TU Delft hosting is operated through the Plesk panel without a
     * shell, so this, not the command line, is the normal way to install.
     *
     * Two things keep it from being a way in:
     *   - it requires app.install_token, which the operator sets when creating
     *     app/config.php, before the site is reachable over HTTPS, closing the
     *     window between deployment and installation;
     *   - it stops existing the moment an administrator account exists.
     */
    public function install(Request $request): Response
    {
        $token = (string) Config::get('app.install_token', '');

        if ($token === '') {
            // Nothing to compare against: say so rather than 404, because this
            // is the operator's own omission and it is not a secret.
            return View::page('admin/install_disabled', [
                'title' => 'Installer not enabled',
            ], 503);
        }

        $migrator = new Migrator(Db::get());

        // The throttle keeps its count in login_attempts, which the installer
        // itself creates: on a fresh database there is nothing to count in yet,
        // and querying it would fail before the schema could ever be loaded.
        // Until then the long random token is the only protection, and enough.
        $throttled = $migrator->hasTable('login_attempts');

        if ($throttled) {
            RateLimit::assertAllowed('install');
        }

        $provided = (string) ($request->post('install_token') ?? $request->query('token') ?? '');

        if (!hash_equals($token, $provided)) {
            if ($throttled) {
                RateLimit::record('install', false);
            }
            // Audit::log() tolerates a missing audit_log table.
            Audit::log('install_token_rejected', 'system', null, [],
                actorType: 'anonymous', actorLabel: 'anonymous');

            // Wrong or missing token: reveal nothing at all.
            throw HttpException::notFound();
        }

        if ($throttled) {
            RateLimit::record('install', true);
            RateLimit::clear('install');
        }

        if ($migrator->hasTable('admin_account') && AdminAuth::exists()) {
            throw HttpException::notFound();
        }

        $error = null;
        $created = null;
        $migrated = [];

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                if (Environment::anyFatal()) {
                    throw new RuntimeException(
                        'Some requirements are not met. Fix the items marked as problems below, then try again.'
                    );
                }

                $migrated = $migrator->migrate();

                $created = AdminAuth::create(
                    $request->post('username', '') ?? '',
                    (string) ($request->post['password'] ?? '')
                );
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($created !== null) {
            Audit::log('installed', 'system', null, ['migrations' => $migrated],
                actorType: 'system', actorLabel: 'installer');

            return View::page('admin/installed', [
                'title'    => 'Administrator created',
                'secret'   => $created['secret'],
                'qr'       => Qr::svg($created['uri']),
                'codes'    => $created['recovery_codes'],
                'migrated' => $migrated,
            ]);
        }

        return View::page('admin/install', [
            'title'    => 'Install the Macrolab website',
            'error'    => $error,
            'minimum'  => Config::int('auth.password_min_length', 12),
            'checks'   => Environment::checks(),
            'pending'  => $migrator->pending(),
            'applied'  => $migrator->applied(),
            'token'    => $provided,
        ], $error !== null ? 422 : 200);
    }
}
