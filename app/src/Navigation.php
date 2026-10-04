<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * The places a signed-in actor can go.
 *
 * The hub page and the top bar render this one list, so that adding a system to
 * the site is a single edit rather than two that drift apart.
 *
 * Paths are app-relative and are passed through path() at render time, exactly
 * like every other link in the templates.
 */
final class Navigation
{
    /**
     * @return list<array{href: string, label: string, blurb: string}>
     */
    public static function destinations(?Actor $actor): array
    {
        if ($actor === null) {
            return [];
        }

        /*
         * The administrator keeps no timesheet and holds no bookings of their
         * own - Actor::forAdmin() has no user id - so they are sent to the
         * administration pages rather than to personal pages that would have
         * nothing to show them. This is the same split as the calendar at
         * /booking versus the management screen at /admin/bookings.
         */
        if ($actor->isAdmin) {
            return [
                [
                    'href'  => '/booking',
                    'label' => 'Booking',
                    'blurb' => 'The shared calendar for the lab equipment.',
                ],
                [
                    'href'  => '/admin/time',
                    'label' => 'Time overview',
                    'blurb' => 'What everyone has logged, with filters and a CSV export.',
                ],
                [
                    'href'  => '/admin',
                    'label' => 'Administration',
                    'blurb' => 'People, equipment, activities, rules and the audit log.',
                ],
            ];
        }

        // Every member books equipment; the role adds the time pages. A lab user
        // is not shown time registration at all (and is refused at /time).
        $destinations = [[
            'href'  => '/booking',
            'label' => 'Booking',
            'blurb' => 'Book time on lab equipment, and change or cancel your own bookings.',
        ]];

        if ($actor->canRegisterTime()) {
            $destinations[] = [
                'href'  => '/time',
                'label' => 'Time registration',
                'blurb' => 'Log your hours on the activities under the general lab code.',
            ];
        }

        if ($actor->canViewAllTime()) {
            $destinations[] = [
                'href'  => '/time/overview',
                'label' => 'Time overview',
                'blurb' => 'What everyone has logged, with filters and a CSV export. This function is only available to the lab manager and to the system administrator.',
            ];
        }

        $destinations[] = [
            'href'  => '/account',
            'label' => 'My account',
            'blurb' => 'Your details, and your password.',
        ];

        return $destinations;
    }
}
