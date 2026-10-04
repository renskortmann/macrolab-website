<?php

declare(strict_types=1);

namespace Macrolab;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The only source of "now" in the application. Everything reads the clock from
 * here so that tests can freeze it - which is what makes the booking rules,
 * the invite expiry and the DST boundaries testable at all.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    /** Current instant, always in UTC. */
    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Freeze the clock. Tests only. */
    public static function freeze(DateTimeImmutable|string $when): DateTimeImmutable
    {
        return self::$frozen = $when instanceof DateTimeImmutable
            ? $when->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    /** UTC string in the format every DATETIME column uses. */
    public static function sql(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** The timezone users see, from configuration. */
    public static function displayZone(): DateTimeZone
    {
        return new DateTimeZone(Config::string('app.display_timezone', 'Europe/Amsterdam'));
    }

    /** Parse a UTC DATETIME string from the database. */
    public static function fromSql(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::utc());
    }

    /** Render a UTC instant in the display timezone. */
    public static function local(DateTimeImmutable $moment, string $format = 'Y-m-d H:i'): string
    {
        return $moment->setTimezone(self::displayZone())->format($format);
    }

    /**
     * Every time of day at the given spacing, as "HH:MM".
     *
     * The application renders its own time pickers from this: a native time
     * input follows the browser's locale, which on an English-language machine
     * means am/pm, and no attribute can talk it out of that.
     *
     * @return list<string>
     */
    public static function timeOptions(int $stepMinutes = 30): array
    {
        $step = max(1, min(24 * 60, $stepMinutes));
        $times = [];

        for ($minutes = 0; $minutes < 24 * 60; $minutes += $step) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    /** A duration in words, for messages: "2 hours 30 minutes", "3 days". */
    public static function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' minutes';
        }

        // Whole days read better than the hour count for long spans; anything
        // that is not a round number of days stays in hours.
        if ($minutes >= 24 * 60 && $minutes % (24 * 60) === 0) {
            $days = intdiv($minutes, 24 * 60);

            return $days . ($days === 1 ? ' day' : ' days');
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $text = $hours . ($hours === 1 ? ' hour' : ' hours');

        return $rest === 0 ? $text : $text . ' ' . $rest . ' minutes';
    }
    /**
     * A date as typed or submitted, as "Y-m-d" - or null when it is not a real
     * calendar date.
     *
     * The site shows dates day first (dd-mm-yyyy), so that is what people
     * type: 08-10-2026, 8-10-2026, 08/10/2026 and 08.10.2026 all mean 8
     * October. ISO (2026-10-08) is accepted too, because links and the
     * browser's own date picker use it. Never handed to PHP's date parser
     * as is: that reads 08/10/2026 the American way, as 10 August.
     */
    public static function isoDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** "2026-10-08" as the site shows dates: "08-10-2026". Anything else is returned unchanged. */
    public static function dmy(string $isoDate): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m) === 1
            ? $m[3] . '-' . $m[2] . '-' . $m[1]
            : $isoDate;
    }

    /**
     * Parse a time submitted by a browser into a UTC instant.
     *
     * A value carrying an offset ("2026-09-14T09:00:00+02:00" or "...Z") is
     * taken at face value. A bare local time ("2026-09-14 09:00") is read in
     * the display timezone, because that is what the person typing it meant.
     */
    public static function parseInstant(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $hasOffset = preg_match('/(Z|[+-]\\d{2}:?\\d{2})$/', $value) === 1;

        try {
            $parsed = new DateTimeImmutable($value, $hasOffset ? self::utc() : self::displayZone());
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone(self::utc());
    }
}
