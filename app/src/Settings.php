<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * Operational settings the admin edits in the web UI: the booking rules, the
 * time registration limits and the active authentication mode. A row in the `settings` table overrides the
 * default below, so a fresh install is already usable.
 */
final class Settings
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        // 'local' - netID + password accounts managed here (stage 1)
        // 'saml'  - TU Delft SSO only (stage 2)
        // 'both'  - either, for the cutover window
        'auth_mode' => 'local',

        // Booking rules. Times are in the display timezone; storage is UTC.
        'slot_minutes'                 => '30',
        'open_days'                    => '1,2,3,4,5', // ISO-8601: Monday = 1
        'open_time'                    => '08:00',
        'close_time'                   => '18:00',
        'min_booking_minutes'          => '30',
        // The longest booking is set in whole days; minutes stay the unit
        // everything downstream works in. See RuleSet::fromSettings().
        'max_booking_days'             => '1',
        'max_advance_days'             => '60',
        // 0 means no quota. The limit counts per piece of equipment, not in total.
        'max_active_bookings_per_user' => '0',
        'min_change_notice_minutes'    => '60',
        'allow_booking_in_past'        => '0',

        // Time registration. Unrelated to the booking rules above: these govern
        // the hours employees log, which have nothing to do with the equipment.
        'time_min_entry_minutes' => '5',
        'time_max_entry_minutes' => '720',   // 12 hours in one entry
        'time_max_day_minutes'   => '960',   // 16 hours across a whole day
        'time_max_future_days'   => '7',
        'time_max_backdate_days' => '90',

        // Housekeeping.
        'audit_retention_days' => '365',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];
        foreach (Db::get()->all('SELECT `key`, `value` FROM settings') as $row) {
            $stored[(string) $row['key']] = (string) $row['value'];
        }

        return self::$cache = [...self::DEFAULTS, ...$stored];
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return in_array(self::get($key), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Days of the week the equipment may be booked on, as ISO-8601 numbers.
     *
     * @return list<int>
     */
    public static function openDays(): array
    {
        $days = [];
        foreach (explode(',', self::get('open_days')) as $day) {
            $day = (int) trim($day);
            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        return array_values(array_unique($days));
    }

    public static function set(string $key, string $value): void
    {
        Db::get()->query(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $value, Clock::sql()]
        );

        self::$cache = null;
    }

    /**
     * @param array<string, string> $values
     */
    public static function setMany(array $values): void
    {
        Db::get()->transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::set($key, $value);
            }
        });
    }

    /** Drop the in-process cache. Needed by tests and after a bulk write. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * The sign-in mode in effect. While TU Delft SSO is not available it is
     * always 'local', whatever is stored: a stored 'saml' would otherwise switch
     * off password sign-in with nothing to replace it, locking out every lab
     * member.
     */
    public static function authMode(): string
    {
        $mode = self::get('auth_mode');

        if (!self::ssoAvailable()) {
            return 'local';
        }

        return in_array($mode, ['local', 'saml', 'both'], true) ? $mode : 'local';
    }

    /**
     * Whether this version of the application can sign anyone in through TU
     * Delft SSO. Stage 2 is not built yet: the routes, the database columns and
     * this setting are in place, but there is no SAML provider. This turns true
     * by itself once Auth\SamlProvider exists.
     */
    public static function ssoAvailable(): bool
    {
        return class_exists(Auth\SamlProvider::class);
    }

    public static function localLoginEnabled(): bool
    {
        return in_array(self::authMode(), ['local', 'both'], true);
    }

    public static function samlLoginEnabled(): bool
    {
        return in_array(self::authMode(), ['saml', 'both'], true);
    }
}
