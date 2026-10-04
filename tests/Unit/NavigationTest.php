<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Actor;
use Macrolab\Navigation;
use Macrolab\Role;
use Macrolab\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which tab in the top bar is the current page's.
 */
final class NavigationTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function memberPaths(): iterable
    {
        yield 'booking'                  => ['/booking', '/booking'];
        yield 'time registration'        => ['/time', '/time'];
        yield 'changing a time entry'    => ['/time/123', '/time'];
        yield 'time overview, not /time' => ['/time/overview', '/time/overview'];
        yield 'the overview export'      => ['/time/overview.csv', '/time/overview'];
        yield 'my account'               => ['/account', '/account'];
        yield 'the hub has no tab'       => ['/', null];
        yield 'a lookalike path'         => ['/timetable', null];
    }

    #[DataProvider('memberPaths')]
    public function testTheCurrentTabForAMember(string $path, ?string $expected): void
    {
        // A lab manager has every member tab.
        $actor = Actor::forUser(new User(id: 1, netid: 'x', displayName: null, email: null,
            status: 'approved', role: Role::LabManager));

        self::assertSame($expected, Navigation::current(Navigation::destinations($actor), $path));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function adminPaths(): iterable
    {
        yield 'administration'            => ['/admin', '/admin'];
        yield 'an administration page'    => ['/admin/users', '/admin'];
        yield 'time overview, not /admin' => ['/admin/time', '/admin/time'];
        yield 'booking'                   => ['/booking', '/booking'];
    }

    #[DataProvider('adminPaths')]
    public function testTheCurrentTabForTheAdministrator(string $path, ?string $expected): void
    {
        self::assertSame($expected, Navigation::current(Navigation::destinations(Actor::forAdmin()), $path));
    }
}
