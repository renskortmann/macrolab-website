<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Booking\Booking;
use Macrolab\Booking\BookingPolicy;
use Macrolab\User;
use PHPUnit\Framework\TestCase;

/**
 * "Users can modify their own bookings, but not those of others."
 *
 * This is the requirement most likely to be got wrong, so it is tested
 * directly rather than through the interface that happens to hide the button.
 */
final class BookingPolicyTest extends TestCase
{
    public function testOwnerMayModifyTheirOwnBooking(): void
    {
        $alice = $this->user(1, 'alice');

        self::assertTrue(BookingPolicy::canModify(
            $this->booking(ownerId: 1),
            Actor::forUser($alice)
        ));
    }

    public function testOtherUserMayNotModifySomeoneElsesBooking(): void
    {
        $bob = $this->user(2, 'bob');

        self::assertFalse(BookingPolicy::canModify(
            $this->booking(ownerId: 1),
            Actor::forUser($bob)
        ));
    }

    public function testAdminMayModifyAnyBooking(): void
    {
        self::assertTrue(BookingPolicy::canModify(
            $this->booking(ownerId: 1),
            Actor::forAdmin()
        ));
    }

    public function testAdminMayEvenModifyACancelledBooking(): void
    {
        self::assertTrue(BookingPolicy::canModify(
            $this->booking(ownerId: 1, status: 'cancelled'),
            Actor::forAdmin()
        ));
    }

    public function testOwnerMayNotModifyTheirCancelledBooking(): void
    {
        self::assertFalse(BookingPolicy::canModify(
            $this->booking(ownerId: 1, status: 'cancelled'),
            Actor::forUser($this->user(1, 'alice'))
        ));
    }

    public function testNobodySignedInMayModifyAnything(): void
    {
        self::assertFalse(BookingPolicy::canModify($this->booking(ownerId: 1), null));
    }

    public function testAMissingBookingIsNotModifiable(): void
    {
        self::assertFalse(BookingPolicy::canModify(null, Actor::forAdmin()));
    }

    public function testEveryoneSignedInSeesWhoHoldsASlot(): void
    {
        self::assertTrue(BookingPolicy::canSeeOwner(Actor::forUser($this->user(2, 'bob'))));
        self::assertTrue(BookingPolicy::canSeeOwner(Actor::forAdmin()));
        self::assertFalse(BookingPolicy::canSeeOwner(null));
    }

    public function testOnlyTheOwnerAndTheAdminSeeThePurpose(): void
    {
        $booking = $this->booking(ownerId: 1);

        self::assertTrue(BookingPolicy::canSeePurpose($booking, Actor::forUser($this->user(1, 'alice'))));
        self::assertFalse(BookingPolicy::canSeePurpose($booking, Actor::forUser($this->user(2, 'bob'))));
        self::assertTrue(BookingPolicy::canSeePurpose($booking, Actor::forAdmin()));
        self::assertFalse(BookingPolicy::canSeePurpose($booking, null));
    }

    private function user(int $id, string $netid): User
    {
        return new User(
            id: $id,
            netid: $netid,
            displayName: ucfirst($netid),
            email: $netid . '@tudelft.nl',
            status: 'approved',
        );
    }

    private function booking(int $ownerId, string $status = 'confirmed'): Booking
    {
        $utc = new DateTimeZone('UTC');

        return new Booking(
            id: 42,
            equipmentId: 1,
            userId: $ownerId,
            startsAt: new DateTimeImmutable('2026-09-14 07:00', $utc),
            endsAt: new DateTimeImmutable('2026-09-14 09:00', $utc),
            purpose: 'sample run',
            status: $status,
        );
    }
}
