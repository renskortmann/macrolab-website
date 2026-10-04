<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Booking\BookingRules;
use Macrolab\Booking\RuleSet;
use PHPUnit\Framework\TestCase;

/**
 * The booking rules. These are pure functions, so every case here is exact -
 * no database, no wall clock.
 */
final class BookingRulesTest extends TestCase
{
    private RuleSet $rules;

    protected function setUp(): void
    {
        // The defaults from Settings::DEFAULTS, stated explicitly so a change
        // to the defaults cannot quietly change what these tests mean.
        $this->rules = new RuleSet(
            slotMinutes: 30,
            openDays: [1, 2, 3, 4, 5],
            openTime: '08:00',
            closeTime: '18:00',
            minMinutes: 30,
            maxMinutes: 240,
            maxAdvanceDays: 60,
            maxActivePerUser: 3,
            minChangeNoticeMinutes: 60,
            allowPast: false,
            timezone: 'Europe/Amsterdam',
        );
    }

    public function testAcceptsAWellFormedBooking(): void
    {
        // Monday 14 September 2026, 09:00-11:00 local time.
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00', now: '2026-09-01 12:00');

        self::assertSame([], $errors);
    }

    public function testRejectsAnEndBeforeItsStart(): void
    {
        $errors = $this->validate('2026-09-14 11:00', '2026-09-14 09:00', now: '2026-09-01 12:00');

        self::assertSame(['A booking must end after it starts.'], $errors);
    }

    public function testRejectsAStartOffTheSlotGrid(): void
    {
        $errors = $this->validate('2026-09-14 09:10', '2026-09-14 10:10', now: '2026-09-01 12:00');

        self::assertContainsMatch('/30-minute boundary/', $errors);
    }

    public function testRejectsADurationThatIsNotAWholeNumberOfSlots(): void
    {
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 09:45', now: '2026-09-01 12:00');

        self::assertContainsMatch('/blocks of 30 minutes/', $errors);
    }

    public function testRejectsTooShortAndTooLong(): void
    {
        $short = new RuleSet(slotMinutes: 15, minMinutes: 30, maxMinutes: 240);

        self::assertContainsMatch('/shortest booking is 30 minutes/',
            $this->validate('2026-09-14 09:00', '2026-09-14 09:15', rules: $short, now: '2026-09-01 12:00'));

        self::assertContainsMatch('/longest booking is 4 hours/',
            $this->validate('2026-09-14 08:00', '2026-09-14 14:00', now: '2026-09-01 12:00'));
    }

    public function testAcceptsExactlyTheMaximumDuration(): void
    {
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 13:00', now: '2026-09-01 12:00');

        self::assertSame([], $errors, 'four hours is allowed, not one minute less');
    }

    public function testRejectsABookingOutsideOpeningHours(): void
    {
        self::assertContainsMatch('/between 08:00 and 18:00/',
            $this->validate('2026-09-14 07:00', '2026-09-14 09:00', now: '2026-09-01 12:00'));

        self::assertContainsMatch('/between 08:00 and 18:00/',
            $this->validate('2026-09-14 17:00', '2026-09-14 19:00', now: '2026-09-01 12:00'));
    }

    public function testAcceptsBookingsExactlyAtTheEdgesOfTheDay(): void
    {
        self::assertSame([], $this->validate('2026-09-14 08:00', '2026-09-14 10:00', now: '2026-09-01 12:00'));
        self::assertSame([], $this->validate('2026-09-14 16:00', '2026-09-14 18:00', now: '2026-09-01 12:00'));
    }

    public function testRejectsAClosedDay(): void
    {
        // Saturday 19 September 2026.
        $errors = $this->validate('2026-09-19 09:00', '2026-09-19 11:00', now: '2026-09-01 12:00');

        self::assertContainsMatch('/Monday, Tuesday, Wednesday, Thursday and Friday/', $errors);
    }

    public function testRejectsThePast(): void
    {
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00', now: '2026-09-14 10:00');

        self::assertContainsMatch('/in the past/', $errors);
    }

    public function testAllowsThePastWhenTheAdminHasTurnedThatOn(): void
    {
        $rules = new RuleSet(allowPast: true);
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00',
            rules: $rules, now: '2026-09-14 10:00');

