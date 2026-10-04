<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use Macrolab\Auth;
use Macrolab\Booking\Bookings;
use Macrolab\Clock;
use Macrolab\Csrf;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Booking\Resources;
use Macrolab\Booking\RuleSet;
use Macrolab\Session;
use Macrolab\View;

/**
 * The booking calendar, one machine at a time. Reached from the hub at /.
 *
 * Plain /booking shows no machine: the calendar is empty and read-only until
 * the member picks one, which reloads the page as /booking?machine=<slug>.
 */
final class CalendarController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();
        $resource = Resources::selected($request->query('machine'));
        $rules = RuleSet::fromSettings();

        return View::page('calendar', [
            'title'    => $resource === null ? 'Booking' : (string) $resource['name'],
            'resource' => $resource,
            'machines' => Resources::allActive(),
            'actor'    => $actor,
            'rules'    => $rules,
            'csrf'     => Csrf::token(),
            'mine'     => $actor->userId() === null ? [] : Bookings::forUser($actor->userId()),
            // Handed to the browser as data attributes; the client mirrors the
            // rules for a civilised UI, but the server is what enforces them.
            'clientRules' => [
                // null until a machine is picked: the browser then shows an
                // empty, read-only calendar.
                'resourceId'       => $resource === null ? null : (int) $resource['id'],
                'slotMinutes'      => $rules->slotMinutes,
                'openTime'         => $rules->openTime,
                'closeTime'        => $rules->closeTime,
                'openDays'         => $rules->openDays,
                'minMinutes'       => $rules->minMinutes,
                'maxMinutes'       => $rules->maxMinutes,
                'maxAdvanceDays'   => $rules->maxAdvanceDays,
                'timezone'         => $rules->timezone,
                'isAdmin'          => $actor->isAdmin,
                'userId'           => $actor->userId(),
                // Shown as "Booked for" when a member opens a new booking.
                'userLabel'        => $actor->label(),
                'nowIso'           => Clock::now()->setTimezone($rules->zone())->format('c'),
            ],
        ]);
    }
}
