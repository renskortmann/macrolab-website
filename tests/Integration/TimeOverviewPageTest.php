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
 * The time overview page: two cards. The time registrations table (hours per
 * activity per person, one week or one day, with its own navigation), then
 * the CSV export (the filter on one line, the entries it selects - oldest
 * first, five rows in view - and the download links).
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

    public function testThePageHasTwoCardsTheTableFirst(): void
    {
        $body = $this->page();

        self::assertSame(2, substr_count($body, '<section class="card">'));
        self::assertMatchesRegularExpression(
            '#<section class="card">\s*<h1>Time registrations table</h1>\s*<div class="period-toolbar">'
            . '(?:(?!</section>).)*</section>\s*'
            . '<section class="card">\s*<h2>Export to CSV</h2>(?:(?!</section>).)*<form[^>]*class="filter filter-line">'
            . '(?:(?!</section>).)*<div class="table-scroll"(?:(?!</section>).)*with semicolons</a>#s',
            $body
        );

        foreach (['Time overview</h1>', 'Time registrations overview', 'By activity', 'Totals', 'Total hours'] as $gone) {
            self::assertStringNotContainsString($gone, $body);
        }
    }

    public function testTheTableShowsEachPersonsHoursAndShares(): void
    {
        $ann = Users::create('aberg', 'Ann van den Berg', role: Role::LabTechnician);
        $tidying = Projects::create('Lab tidying');
        foreach ([[$tidying->id, 30], [Projects::findByName('Equipment maintenance')->id, 90]] as [$projectId, $minutes]) {
            TimeEntryService::create(
                actor: Actor::forUser($ann),
                projectId: $projectId,
                workedOn: new DateTimeImmutable('2026-09-03', new DateTimeZone('UTC')),
                minutes: $minutes,
                note: null,
            );
        }

        $body = $this->page(['date' => '2026-09-02']);

        self::assertStringContainsString('<h2 class="period-title">31 Aug - 6 Sep 2026</h2>', $body, 'Monday to Sunday');
        self::assertMatchesRegularExpression(
            '#<th>Activity</th>\s*<th class="num" scope="col">Ann van den Berg</th>\s*'
            . '<th class="num" scope="col">xia</th>#',
            $body,
            'by last name, the netID standing in for a missing name'
        );
        self::assertMatchesRegularExpression(
            '#<td>Equipment maintenance</td>\s*<td class="num">1:30 <span class="muted">\(75%\)</span></td>\s*'
            . '<td class="num">2:00 <span class="muted">\(100%\)</span></td>#',
            $body,
            'xia logged 2 and 5 September; 9 September is the next week'
        );
        self::assertMatchesRegularExpression(
            '#<td>Lab tidying</td>\s*<td class="num">0:30 <span class="muted">\(25%\)</span></td>\s*'
            . '<td class="num"><span class="muted">-</span></td>#',
            $body
        );
        self::assertMatchesRegularExpression(
            '#<th scope="row">Total</th>\s*<td class="num">2:00 <span class="muted">\(100%\)</span></td>\s*'
            . '<td class="num">2:00 <span class="muted">\(100%\)</span></td>#',
            $body
        );
    }

    public function testACellWithNotesCarriesThemForThePopup(): void
    {
        $xia = Users::findByNetid('xia');
        TimeEntryService::create(
            actor: Actor::forUser($xia),
            projectId: Projects::findByName('Equipment maintenance')->id,
            workedOn: new DateTimeImmutable('2026-09-04', new DateTimeZone('UTC')),
            minutes: 30,
            note: 'Pump <b>replaced</b>',
        );

        $body = $this->page(['date' => '2026-09-02']);

        self::assertMatchesRegularExpression(
            '#<td class="num has-notes" tabindex="0" aria-describedby="notes-0-0">\s*2:30 <span class="muted">\(100%\)</span>\s*'
            . '<div class="cell-notes" id="notes-0-0" hidden>\s*<p class="cell-notes-title">xia &middot; Equipment maintenance</p>#',
            $body
        );
        self::assertStringContainsString(
            '<li><span class="muted">Fri 4 Sep, 0:30</span> Pump &lt;b&gt;replaced&lt;/b&gt;</li>',
            $body,
            'the note, escaped, with its day and hours'
        );
        self::assertSame(1, substr_count($body, 'has-notes'), 'only the cell with a note gets a popup');
    }

    public function testTheTableIgnoresTheFilterBelowIt(): void
    {
        $body = $this->page(['date' => '2026-09-02', 'from' => '2026-09-08', 'to' => '2026-09-10']);

        self::assertStringContainsString('<td class="num">2:00 <span class="muted">(100%)</span></td>', $body);
    }

    public function testThisWeekIsShownFirstAndMaySayNothingWasLogged(): void
    {
        $body = $this->page();

        self::assertStringContainsString('<h2 class="period-title">14 - 20 Sep 2026</h2>', $body);
        self::assertStringContainsString('Nothing logged this week.', $body);
        self::assertStringContainsString('<a class="btn-dark" aria-disabled="true">Today</a>', $body);
        self::assertMatchesRegularExpression('#period=week&amp;date=2026-09-14[^"]*"\s+aria-current="true">Week</a>#', $body);
    }

    public function testTheDayViewShowsOneDay(): void
    {
        $body = $this->page(['period' => 'day', 'date' => '2026-09-05']);

        self::assertStringContainsString('<h2 class="period-title">5 Sep 2026</h2>', $body);
        self::assertStringContainsString('<td class="num">1:00 <span class="muted">(100%)</span></td>', $body);
        self::assertStringContainsString('aria-label="Previous day"', $body);
        self::assertStringContainsString('period=day&amp;date=2026-09-04', $body);
    }

    public function testTheTableAndTheFilterKeepEachOthersState(): void
    {
        $body = $this->page(['date' => '2026-09-02', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        self::assertStringContainsString(
            'href="/admin/time?period=week&amp;date=2026-08-24&amp;from=2026-09-01&amp;to=2026-09-30"',
            $body,
            'the previous week keeps the filter'
        );
        self::assertStringContainsString('<input type="hidden" name="period" value="week">', $body);
        self::assertStringContainsString('<input type="hidden" name="date" value="2026-08-31">', $body);
    }

    public function testTheShowButtonSitsOnTheFilterLine(): void
    {
        self::assertMatchesRegularExpression(
            '#<div class="filter-fields">.*<label for="project">.*<div class="filter-actions">\s*<button type="submit" class="primary">Show</button>\s*</div>\s*</div>\s*</form>#s',
            $this->page()
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