        self::assertSame([], $errors);
    }

    public function testRejectsBeyondTheBookingHorizon(): void
    {
        $errors = $this->validate('2026-12-14 09:00', '2026-12-14 11:00', now: '2026-09-01 12:00');

        self::assertContainsMatch('/up to 60 days ahead/', $errors);
    }

    public function testRejectsWhenTheUserHoldsTheirQuotaAlready(): void
    {
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00',
            now: '2026-09-01 12:00', activeCount: 3);

        self::assertContainsMatch('/already have 3 upcoming booking/', $errors);
    }

    public function testQuotaIsNotAppliedWhenTheCallerSaysItDoesNotCount(): void
    {
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00',
            now: '2026-09-01 12:00', activeCount: 99, countsAgainstQuota: false);

        self::assertSame([], $errors);
    }

    public function testQuotaOfZeroMeansNoLimit(): void
    {
        $rules = new RuleSet(maxActivePerUser: 0);
        $errors = $this->validate('2026-09-14 09:00', '2026-09-14 11:00',
            rules: $rules, now: '2026-09-01 12:00', activeCount: 50);

        self::assertSame([], $errors);
    }

    public function testAcceptsABookingSpanningTwoDays(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5, 6, 7], openTime: '00:00',
            closeTime: '23:30', maxMinutes: 24 * 60);

        $errors = $this->validate('2026-09-14 22:00', '2026-09-15 02:00',
            rules: $rules, now: '2026-09-01 12:00');

        self::assertSame([], $errors);
    }

    /**
     * A long run holds the equipment overnight. The hours in between are occupied
     * by design; only the two ends are held to the opening hours.
     */
    public function testAcceptsAnOvernightBooking(): void
    {
        $errors = $this->validate('2026-09-14 16:00', '2026-09-15 10:00',
            rules: $this->multiDayRules(), now: '2026-09-01 12:00');

        self::assertSame([], $errors);
    }

    /**
     * The opening-hours check used to apply only to same-day bookings, so
     * allowing multi-day ones could have opened a hole straight through it.
     */
    public function testAMultiDayBookingStillCannotStartBeforeOpeningTime(): void
    {
        $errors = $this->validate('2026-09-14 07:00', '2026-09-15 10:00',
            rules: $this->multiDayRules(), now: '2026-09-01 12:00');

        self::assertContainsMatch('/between 08:00 and 18:00/', $errors);
    }

    public function testAMultiDayBookingStillCannotEndAfterClosingTime(): void
    {
        $errors = $this->validate('2026-09-14 16:00', '2026-09-15 19:00',
            rules: $this->multiDayRules(), now: '2026-09-01 12:00');

        self::assertContainsMatch('/between 08:00 and 18:00/', $errors);
    }

    public function testRejectsABookingThatRunsThroughAClosedDay(): void
    {
        // Friday 18 September 2026 through to Monday the 21st, over a weekend
        // the equipment is closed.
        $errors = $this->validate('2026-09-18 16:00', '2026-09-21 10:00',
            rules: $this->multiDayRules(), now: '2026-09-01 12:00');

        self::assertContainsMatch('/Monday, Tuesday, Wednesday, Thursday and Friday/', $errors);
        self::assertContainsMatch('/run through a Saturday/', $errors);
    }

    public function testAClosedDayIsReportedOnlyOnceHoweverLongTheBooking(): void
    {
        // Two whole weekends inside one booking, but one sentence about it.
        $errors = $this->validate('2026-09-14 08:00', '2026-09-28 10:00',
            rules: $this->multiDayRules(), now: '2026-09-01 12:00');

        $closedDayErrors = array_filter($errors,
            static fn (string $e): bool => str_contains($e, 'can be booked on'));

        self::assertCount(1, $closedDayErrors, 'one complaint, not one per closed day');
    }

    /**
     * A booking ending at exactly midnight finishes on the next calendar day
     * but occupies none of it, so that day need not be an open one.
     */
    public function testABookingMayEndAtMidnightOnTheEveOfAClosedDay(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5], openTime: '08:00',
            closeTime: '24:00', maxMinutes: 31 * 24 * 60);

        // Friday 16:00 to Saturday 00:00.
        $errors = $this->validate('2026-09-18 16:00', '2026-09-19 00:00',
            rules: $rules, now: '2026-09-01 12:00');

        self::assertSame([], $errors);
    }

    /** The extra hour in October must not make a multi-day booking invalid. */
    public function testAcceptsAMultiDayBookingAcrossTheOctoberTransition(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5, 6, 7], openTime: '00:00',
            closeTime: '23:30', maxMinutes: 31 * 24 * 60);

        // Saturday 22:00 to Sunday 12:00: fifteen real hours, not fourteen.
        $errors = $this->validate('2026-10-24 22:00', '2026-10-25 12:00',
            rules: $rules, now: '2026-10-01 12:00');

        self::assertSame([], $errors);
    }

    /** And the missing hour in March. */
    public function testAcceptsAMultiDayBookingAcrossTheMarchTransition(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5, 6, 7], openTime: '00:00',
            closeTime: '23:30', maxMinutes: 31 * 24 * 60);

        $errors = $this->validate('2026-03-28 22:00', '2026-03-29 12:00',
            rules: $rules, now: '2026-03-01 12:00');

        self::assertSame([], $errors);
    }

    /**
     * The reason everything is stored in UTC. On 25 October 2026 the
     * Netherlands goes from CEST (+02:00) back to CET (+01:00) at 03:00 local.
     * A 09:00-11:00 local booking that day is two hours long and must be
     * accepted; the UTC instants behind it are 08:00 and 10:00.
     */
    public function testHandlesTheOctoberDstTransition(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5, 6, 7]);

        $start = $this->local('2026-10-25 09:00');
        $end = $this->local('2026-10-25 11:00');

        self::assertSame('2026-10-25 08:00', $start->format('Y-m-d H:i'), 'stored as UTC');
        self::assertSame('2026-10-25 10:00', $end->format('Y-m-d H:i'));
        self::assertSame(120, ($end->getTimestamp() - $start->getTimestamp()) / 60);

        self::assertSame([], BookingRules::validate($rules, $start, $end,
            new DateTimeImmutable('2026-10-01 12:00', new DateTimeZone('UTC'))));
    }

    /**
     * And the March transition, where 02:00-03:00 local does not exist. A
     * booking from 01:30 to 03:30 local is only one hour of real time, so it
     * is a single 30-minute-aligned hour - and must not be mistaken for two.
     */
    public function testHandlesTheMarchDstTransition(): void
    {
        $rules = new RuleSet(openDays: [1, 2, 3, 4, 5, 6, 7], openTime: '00:00',
            closeTime: '23:30', minMinutes: 30, maxMinutes: 240);

        $start = $this->local('2026-03-29 01:30');
        $end = $this->local('2026-03-29 03:30');

        $minutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        self::assertSame(60.0, (float) $minutes, 'the clock jumps an hour, so this is 60 real minutes');

        self::assertSame([], BookingRules::validate($rules, $start, $end,
            new DateTimeImmutable('2026-03-01 12:00', new DateTimeZone('UTC'))));
    }

    public function testChangeNoticeRefusesABookingThatHasStarted(): void
    {
        $start = $this->local('2026-09-14 09:00');
        $now = $this->local('2026-09-14 09:30');

        $error = BookingRules::changeNoticeError($this->rules, $start, $now);

        self::assertNotNull($error);
        self::assertMatchesRegularExpression('/already started/', $error);
    }

    public function testChangeNoticeRefusesInsideTheNoticeWindow(): void
    {
        $start = $this->local('2026-09-14 09:00');
        $now = $this->local('2026-09-14 08:30'); // 30 minutes of 60 needed

        $error = BookingRules::changeNoticeError($this->rules, $start, $now);

        self::assertNotNull($error);
        self::assertMatchesRegularExpression('/up to 1 hour before/', $error);
    }

    public function testChangeNoticeAllowsOutsideTheWindow(): void
    {
        $start = $this->local('2026-09-14 09:00');
        $now = $this->local('2026-09-13 09:00');

        self::assertNull(BookingRules::changeNoticeError($this->rules, $start, $now));
    }

    public function testChangeNoticeOfZeroAllowsUpToTheStart(): void
    {
        $rules = new RuleSet(minChangeNoticeMinutes: 0);
        $start = $this->local('2026-09-14 09:00');
        $now = $this->local('2026-09-14 08:59');

        self::assertNull(BookingRules::changeNoticeError($rules, $start, $now));
    }

    public function testHumanReadableDays(): void
    {
        self::assertSame('Monday', BookingRules::humanDays([1]));
        self::assertSame('Monday and Friday', BookingRules::humanDays([1, 5]));
        self::assertSame('Monday, Wednesday and Friday', BookingRules::humanDays([1, 3, 5]));
    }

    // ------------------------------------------------------------- helpers

    /** The defaults, but with room for a booking that runs for days. */
    private function multiDayRules(): RuleSet
    {
        return new RuleSet(
            slotMinutes: 30,
            openDays: [1, 2, 3, 4, 5],
            openTime: '08:00',
            closeTime: '18:00',
            minMinutes: 30,
            maxMinutes: 31 * 24 * 60,
            maxAdvanceDays: 60,
            maxActivePerUser: 0,
            timezone: 'Europe/Amsterdam',
        );
    }

    /**
     * @return list<string>
     */
    private function validate(
        string $localStart,
        string $localEnd,
        string $now,
        ?RuleSet $rules = null,
        int $activeCount = 0,
        bool $countsAgainstQuota = true,
    ): array {
        return BookingRules::validate(
            rules: $rules ?? $this->rules,
            startUtc: $this->local($localStart),
            endUtc: $this->local($localEnd),
            now: new DateTimeImmutable($now, new DateTimeZone('UTC')),
            activeBookingCount: $activeCount,
            countsAgainstQuota: $countsAgainstQuota,
        );
    }

    /** A local wall-clock time in the lab timezone, as the UTC instant it is. */
    private function local(string $when): DateTimeImmutable
    {
        return (new DateTimeImmutable($when, new DateTimeZone('Europe/Amsterdam')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param list<string> $errors
     */
    private static function assertContainsMatch(string $pattern, array $errors): void
    {
        foreach ($errors as $error) {
            if (preg_match($pattern, $error) === 1) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail('No error matched ' . $pattern . '. Got: ' . json_encode($errors));
    }
}
