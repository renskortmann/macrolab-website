<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use DateTimeImmutable;
use Macrolab\Clock;

/**
 * The booking rules, as pure functions: same inputs, same answer, no database
 * and no clock of their own.
 *
 * Times arrive as UTC instants and are compared in the display timezone,
 * because "between 08:00 and 18:00 on a weekday" is a statement about local
 * wall-clock time. Converting a UTC instant to local time always yields
 * exactly one wall time, so the March and October DST transitions cannot
 * produce an ambiguous booking.
 *
 * The administrator bypasses all of this; see BookingService.
 */
final class BookingRules
{
    /**
     * Everything wrong with a proposed booking, as sentences fit to show a user.
     * An empty list means it is acceptable.
     *
     * @return list<string>
     */
    public static function validate(
        RuleSet $rules,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        DateTimeImmutable $now,
        int $activeBookingCount = 0,
        bool $countsAgainstQuota = true,
    ): array {
        $errors = [];

        if ($endUtc <= $startUtc) {
            return ['A booking must end after it starts.'];
        }

        $zone = $rules->zone();
        $localStart = $startUtc->setTimezone($zone);
        $localEnd = $endUtc->setTimezone($zone);

        $durationMinutes = (int) round(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60);

        // --- duration -------------------------------------------------------
        if ($durationMinutes < $rules->minMinutes) {
            $errors[] = 'The shortest booking is ' . Clock::humanDuration($rules->minMinutes) . '.';
        }

        if ($durationMinutes > $rules->maxMinutes) {
            $errors[] = 'The longest booking is ' . Clock::humanDuration($rules->maxMinutes) . '.';
        }

        if ($durationMinutes % $rules->slotMinutes !== 0) {
            $errors[] = 'Bookings are made in blocks of ' . $rules->slotMinutes . ' minutes.';
        }

        // --- alignment to the slot grid -------------------------------------
        $startMinutes = (int) $localStart->format('H') * 60 + (int) $localStart->format('i');

        if ((int) $localStart->format('s') !== 0 || $startMinutes % $rules->slotMinutes !== 0) {
            $errors[] = 'Please start on a ' . $rules->slotMinutes . '-minute boundary, such as '
                . self::exampleBoundary($rules) . '.';
        }

        // --- opening hours --------------------------------------------------
        $endMinutes = (int) $localEnd->format('H') * 60 + (int) $localEnd->format('i');
        // A booking that ends exactly at midnight ends on the next calendar day
        // but occupies none of it.
        $endsAtMidnight = $endMinutes === 0 && (int) $localEnd->format('s') === 0;

        // Every calendar day the booking touches must be one the equipment is
        // open on. Stepping at local noon keeps the cursor clear of the hour
        // DST adds or removes, so "+1 day" always lands on the next date.
        $cursor = $localStart->setTime(12, 0);
        $lastDay = $localEnd->setTime(12, 0);

        if ($endsAtMidnight) {
            $lastDay = $lastDay->modify('-1 day');
        }

        for (; $cursor <= $lastDay; $cursor = $cursor->modify('+1 day')) {
            if (in_array((int) $cursor->format('N'), $rules->openDays, true)) {
                continue;
            }

            $errors[] = 'The equipment can be booked on ' . self::humanDays($rules->openDays) . '.'
                . ($cursor->format('Y-m-d') === $localStart->format('Y-m-d')
                    ? ''
                    : ' This booking would run through a ' . self::humanDays([(int) $cursor->format('N')]) . '.');
            break;
        }

        // Only the two ends are held to the clock. A booking that runs for days
        // holds the equipment through the nights in between, and those hours are
        // occupied by design rather than booked against opening hours.
        $open = $rules->openMinutes();
        $close = $rules->closeMinutes();
        $effectiveEnd = $endsAtMidnight ? 24 * 60 : $endMinutes;

        if ($startMinutes < $open || $effectiveEnd > $close) {
            $errors[] = 'Bookings must fall between ' . $rules->openTime . ' and ' . $rules->closeTime . '.';
        }

        // --- when, relative to now ------------------------------------------
        if (!$rules->allowPast && $startUtc < $now) {
            $errors[] = 'That start time is in the past.';
        }

        $horizon = $now->modify('+' . $rules->maxAdvanceDays . ' days');

        if ($startUtc > $horizon) {
            $errors[] = 'Bookings can be made up to ' . $rules->maxAdvanceDays . ' days ahead.';
        }

        // --- how many the user already holds --------------------------------
        if ($countsAgainstQuota
            && $rules->maxActivePerUser > 0
            && $activeBookingCount >= $rules->maxActivePerUser) {
            $errors[] = 'You already have ' . $rules->maxActivePerUser
                . ' upcoming booking(s). Please cancel one before making another.';
        }

        return $errors;
    }

    /**
     * Whether a user may still change or cancel a booking of their own.
     * Bookings that have started, or are about to, are frozen - the admin can
     * still alter them.
     */
    public static function changeNoticeError(
        RuleSet $rules,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $now,
    ): ?string {
        if ($startUtc <= $now) {
            return 'That booking has already started, so it can no longer be changed. '
                . 'Please ask the lab administrator.';
        }

        $deadline = $startUtc->modify('-' . $rules->minChangeNoticeMinutes . ' minutes');

        if ($rules->minChangeNoticeMinutes > 0 && $now > $deadline) {
            return 'Bookings can only be changed up to ' . Clock::humanDuration($rules->minChangeNoticeMinutes)
                . ' before they start. Please ask the lab administrator.';
        }

        return null;
    }

    /**
     * @param list<int> $days
     */
    public static function humanDays(array $days): string
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                  5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        sort($days);
        $labels = array_map(static fn (int $d): string => $names[$d] ?? (string) $d, $days);

        if ($labels === []) {
            return 'no days (ask the administrator)';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }

    private static function exampleBoundary(RuleSet $rules): string
    {
        $open = $rules->openMinutes();
        $second = $open + $rules->slotMinutes;

        return sprintf('%02d:%02d or %02d:%02d',
            intdiv($open, 60), $open % 60,
            intdiv($second, 60) % 24, $second % 60);
    }
}
