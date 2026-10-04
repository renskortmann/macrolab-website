<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Auth;
use Macrolab\Clock;
use Macrolab\Controller\AdminTimeController;
use Macrolab\Http\Request;
use Macrolab\Role;
use Macrolab\Time\Projects;
use Macrolab\Time\TimeEntryService;
use Macrolab\Users;

/**
 * The time overview page: the entries first (folded, oldest first, five rows
 * in view), then the hours per activity (folded), then the filter and export.
 */
final class TimeOverviewPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Clock::freeze('2026-09-17 06:00:00');

        $user = Users::create('xia', role: Role::LabTechnician);
        $project = Projects::create('Equipment maintenance');

        // Logged out of date order, so the page has to sort them.
        foreach (['2026-09-09', '2026-09-02', '2026-09-05'] as $day) {
            TimeEntryService::create(
                actor: Actor::forUser($user),
                projectId: $project->id,
                workedOn: new DateTimeImmutable($day, new DateTimeZone('UTC')),
                minutes: 60,
                note: null,
            );
        }

        Auth::completeAdminLogin(1, 'admin');
    }

    public function testTheSectionsComeInTheirNewOrder(): void
    {
        self::assertMatchesRegularExpression(
            '#<h1>Time registrations overview</h1>.*<h2>By activity</h2>.*<h2>Export to CSV</h2>#s',
            $this->page()
        );
    }

    public function testBothUpperSectionsStartFolded(): void
    {
        $body = $this->page();

        self::assertSame(2, substr_count($body, '<details class="foldable">'), 'entries and by activity, both folded');
        self::assertStringNotContainsString('<details class="foldable" open>', $body);
    }

    public function testUsingTheFilterOpensTheEntries(): void
    {
        $body = $this->page(['from' => '01-09-2026', 'to' => '30-09-2026']);

        self::assertMatchesRegularExpression(
            '#<details class="foldable" open>\s*<summary>\s*<h1>Time registrations overview</h1>#',
            $body
        );
    }

    public function testTheEntriesScrollFiveAtATimeOldestFirst(): void
    {
        $body = $this->page();

        self::assertStringContainsString('<div class="table-scroll" data-visible-rows="5">', $body);

        $second = strpos($body, '2 Sep 2026');
        $fifth = strpos($body, '5 Sep 2026');
        $ninth = strpos($body, '9 Sep 2026');
        self::assertTrue($second < $fifth && $fifth < $ninth, '2, 5, 9 September in that order');
    }

    /** @param array<string, string> $query  empty: a plain visit, no filter in the address */
    private function page(array $query = []): string
    {
        return (new AdminTimeController())->entries(new Request('GET', '/admin/time', query: $query))->body;
    }
}
