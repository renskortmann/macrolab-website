<?php

declare(strict_types=1);

namespace Macrolab;

use Macrolab\Auth\Identity;

/**
 * Every query against the `users` table. That table is the access allowlist:
 * creating a row here is what grants access, and removing or suspending it is
 * what takes it away - for both authentication methods.
 */
final class Users
{
    private const COLUMNS = 'id, netid, display_name, email, status, role, password_hash,
                             saml_name_id, note, created_at, first_login_at, last_login_at';

    public static function findByNetid(string $netid): ?User
    {
        $row = Db::get()->one(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE netid = ?',
            [self::normaliseNetid($netid)]
        );

        return $row === null ? null : User::fromRow($row);
    }

    public static function findById(int $id): ?User
    {
        $row = Db::get()->one('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Allowlist rows for the admin screen, with the two counts that page shows.
     *
     * @return list<array<string, mixed>>
     */
    public static function listAll(): array
    {
        return Db::get()->all(
            'SELECT u.id, u.netid, u.display_name, u.email, u.status, u.role,
                    u.password_hash IS NOT NULL AS has_password,
                    u.note, u.created_at, u.first_login_at, u.last_login_at,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.user_id = u.id AND b.status = "confirmed") AS booking_count,
                    (SELECT COUNT(*) FROM time_entries t
                      WHERE t.user_id = u.id) AS time_entry_count,
                    (SELECT MIN(i.expires_at) FROM user_invites i
                      WHERE i.user_id = u.id AND i.used_at IS NULL AND i.expires_at > ?)
                        AS pending_invite_expires
               FROM users u
              ORDER BY u.netid',
            [Clock::sql()]
        );
    }

    /**
     * Add a netID to the allowlist. The account has no password yet; the admin
     * hands out an invite link, and the user chooses their own.
     */
    public static function create(
        string $netid,
        ?string $displayName = null,
        ?string $note = null,
        Role $role = Role::LabUser,
    ): User {
        $netid = self::normaliseNetid($netid);

        Db::get()->insert('users', [
            'netid'        => $netid,
            'display_name' => $displayName !== '' ? $displayName : null,
            'note'         => $note !== '' ? $note : null,
            'status'       => 'approved',
            'role'         => $role->value,
            'created_at'   => Clock::sql(),
        ]);

        $user = self::findByNetid($netid);

        if ($user === null) {
            throw new \RuntimeException('User row disappeared immediately after insert: ' . $netid);
        }

        return $user;
    }

    public static function setPasswordHash(int $userId, string $hash): void
    {
        Db::get()->update('users', [
            'password_hash'       => $hash,
            'password_changed_at' => Clock::sql(),
        ], 'id = ?', [$userId]);
    }

    public static function setStatus(int $userId, string $status): void
    {
        Db::get()->update('users', ['status' => $status], 'id = ?', [$userId]);
    }

    public static function setRole(int $userId, Role $role): void
    {
        Db::get()->update('users', ['role' => $role->value], 'id = ?', [$userId]);
    }

    public static function updateProfile(int $userId, ?string $displayName, ?string $note): void
    {
        Db::get()->update('users', [
            'display_name' => $displayName !== '' ? $displayName : null,
            'note'         => $note !== '' ? $note : null,
        ], 'id = ?', [$userId]);
    }

    /**
     * Remove an account. Refused while it still owns bookings, because the
     * bookings must keep naming their owner; suspend instead.
     */
    public static function delete(int $userId): void
    {
        Db::get()->query('DELETE FROM users WHERE id = ?', [$userId]);
    }

    public static function countBookings(int $userId): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [$userId]);
    }

    /**
     * Time entries name their owner and the foreign key restricts, so this is
     * checked before a delete for the same reason bookings are: without it the
     * database refuses and the admin sees a 500 rather than an explanation.
     */
    public static function countTimeEntries(int $userId): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM time_entries WHERE user_id = ?', [$userId]);
    }

    /**
     * Fill in whatever the identity provider told us that we do not have yet,
     * and stamp the login timestamps.
     *
     * Details already on record are not overwritten by a later login, except
     * the SAML NameID, which the identity provider owns.
     */
    public static function recordLogin(User $user, Identity $identity): void
    {
        $now = Clock::sql();
        $fields = ['last_login_at' => $now];

        if ($user->firstLoginAt === null) {
            $fields['first_login_at'] = $now;
        }

        if (($user->displayName === null || $user->displayName === '') && $identity->displayName !== null) {
            $fields['display_name'] = $identity->displayName;
        }

        if (($user->email === null || $user->email === '') && $identity->email !== null) {
            $fields['email'] = $identity->email;
        }

        if ($identity->samlNameId !== null && $identity->samlNameId !== $user->samlNameId) {
            $fields['saml_name_id'] = $identity->samlNameId;
        }

        Db::get()->update('users', $fields, 'id = ?', [$user->id]);
    }

    /**
     * netIDs are case-insensitive; store them lowercased so that "JDoe" and
     * "jdoe" cannot become two accounts with two sets of bookings.
     */
    public static function normaliseNetid(string $netid): string
    {
        return strtolower(trim($netid));
    }

    /** True for a syntactically plausible netID. */
    public static function isValidNetid(string $netid): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', self::normaliseNetid($netid)) === 1;
    }
}
