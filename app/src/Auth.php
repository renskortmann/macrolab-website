<?php

declare(strict_types=1);

namespace Macrolab;

use Macrolab\Auth\AccessDeniedException;
use Macrolab\Auth\Identity;
use Macrolab\Http\HttpException;

/**
 * Who is signed in, and the one gate that decides whether a verified identity
 * is allowed in at all.
 *
 * That gate is here rather than in an authentication provider on purpose. It
 * means the allowlist cannot be bypassed by a bug in one provider, and that
 * enabling TU Delft SSO later adds no new place where access is granted.
 */
final class Auth
{
    private const USER_ID        = 'auth_user_id';
    private const METHOD         = 'auth_method';
    private const ADMIN_ID       = 'auth_admin_id';
    private const ADMIN_USERNAME = 'auth_admin_username';
    private const ADMIN_PENDING  = 'auth_admin_pending_id';

    private static ?User $cachedUser = null;
    private static bool $userLookedUp = false;

    /**
     * Establish a session for a verified identity.
     *
     * @throws AccessDeniedException when the netID is not on the allowlist or
     *                               has been suspended
     */
    public static function signIn(Identity $identity): User
    {
        $netid = Users::normaliseNetid($identity->netid);
        $user = Users::findByNetid($netid);

        if ($user === null) {
            Audit::log('login_denied_not_allowlisted', 'user', null, [
                'netid'  => $netid,
                'method' => $identity->method,
            ], actorType: 'anonymous', actorLabel: $netid);

            throw AccessDeniedException::notAllowlisted($netid);
        }

        if (!$user->isApproved()) {
            Audit::log('login_denied_suspended', 'user', $user->id, [
                'netid'  => $netid,
                'method' => $identity->method,
            ], actorType: 'anonymous', actorLabel: $netid);

            throw AccessDeniedException::suspended($netid);
        }

        // New session id and new CSRF token at the moment privileges change.
        Session::regenerate();
        Csrf::rotate();

        Session::forget(self::ADMIN_ID);
        Session::forget(self::ADMIN_USERNAME);
        Session::forget(self::ADMIN_PENDING);
        Session::set(self::USER_ID, $user->id);
        Session::set(self::METHOD, $identity->method);

        Users::recordLogin($user, $identity);
        self::$cachedUser = null;
        self::$userLookedUp = false;

        Audit::log('login', 'user', $user->id, ['method' => $identity->method],
            actorType: 'user', actorId: $user->id, actorLabel: $user->netid);

        return $user;
    }

    /** How the current user signed in: 'local' or 'saml'. */
    public static function method(): ?string
    {
        $method = Session::get(self::METHOD);

        return is_string($method) ? $method : null;
    }

    public static function user(): ?User
    {
        if (self::$userLookedUp) {
            return self::$cachedUser;
        }

        self::$userLookedUp = true;
        self::$cachedUser = null;

        $id = Session::get(self::USER_ID);

        if (!is_int($id)) {
            return null;
        }

        if (!self::sessionAlive('user')) {
            return null;
        }

        $user = Users::findById($id);

        // The allowlist is re-checked on every request, so suspending or
        // deleting an account takes effect immediately rather than at the end
        // of that person's session.
        if ($user === null || !$user->isApproved()) {
            self::logout();

            return null;
        }

        return self::$cachedUser = $user;
    }

    public static function isAdmin(): bool
    {
        return Session::get(self::ADMIN_ID) !== null && self::sessionAlive('admin');
    }

    public static function adminUsername(): ?string
    {
        $username = Session::get(self::ADMIN_USERNAME);

        return is_string($username) ? $username : null;
    }

    public static function adminId(): ?int
    {
        $id = Session::get(self::ADMIN_ID);

        return is_int($id) && self::isAdmin() ? $id : null;
    }

    /** Admin has passed the password step and still owes a TOTP code. */
    public static function setAdminPending(int $adminId): void
    {
        Session::regenerate();
        Session::set(self::ADMIN_PENDING, $adminId);
    }

    public static function adminPendingId(): ?int
    {
        $id = Session::get(self::ADMIN_PENDING);

        return is_int($id) ? $id : null;
    }

    /** Both admin factors are satisfied. */
    public static function completeAdminLogin(int $adminId, string $username): void
    {
        Session::regenerate();
        Csrf::rotate();

        Session::forget(self::ADMIN_PENDING);
        Session::forget(self::USER_ID);
        Session::forget(self::METHOD);
        Session::set(self::ADMIN_ID, $adminId);
        Session::set(self::ADMIN_USERNAME, $username);

        self::$cachedUser = null;
        self::$userLookedUp = false;
    }

    public static function actor(): ?Actor
    {
        if (self::isAdmin()) {
            return Actor::forAdmin();
        }

        $user = self::user();

        return $user === null ? null : Actor::forUser($user);
    }

    /** Anyone signed in: a lab member or the admin. */
    public static function requireActor(): Actor
    {
        $actor = self::actor();

        if ($actor === null) {
            throw HttpException::unauthorized();
        }

        return $actor;
    }

    public static function requireUser(): User
    {
        $user = self::user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /**
     * A member whose role includes time registration. Signed out is a 401
     * (back to the sign-in page); a lab user or the administrator - who keeps
     * no timesheet - is a 403, not a confusing bounce to the member sign-in.
     */
    public static function requireTimeRegistration(): Actor
    {
        $actor = self::requireActor();

        if (!$actor->canRegisterTime()) {
            throw HttpException::forbidden('Time registration is for lab technicians and lab managers.');
        }

        return $actor;
    }

    /** The administrator or a lab manager: everyone's time, read-only. */
    public static function requireTimeOverview(): Actor
    {
        $actor = self::requireActor();

        if (!$actor->canViewAllTime()) {
            throw HttpException::forbidden('The time overview is for lab managers and the administrator.');
        }

        return $actor;
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            throw HttpException::forbidden('This page is for the lab administrator.');
        }
    }

    public static function logout(): void
    {
        $user = is_int(Session::get(self::USER_ID)) ? Session::get(self::USER_ID) : null;
        $wasAdmin = Session::get(self::ADMIN_ID) !== null;

        if ($user !== null || $wasAdmin) {
            Audit::log('logout', $wasAdmin ? 'admin' : 'user', is_int($user) ? $user : null);
        }

        Session::destroy();
        self::$cachedUser = null;
        self::$userLookedUp = true;
    }

    /** Forget any memoised state. Tests only. */
    public static function resetCache(): void
    {
        self::$cachedUser = null;
        self::$userLookedUp = false;
    }

    /**
     * Enforce the idle and absolute session limits for the given kind of
     * session, destroying it when either is exceeded.
     */
    private static function sessionAlive(string $kind): bool
    {
        $idle = Config::int(
            $kind === 'admin' ? 'auth.admin_session_idle_minutes' : 'auth.user_session_idle_minutes',
            24 // the host's session lifetime; see app/config.example.php
        );
        $absolute = Config::int(
            $kind === 'admin' ? 'auth.admin_session_absolute_minutes' : 'auth.user_session_absolute_minutes',
            $kind === 'admin' ? 480 : 720
        );

        if (Session::isAlive($idle, $absolute)) {
            return true;
        }

        Session::destroy();
        self::$cachedUser = null;
        self::$userLookedUp = true;

        return false;
    }
}
