<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use DateTimeImmutable;
use Macrolab\Actor;
use Macrolab\Audit;
use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;

/**
 * Creating, changing and cancelling bookings: rule checks, the overlap check
 * and the audit entry, in one transaction.
 *
 * The administrator bypasses the booking rules entirely - they may book the
 * past, exceed the quota and work outside opening hours - but never the
 * overlap check, because two reservations on one piece of equipment at one time is not a
 * policy question.
 */
final class BookingService
{
    public static function create(
        Actor $actor,
        int $equipmentId,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        ?string $purpose = null,
        ?int $ownerUserId = null,
    ): Booking {
        // Only the admin may book on someone else's behalf.
        $userId = $actor->isAdmin && $ownerUserId !== null ? $ownerUserId : $actor->userId();

        if ($userId === null) {
            throw BookingException::invalid(['A booking needs an owner.']);
        }

        if (!$actor->isAdmin) {
            self::assertRules($actor, $startUtc, $endUtc, null, $userId, $equipmentId);
        } else {
            self::assertSane($startUtc, $endUtc);
        }

        $purpose = self::normalisePurpose($purpose);

        return Db::get()->transaction(static function () use (
            $equipmentId, $userId, $startUtc, $endUtc, $purpose, $actor
        ): Booking {
            Equipment::lock($equipmentId);

            if (Bookings::findOverlap($equipmentId, $startUtc, $endUtc) !== null) {
                throw BookingException::slotTaken();
            }

            $now = Clock::sql();
            $id = Db::get()->insert('bookings', [
                'equipment_id'      => $equipmentId,
                'user_id'          => $userId,
                'starts_at'        => Clock::sql($startUtc),
                'ends_at'          => Clock::sql($endUtc),
                'purpose'          => $purpose,
                'status'           => 'confirmed',
                'created_by_admin' => $actor->isAdmin ? 1 : 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $booking = Bookings::find($id);

            if ($booking === null) {
                throw new \RuntimeException('Booking row disappeared immediately after insert.');
            }

            Audit::log('booking_created', 'booking', $id, [
                'owner_id' => $userId,
                'starts_at' => Clock::sql($startUtc),
                'ends_at' => Clock::sql($endUtc),
                'by_admin' => $actor->isAdmin,
            ]);

            return $booking;
        });
    }

    public static function update(
        Actor $actor,
        Booking $booking,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        ?string $purpose = null,
    ): Booking {
        BookingPolicy::assertCanModify($booking, $actor);

        if (!$actor->isAdmin) {
            // Both the booking as it stands and the booking as proposed must be
            // far enough in the future.
            $rules = RuleSet::fromSettings();
            $now = Clock::now();

            if ($error = BookingRules::changeNoticeError($rules, $booking->startsAt, $now)) {
                throw BookingException::invalid([$error]);
            }

            self::assertRules($actor, $startUtc, $endUtc, $booking->id, $booking->userId, $booking->equipmentId);
        } else {
            self::assertSane($startUtc, $endUtc);
        }

        $purpose = self::normalisePurpose($purpose);

        return Db::get()->transaction(static function () use ($booking, $startUtc, $endUtc, $purpose): Booking {
            Equipment::lock($booking->equipmentId);

            if (Bookings::findOverlap($booking->equipmentId, $startUtc, $endUtc, $booking->id) !== null) {
                throw BookingException::slotTaken();
            }

            Db::get()->update('bookings', [
                'starts_at'  => Clock::sql($startUtc),
                'ends_at'    => Clock::sql($endUtc),
                'purpose'    => $purpose,
                'updated_at' => Clock::sql(),
            ], 'id = ?', [$booking->id]);

            $updated = Bookings::find($booking->id);

            if ($updated === null) {
                throw new \RuntimeException('Booking disappeared while being updated.');
            }

            Audit::log('booking_updated', 'booking', $booking->id, [
                'from' => ['starts_at' => Clock::sql($booking->startsAt), 'ends_at' => Clock::sql($booking->endsAt)],
                'to'   => ['starts_at' => Clock::sql($startUtc), 'ends_at' => Clock::sql($endUtc)],
            ]);

            return $updated;
        });
    }

    /**
     * Cancel a booking. The row is kept with status 'cancelled' so the audit
     * trail still explains who had the equipment and what happened.
     */
    public static function cancel(Actor $actor, Booking $booking): void
    {
        BookingPolicy::assertCanModify($booking, $actor);

        if (!$actor->isAdmin) {
            $error = BookingRules::changeNoticeError(RuleSet::fromSettings(), $booking->startsAt, Clock::now());

            if ($error !== null) {
                throw BookingException::invalid([$error]);
            }
        }

        Db::get()->update('bookings', [
            'status'       => 'cancelled',
            'cancelled_at' => Clock::sql(),
            'updated_at'   => Clock::sql(),
        ], 'id = ? AND status = "confirmed"', [$booking->id]);

        Audit::log('booking_cancelled', 'booking', $booking->id, [
            'owner_id'  => $booking->userId,
            'starts_at' => Clock::sql($booking->startsAt),
            'by_admin'  => $actor->isAdmin,
        ]);
    }

    /**
     * Remove a booking outright. Admin only - the requirement asks for it - and
     * recorded in the audit log, which is what remains of the booking.
     */
    public static function delete(Actor $actor, Booking $booking): void
    {
        if (!$actor->isAdmin) {
            throw HttpException::forbidden('Only the administrator can delete a booking outright.');
        }

        Audit::log('booking_deleted', 'booking', $booking->id, [
            'owner_id'  => $booking->userId,
            'owner'     => $booking->ownerNetid,
            'starts_at' => Clock::sql($booking->startsAt),
            'ends_at'   => Clock::sql($booking->endsAt),
            'purpose'   => $booking->purpose,
        ]);

        Db::get()->query('DELETE FROM bookings WHERE id = ?', [$booking->id]);
    }

    /**
     * @throws BookingException
     */
    private static function assertRules(
        Actor $actor,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        ?int $excludeBookingId,
        int $ownerUserId,
        int $equipmentId,
    ): void {
        $rules = RuleSet::fromSettings();
        $activeCount = Bookings::countUpcomingForUser($ownerUserId, $equipmentId, $excludeBookingId);

        $errors = BookingRules::validate(
            rules: $rules,
            startUtc: $startUtc,
            endUtc: $endUtc,
            now: Clock::now(),
            activeBookingCount: $activeCount,
            countsAgainstQuota: true,
        );

        if ($errors !== []) {
            throw BookingException::invalid($errors);
        }
    }

    /**
     * The two things that hold even for the administrator: a booking must have
     * positive length, and it must not be absurdly long.
     */
    private static function assertSane(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc): void
    {
        if ($endUtc <= $startUtc) {
            throw BookingException::invalid(['A booking must end after it starts.']);
        }

        if ($endUtc->getTimestamp() - $startUtc->getTimestamp() > 31 * 24 * 3600) {
            throw BookingException::invalid(['A single booking cannot be longer than 31 days.']);
        }
    }

    private static function normalisePurpose(?string $purpose): ?string
    {
        $purpose = trim((string) $purpose);

        if ($purpose === '') {
            return null;
        }

        return mb_substr($purpose, 0, 255);
    }
}
