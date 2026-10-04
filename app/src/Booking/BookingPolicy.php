<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use Macrolab\Actor;
use Macrolab\Audit;
use Macrolab\Http\HttpException;

/**
 * Who may change what. The whole of the "users can edit their own bookings but
 * not other people's" requirement lives here, and every write path goes
 * through assertCanModify() - never through a check in a template or a hidden
 * form field.
 */
final class BookingPolicy
{
    public static function canModify(?Booking $booking, ?Actor $actor): bool
    {
        if ($booking === null || $actor === null) {
            return false;
        }

        // The administrator may change any booking, including cancelled ones
        // and ones already in the past.
        if ($actor->isAdmin) {
            return true;
        }

        if (!$booking->isConfirmed()) {
            return false;
        }

        return $actor->userId() !== null && $actor->userId() === $booking->userId;
    }

    /**
     * @throws HttpException 403 when the actor does not own the booking
     */
    public static function assertCanModify(?Booking $booking, ?Actor $actor): void
    {
        if (self::canModify($booking, $actor)) {
            return;
        }

        if ($booking !== null && $actor !== null) {
            Audit::log('booking_change_refused', 'booking', $booking->id, [
                'reason'      => $booking->isConfirmed() ? 'not_owner' : 'not_confirmed',
                'owner_id'    => $booking->userId,
                'attempted_by' => $actor->userId(),
            ]);
        }

        throw HttpException::forbidden('That booking belongs to someone else.');
    }

    /**
     * Whether the actor may see who else booked a slot. Everyone signed in can:
     * knowing who has the equipment before you is the point of a shared
     * calendar. What they may not see is anyone else's stated purpose.
     */
    public static function canSeeOwner(?Actor $actor): bool
    {
        return $actor !== null;
    }

    public static function canSeePurpose(Booking $booking, ?Actor $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $actor->isAdmin || $actor->userId() === $booking->userId;
    }
}
