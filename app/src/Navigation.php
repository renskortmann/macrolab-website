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
     * Which destination the page at $path belongs to - the tab to mark as
     * current in the top bar - or null (the hub, the sign-in page).
     *
     * A destination owns its own path and everything below it: /time/123 is
     * Time registration. When two match, the longer one is more specific and
     * wins: /time/overview is Time overview, not Time registration, and
     * /admin/time is the admin's Time overview, not Administration.
     *
     * @param list<array{href: string, label: string, blurb?: string}> $destinations
     */
    public static function current(array $destinations, string $path): ?string
    {
        $best = null;

        foreach ($destinations as $destination) {
            $href = $destination['href'];
            $owns = $path === $href
                || str_starts_with($path, $href . '/')
                || str_starts_with($path, $href . '.');

            if ($owns && ($best === null || strlen($href) > strlen($best))) {
                $best = $href;
            }
        }

        return $best;
    }

    /**
     * The tabs of the second ribbon on the Administration pages. Time overview
     * is not among them: it is a tab of the top ribbon already, and on its page
     * the administration ribbon is not shown (layout.php).
     *
     * @return list<array{href: string, label: string}>
     */
    public static function adminTabs(): array
    {
        return [
            ['href' => '/admin',           'label' => 'Dashboard'],
            ['href' => '/admin/users',     'label' => 'Who may sign in'],
            ['href' => '/admin/equipment', 'label' => 'Equipment'],
            ['href' => '/admin/bookings',  'label' => 'All bookings'],
            ['href' => '/admin/projects',  'label' => 'Activities'],
            ['href' => '/admin/settings',  'label' => 'Rules'],
            ['href' => '/admin/audit',     'label' => 'Audit log'],
            ['href' => '/admin/system',    'label' => 'System'],
        ];
    }

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
                'blurb' => 'Log your hours on the activities under the general lab code. This function is only available to lab technicians and manager, not to regular lab users.',
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
