<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Booking\Booking;
use Macrolab\Booking\BookingException;
use Macrolab\Booking\BookingService;
use Macrolab\Booking\Bookings;
use Macrolab\Clock;
use Macrolab\Http\HttpException;
use Macrolab\Booking\Equipment;
use Macrolab\Settings;
use Macrolab\User;
use Macrolab\Users;

/**
 * Booking, changing and cancelling, against a real database - because the
 * overlap check is a database operation, not a calculation.
 */
final class BookingServiceTest extends DatabaseTestCase
{
    private User $alice;
    private User $bob;
    private int $equipmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = Users::create('alice', 'Alice');
        $this->bob = Users::create('bob', 'Bob');
        $this->equipmentId = Equipment::primaryId();

        // A fixed "now": Monday 14 September 2026, 06:00 UTC - which is 08:00
        // local, so the 11:00-and-later bookings below are comfortably in the
        // future and clear of the change-notice window.
        Clock::freeze('2026-09-14 06:00:00');
    }

    public function testAUserCanBookAFreeSlot(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        self::assertTrue($booking->isConfirmed());
        self::assertSame($this->alice->id, $booking->userId);
        self::assertFalse($booking->createdByAdmin);
    }

    public function testASecondBookingOverTheSameSlotIsRefused(): void
    {
        $this->book($this->alice, '11:00', '12:00');

        $this->expectException(BookingException::class);
        $this->book($this->bob, '11:00', '12:00');
    }

    public function testAPartialOverlapIsRefused(): void
    {
        $this->book($this->alice, '11:00', '13:00');

        try {
            $this->book($this->bob, '12:00', '14:00');
            self::fail('an overlapping booking must be refused');
        } catch (BookingException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testABookingContainedInsideAnotherIsRefused(): void
    {
        $this->book($this->alice, '11:00', '15:00');

        $this->expectException(BookingException::class);
        $this->book($this->bob, '12:00', '13:00');
    }

    public function testABookingThatSwallowsAnotherIsRefused(): void
    {
        $this->book($this->alice, '12:00', '13:00');

        $this->expectException(BookingException::class);
        $this->book($this->bob, '11:00', '15:00');
    }

    /** The half-open interval: back-to-back bookings are fine. */
    public function testTouchingBookingsAreAllowed(): void
    {
        $first = $this->book($this->alice, '11:00', '12:00');
        $second = $this->book($this->bob, '12:00', '13:00');

        self::assertNotSame($first->id, $second->id);
        self::assertCount(2, Bookings::inWindow(
            $this->equipmentId,
            $this->utc('2026-09-14 00:00'),
            $this->utc('2026-09-15 00:00'),
        ));
    }

    public function testACancelledBookingFreesTheSlot(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');
        BookingService::cancel(Actor::forUser($this->alice), $booking);

        $replacement = $this->book($this->bob, '11:00', '12:00');

        self::assertTrue($replacement->isConfirmed());
    }

    public function testTheQuotaIsEnforced(): void
    {
        Settings::set('max_active_bookings_per_user', '2');

        $this->book($this->alice, '11:00', '12:00');
        $this->book($this->alice, '12:00', '13:00');

        try {
            $this->book($this->alice, '13:00', '14:00');
            self::fail('the third booking should exceed the quota');
        } catch (BookingException $e) {
            self::assertStringContainsString('upcoming booking', $e->getMessage());
        }
    }

    public function testEditingAnOwnBookingDoesNotCountAgainstItsOwnQuota(): void
    {
        Settings::set('max_active_bookings_per_user', '1');

        $booking = $this->book($this->alice, '11:00', '12:00');

        $updated = BookingService::update(
            Actor::forUser($this->alice),
            $booking,
            $this->local('13:00'),
            $this->local('14:00'),
            'moved',
        );

        self::assertSame('13:00', Clock::local($updated->startsAt, 'H:i'));
    }

    public function testAUserCannotChangeSomeoneElsesBooking(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        try {
            BookingService::update(Actor::forUser($this->bob), $booking,
                $this->local('15:00'), $this->local('16:00'));
            self::fail('Bob must not be able to move Alice\'s booking');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }

        $unchanged = Bookings::find($booking->id);
        self::assertSame('11:00', Clock::local($unchanged->startsAt, 'H:i'));
    }

    public function testAUserCannotCancelSomeoneElsesBooking(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        try {
            BookingService::cancel(Actor::forUser($this->bob), $booking);
            self::fail('Bob must not be able to cancel Alice\'s booking');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }

        self::assertTrue(Bookings::find($booking->id)->isConfirmed());
    }

    public function testARefusedChangeIsRecordedInTheAuditLog(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        try {
            BookingService::cancel(Actor::forUser($this->bob), $booking);
        } catch (HttpException) {
            // expected
        }

        self::assertSame(1, (int) \Macrolab\Db::get()->value(
            'SELECT COUNT(*) FROM audit_log WHERE action = ?', ['booking_change_refused']
        ));
    }

    public function testAUserCannotDeleteABookingOutright(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        $this->expectException(HttpException::class);
        BookingService::delete(Actor::forUser($this->alice), $booking);
    }

    // ------------------------------------------------------------- the admin

    public function testTheAdminCanBookOnSomeonesBehalf(): void
    {
        $booking = BookingService::create(
            Actor::forAdmin(),
            $this->equipmentId,
            $this->local('11:00'),
            $this->local('12:00'),
            'training',
            $this->alice->id,
        );

        self::assertSame($this->alice->id, $booking->userId);
        self::assertTrue($booking->createdByAdmin);
    }

    public function testTheAdminIgnoresOpeningHoursTheQuotaAndThePast(): void
    {
        Settings::set('max_active_bookings_per_user', '1');

        // 05:00 local on a Sunday, in the past, and eight hours long.
        $booking = BookingService::create(
            Actor::forAdmin(),
            $this->equipmentId,
            $this->utc('2026-09-06 03:00'),
            $this->utc('2026-09-06 11:00'),
            'maintenance',
            $this->alice->id,
        );

        self::assertTrue($booking->isConfirmed());
    }

    public function testEvenTheAdminCannotDoubleBookTheEquipment(): void
    {
        $this->book($this->alice, '11:00', '12:00');

        $this->expectException(BookingException::class);
        BookingService::create(Actor::forAdmin(), $this->equipmentId,
            $this->local('11:30'), $this->local('12:30'), null, $this->bob->id);
    }

    public function testTheAdminCanChangeAndDeleteAnyBooking(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');

        $moved = BookingService::update(Actor::forAdmin(), $booking,
            $this->local('15:00'), $this->local('16:00'), 'moved by admin');
        self::assertSame('15:00', Clock::local($moved->startsAt, 'H:i'));

        BookingService::delete(Actor::forAdmin(), $moved);
        self::assertNull(Bookings::find($booking->id));
    }

    public function testDeletingABookingLeavesItsRecordInTheAuditLog(): void
    {
        $booking = $this->book($this->alice, '11:00', '12:00');
        BookingService::delete(Actor::forAdmin(), $booking);

        $row = \Macrolab\Db::get()->one('SELECT details FROM audit_log WHERE action = ?', ['booking_deleted']);

        self::assertNotNull($row);
        self::assertStringContainsString('alice', (string) $row['details']);
    }

    // ------------------------------------------------------- change notice

    public function testAUserCannotChangeABookingInsideTheNoticeWindow(): void
    {
        Settings::set('min_change_notice_minutes', '60');

        $booking = BookingService::create(Actor::forAdmin(), $this->equipmentId,
            $this->local('08:30'), $this->local('09:30'), null, $this->alice->id);

        // "Now" is 08:00 local, so the booking starts in 30 minutes - inside
        // the 60 minutes of notice a lab member needs.
        try {
            BookingService::cancel(Actor::forUser($this->alice), $booking);
            self::fail('inside the notice window this must be refused');
        } catch (BookingException $e) {
            self::assertStringContainsString('before they start', $e->getMessage());
        }
    }

    public function testTheAdminCanStillChangeABookingThatHasStarted(): void
    {
        // 07:00 local: an hour before the frozen "now", so it is under way.
        $booking = BookingService::create(Actor::forAdmin(), $this->equipmentId,
            $this->local('07:00'), $this->local('09:00'), null, $this->alice->id);

        BookingService::cancel(Actor::forAdmin(), $booking);

        self::assertSame('cancelled', Bookings::find($booking->id)?->status);
    }

    // --------------------------------------------------------------- helpers

    private function book(User $user, string $fromLocal, string $toLocal): Booking
    {
        return BookingService::create(
            Actor::forUser($user),
            $this->equipmentId,
            $this->local($fromLocal),
            $this->local($toLocal),
            'test run',
        );
    }

    /** A local time today (Monday 14 September 2026) as a UTC instant. */
    private function local(string $time): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-09-14 ' . $time, new DateTimeZone('Europe/Amsterdam')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }
}
