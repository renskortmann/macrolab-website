<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Clock;

/**
 * The time registration rules, and the converters that turn what a person typed
 * into what the database stores.
 *
 * Pure, like BookingRules: no database, no clock of its own. "Today" is handed
 * in, which is what makes every rule here testable without a fixture.
 */
final class TimeRules
{
    /** The longest a note may be, matching time_entries.note. */
    public const NOTE_MAX = 255;

    /**
     * Everything wrong with a proposed entry, as sentences fit to show a user.
     * An empty list means it is acceptable.
     *
     * @param DateTimeImmutable $today the current date in the display timezone,
     *                                 at UTC midnight - see TimeEntryService
     * @return list<string>
     */
    public static function validate(
        TimeRuleSet $rules,
        DateTimeImmutable $workedOn,
        int $minutes,
        DateTimeImmutable $today,
        int $minutesAlreadyOnDay = 0,
        ?string $note = null,
    ): array {
        $errors = [];

        if ($minutes <= 0) {
            $errors[] = 'Enter how long you worked.';
        } elseif ($minutes < $rules->minMinutes) {
            $errors[] = 'The shortest entry is ' . Clock::humanDuration($rules->minMinutes) . '.';
        } elseif ($minutes > $rules->maxMinutesPerEntry) {
            // One entry is one project's time for one day, so this is a
            // per-project daily limit; splitting the time is not an option.
            $errors[] = 'No more than ' . Clock::humanDuration($rules->maxMinutesPerEntry)
                . ' can be logged on one activity in a day.';
        }

        // Only worth checking when this entry is itself sane.
        if ($minutes > 0 && $minutesAlreadyOnDay + $minutes > $rules->maxMinutesPerDay) {
            $errors[] = 'That would put ' . Clock::humanDuration($minutesAlreadyOnDay + $minutes)
                . ' on one day, and the limit is ' . Clock::humanDuration($rules->maxMinutesPerDay)
                . '. You have already logged ' . Clock::humanDuration($minutesAlreadyOnDay)
                . ' that day.';
        }

        $daysAhead = self::wholeDaysBetween($today, $workedOn);

        if ($daysAhead > $rules->maxFutureDays) {
            $errors[] = $rules->maxFutureDays === 0
                ? 'You cannot log time for a day that has not happened yet.'
                : 'You cannot log time more than ' . $rules->maxFutureDays . ' days ahead.';
        }

        if (-$daysAhead > $rules->maxBackdateDays) {
            $errors[] = 'You cannot log time more than ' . $rules->maxBackdateDays
                . ' days back. Ask the administrator if you need to correct something older.';
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            // Rejected rather than truncated: a silently shortened time record
            // is a wrong record, where a shortened booking purpose is only a
            // cosmetic loss.
            $errors[] = 'The note is too long. Please keep it under ' . self::NOTE_MAX . ' characters.';
        }

        return $errors;
    }

    /**
     * Whether time may be logged for this day at all: the same window
     * validate() enforces, asked up front so the day sheet can show a day
     * outside it read-only instead of refusing every cell.
     */
    public static function isOpenForLogging(TimeRuleSet $rules, DateTimeImmutable $day, DateTimeImmutable $today): bool
    {
        $daysAhead = self::wholeDaysBetween($today, $day);

        return $daysAhead <= $rules->maxFutureDays && -$daysAhead <= $rules->maxBackdateDays;
    }

    /**
     * Whole days from $from to $to, positive when $to is later.
     *
     * Both are UTC midnights, so this is plain date arithmetic with no
     * timezone or DST subtlety in it.
     */
    public static function wholeDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    /**
     * Turn what somebody typed into whole minutes, or null when it makes no
     * sense. The single place this conversion happens.
     *
     * Accepts the shapes people actually type: "3.5", "3,5" (the decimal comma
     * is normal in Dutch and these are TU Delft users), "3:30", "3h30", "3h",
     * "8" and "0.25". Rounds exactly once, so 3.5 is 210 minutes and not
     * 209.99999 floored.
     */
    public static function parseHours(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        // "3:30" and "3h30" are hours and minutes, not a decimal.
        if (preg_match('/^(\d{1,3})\s*[:h]\s*(\d{1,2})$/i', $value, $m) === 1) {
            $minutes = (int) $m[2];

            if ($minutes > 59) {
                return null;
            }

            return (int) $m[1] * 60 + $minutes;
        }

        // A bare "3h" is three hours.
        if (preg_match('/^(\d{1,3})\s*h$/i', $value, $m) === 1) {
            return (int) $m[1] * 60;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) !== 1) {
            return null;
        }

        $minutes = (int) round(((float) $value) * 60);

        return $minutes < 0 ? null : $minutes;
    }

    /** Minutes as "3:30", the form the timesheet shows. */
    public static function formatHours(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60) . ':' . sprintf('%02d', $minutes % 60);
    }

    /**
     * Minutes as a decimal hour count, for the CSV export to sum: "3.50", or
     * "3,50" for spreadsheets set up for a decimal comma.
     */
    public static function decimalHours(int $minutes, string $decimalSeparator = '.'): string
    {
        return number_format(max(0, $minutes) / 60, 2, $decimalSeparator, '');
    }

    /**
     * Parse a date - day first (08-10-2026) or ISO (2026-10-08) - into UTC
     * midnight, or null.
     *
     * Strict on purpose: Clock::isoDate() refuses 30-02-2025 rather than
     * rolling it forward to 2 March, and createFromFormat's warning count is
     * still checked behind it. Whatever a form submits, the endpoint does not
     * trust it.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        // Day first as the site shows it (08-10-2026), or ISO from a link.
        $value = Clock::isoDate($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, Clock::utc());
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    /** Today, as a date at UTC midnight, read in the timezone the user sees. */
    public static function today(): DateTimeImmutable
    {
        return self::parseDate(Clock::now()->setTimezone(Clock::displayZone())->format('Y-m-d'))
            ?? Clock::now()->setTime(0, 0);
    }
}
