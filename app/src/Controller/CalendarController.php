<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use Macrolab\Auth;
use Macrolab\Booking\Bookings;
use Macrolab\Clock;
use Macrolab\Csrf;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Booking\Equipment;
use Macrolab\Booking\RuleSet;
use Macrolab\Session;
use Macrolab\View;

/**
 * The booking calendar, one piece of equipment at a time. Reached from the hub at /.
 *
 * Plain /booking shows no equipment: the calendar is empty and read-only until
 * the member picks a piece, which reloads the page as /booking?equipment=<slug>.
 */
final class CalendarController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();
        $equipment = Equipment::selected($request->query('equipment'));
        $rules = RuleSet::fromSettings();

        return View::page('calendar', [
            // Neither the heading nor the browser tab changes with the
            // equipment; the date label above the calendar names it.
            'title'         => 'Booking',
            'equipment'     => $equipment,
            'equipmentList' => Equipment::allActive(),
            'actor'    => $actor,
            'rules'    => $rules,
            'csrf'     => Csrf::token(),
            'mine'     => $actor->userId() === null ? [] : Bookings::forUser($actor->userId()),
            // Handed to the browser as data attributes; the client mirrors the
            // rules for a civilised UI, but the server is what enforces them.
            'clientRules' => [
                // null until a piece of equipment is picked: the browser then shows an
                // empty, read-only calendar.
                'equipmentId'      => $equipment === null ? null : (int) $equipment['id'],
                // Leads the date label above the calendar.
                'equipmentName'    => $equipment === null ? null : (string) $equipment['name'],
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
