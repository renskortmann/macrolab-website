<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Macrolab\Actor;
use Macrolab\Auth;
use Macrolab\Booking\Booking;
use Macrolab\Booking\BookingException;
use Macrolab\Booking\BookingPolicy;
use Macrolab\Booking\BookingService;
use Macrolab\Booking\Bookings;
use Macrolab\Clock;
use Macrolab\Csrf;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Booking\Equipment;
use Macrolab\Users;
use Macrolab\View;

/**
 * The JSON API the calendar talks to.
 *
 * Every write re-checks authorisation against the loaded booking, so a request
 * naming somebody else's booking id is refused here even though the UI would
 * never offer the option.
 */
final class BookingApiController
{
    /** GET /api/bookings?equipment=&from=&to= - the calendar feed. */
    public function feed(Request $request): Response
    {
        $actor = Auth::requireActor();
        $equipment = Equipment::requireActive($request->query('equipment'));

        $from = Clock::parseInstant($request->query('from', '') ?? '')
            ?? Clock::now()->modify('-1 week');
        $to = Clock::parseInstant($request->query('to', '') ?? '')
            ?? Clock::now()->modify('+3 months');

        if ($to <= $from) {
            throw HttpException::unprocessable('The requested date range is empty.');
        }

        // Keep one request from asking for the entire history.
        if ($to->getTimestamp() - $from->getTimestamp() > 400 * 24 * 3600) {
            $to = $from->modify('+400 days');
        }

        $bookings = Bookings::inWindow((int) $equipment['id'], $from, $to);

        return Response::json(array_map(
            static fn (Booking $b): array => self::toEvent($b, $actor),
            $bookings
        ));
    }

    /**
     * GET /api/bookings/mine - the member's upcoming bookings, rendered as the
     * list under the calendar, so it can be refreshed after a change.
     */
    public function mine(Request $request): Response
    {
        $actor = Auth::requireActor();
        $userId = $actor->userId();

        return Response::html(View::render('my_bookings', [
            'bookings' => $userId === null ? [] : Bookings::forUser($userId),
        ]));
    }

    /** POST /api/bookings */
    public function create(Request $request): Response
    {
        $actor = Auth::requireActor();
        Csrf::verify($request);

        $equipment = Equipment::requireActive($request->post('equipment'));
        [$start, $end] = $this->readInterval($request);

        try {
            $booking = BookingService::create(
                actor: $actor,
                equipmentId: (int) $equipment['id'],
                startUtc: $start,
                endUtc: $end,
                purpose: $request->post('purpose'),
                ownerUserId: $this->readOwner($request, $actor),
            );
        } catch (BookingException $e) {
            return self::problem($e);
        }

        return Response::json(self::toEvent($booking, $actor), 201);
    }

    /** POST /api/bookings/{id} */
    public function update(Request $request, string $id): Response
    {
        $actor = Auth::requireActor();
        Csrf::verify($request);

        $booking = $this->mustFind($id);
        [$start, $end] = $this->readInterval($request);

        try {
            $updated = BookingService::update(
                actor: $actor,
                booking: $booking,
                startUtc: $start,
                endUtc: $end,
                purpose: $request->post('purpose') ?? $booking->purpose,
            );
        } catch (BookingException $e) {
            return self::problem($e);
        }

        return Response::json(self::toEvent($updated, $actor));
    }

    /** POST /api/bookings/{id}/cancel */
    public function cancel(Request $request, string $id): Response
    {
        $actor = Auth::requireActor();
        Csrf::verify($request);

        $booking = $this->mustFind($id);

        try {
            BookingService::cancel($actor, $booking);
        } catch (BookingException $e) {
            return self::problem($e);
        }

        return Response::json(['ok' => true, 'id' => $booking->id]);
    }

    private function mustFind(string $id): Booking
    {
        if (!ctype_digit($id)) {
            throw HttpException::notFound('No such booking.');
        }

        $booking = Bookings::find((int) $id);

        if ($booking === null) {
            throw HttpException::notFound('No such booking.');
        }

        return $booking;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function readInterval(Request $request): array
    {
        $start = Clock::parseInstant($request->post('start', '') ?? '');
        $end = Clock::parseInstant($request->post('end', '') ?? '');

        if ($start === null || $end === null) {
            throw HttpException::unprocessable('A booking needs a start and an end time.');
        }

        return [$start, $end];
    }

    /** Only the admin may name a different owner. */
    private function readOwner(Request $request, Actor $actor): ?int
    {
        $netid = $request->post('owner_netid');

        if (!$actor->isAdmin || $netid === null || $netid === '') {
            return null;
        }

        $user = Users::findByNetid($netid);

        if ($user === null) {
            throw HttpException::unprocessable(
                'No user with netID "' . $netid . '". Add them to the allowlist first.'
            );
        }

        return $user->id;
    }

    private static function problem(BookingException $e): Response
    {
        return Response::json([
            'error'  => $e->errors[0] ?? 'That booking could not be saved.',
            'errors' => $e->errors,
        ], $e->status);
    }

    /**
     * A booking as the calendar needs it.
     *
     * Other people's bookings show who holds the equipment - that is the point of
     * a shared calendar - but never their stated purpose.
     *
     * @return array<string, mixed>
     */
    private static function toEvent(Booking $booking, Actor $actor): array
    {
        $isOwn = $actor->userId() !== null && $actor->userId() === $booking->userId;
        $zone = Clock::displayZone();

        return [
            'id'    => (string) $booking->id,
            'start' => $booking->startsAt->setTimezone($zone)->format(DateTimeInterface::ATOM),
            'end'   => $booking->endsAt->setTimezone($zone)->format(DateTimeInterface::ATOM),
            'title' => $isOwn ? 'You' : $booking->ownerLabel(),
            'classNames' => [$isOwn ? 'booking-own' : 'booking-other'],
            'extendedProps' => [
                'own'        => $isOwn,
                'owner'      => BookingPolicy::canSeeOwner($actor) ? $booking->ownerLabel() : 'Booked',
                'ownerNetid' => BookingPolicy::canSeeOwner($actor) ? $booking->ownerNetid : null,
                'purpose'    => BookingPolicy::canSeePurpose($booking, $actor) ? $booking->purpose : null,
                'canModify'  => BookingPolicy::canModify($booking, $actor),
                'byAdmin'    => $booking->createdByAdmin,
            ],
            'editable' => BookingPolicy::canModify($booking, $actor),
        ];
    }
}
