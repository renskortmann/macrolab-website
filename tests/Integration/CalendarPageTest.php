<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Booking\Resources;
use Macrolab\Controller\CalendarController;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Users;

/**
 * /booking starts with no machine: an empty, read-only calendar until the
 * member picks one. Nothing is remembered between visits.
 */
final class CalendarPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Users::create('kim');
        Auth::signIn(new Identity(netid: 'kim', method: 'test'));
    }

    public function testPlainBookingShowsNoMachine(): void
    {
        $response = $this->show();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Choose a machine', $response->body);
        self::assertSame(null, $this->config($response)['resourceId']);
    }

    public function testANamedMachineIsShown(): void
    {
        $machine = Resources::primary();

        $response = $this->show(['machine' => $machine['slug']]);

        self::assertStringContainsString((string) $machine['name'], $response->body);
        self::assertSame((int) $machine['id'], $this->config($response)['resourceId']);

        // The booking dialog names the machine and whom the booking is for.
        self::assertMatchesRegularExpression(
            '#<dt>Machine</dt>\s*<dd>' . preg_quote((string) $machine['name'], '#') . '</dd>#',
            $response->body
        );
        self::assertSame('kim', $this->config($response)['userLabel']);
    }

    public function testTheChoiceIsNotRememberedForTheNextVisit(): void
    {
        $this->show(['machine' => Resources::primary()['slug']]);

        self::assertSame(null, $this->config($this->show())['resourceId']);
    }

    public function testAnUnknownOrRetiredMachineShowsNoMachine(): void
    {
        self::assertSame(null, $this->config($this->show(['machine' => 'no-such-machine']))['resourceId']);

        $retired = Resources::create('Old microscope');
        Resources::setActive((int) $retired['id'], false);

        self::assertSame(null, $this->config($this->show(['machine' => $retired['slug']]))['resourceId']);
    }

    /** @param array<string, string> $query */
    private function show(array $query = []): Response
    {
        Auth::resetCache();

        return (new CalendarController())->show(new Request('GET', '/booking', query: $query));
    }

    /** @return array<string, mixed> The configuration the page hands to the calendar script. */
    private function config(Response $response): array
    {
        self::assertSame(1, preg_match('/data-config="([^"]*)"/', $response->body, $m), 'the calendar is on the page');

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }
}
