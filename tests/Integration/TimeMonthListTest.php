<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Clock;
use Macrolab\Controller\TimeApiController;
use Macrolab\Controller\TimeController;
use Macrolab\Http\Request;
use Macrolab\Role;
use Macrolab\Time\Projects;
use Macrolab\Time\TimeEntryService;
use Macrolab\Users;

/**
 * "My time registrations" under the day sheet: one month at a time between
 * two arrows, oldest entry first. It folds like every card (app.js).
 */
final class TimeMonthListTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 17 September 2026, 08:00 in the lab.
        Clock::freeze('2026-09-17 06:00:00');

        $user = Users::create('wes', role: Role::LabTechnician);
        $project = Projects::create('Equipment maintenance');
        $other = Projects::create('Lab tidying');

        // Logged out of date order, so the list has to sort them.
        foreach ([['2026-09-03', $project], ['2026-09-01', $other], ['2026-09-02', $project]] as [$day, $p]) {
            TimeEntryService::create(
                actor: Actor::forUser($user),
                projectId: $p->id,
                workedOn: new DateTimeImmutable($day, new DateTimeZone('UTC')),
                minutes: 60,
                note: null,
            );
        }

        Auth::signIn(new Identity(netid: 'wes', method: 'test'));
        Auth::resetCache();
    }

    public function testTheSectionIsAPlainCardTitledFirst(): void
    {
        $body = $this->page();

        self::assertMatchesRegularExpression('#<section class="card" id="time-month"[^>]*>\s*<h2>My time registrations</h2>#', $body);
        self::assertStringNotContainsString('<details', $body);
        self::assertStringContainsString('<span class="day-label month-label">September 2026</span>', $body);
    }

    public function testTheArrowsLeadToTheMonthBeforeAndAfter(): void
    {
        $body = $this->page();

        self::assertStringContainsString('data-month="2026-08"', $body);
        self::assertStringContainsString('aria-label="Previous month: August 2026"', $body);
        self::assertStringContainsString('data-month="2026-10"', $body);
        self::assertStringContainsString('aria-label="Next month: October 2026"', $body);
    }

    public function testAMonthInTheAddressIsShown(): void
    {
        self::assertStringContainsString(
            '<span class="day-label month-label">August 2026</span>',
            $this->page(['month' => '2026-08'])
        );
    }

    public function testEntriesAreListedOldestFirst(): void
    {
        $body = $this->page();

        $first = strpos($body, 'Tue 1 Sep 2026');
        $second = strpos($body, 'Wed 2 Sep 2026');
        $third = strpos($body, 'Thu 3 Sep 2026');

        self::assertNotFalse($first);
        self::assertTrue($first < $second && $second < $third, '1, 2, 3 September in that order');
    }

    public function testTheListEndpointRendersTheSameSection(): void
    {
        $response = (new TimeApiController())->month(new Request('GET', '/api/time/month',
            query: ['day' => '2026-09-17', 'month' => '2026-10']));

        self::assertStringContainsString('<h2>My time registrations</h2>', $response->body);
        self::assertStringContainsString('<span class="day-label month-label">October 2026</span>', $response->body);
    }

    /** @param array<string, string> $query */
    private function page(array $query = []): string
    {
        return (new TimeController())->show(new Request('GET', '/time', query: $query))->body;
    }
}
