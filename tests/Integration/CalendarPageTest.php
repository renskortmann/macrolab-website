<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Booking\Equipment;
use Macrolab\Controller\CalendarController;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Users;

/**
 * /booking starts with no equipment: an empty, read-only calendar until the
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

    public function testPlainBookingShowsNoEquipment(): void
    {
        $response = $this->show();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Choose equipment', $response->body);
        self::assertSame(null, $this->config($response)['equipmentId']);
    }

    public function testNamedEquipmentIsShown(): void
    {
        $piece = Equipment::primary();

        $response = $this->show(['equipment' => $piece['slug']]);

        self::assertStringContainsString((string) $piece['name'], $response->body);
        self::assertSame((int) $piece['id'], $this->config($response)['equipmentId']);

        // The booking dialog names the equipment and whom the booking is for.
        self::assertMatchesRegularExpression(
            '#<dt>Equipment</dt>\s*<dd>' . preg_quote((string) $piece['name'], '#') . '</dd>#',
            $response->body
        );
        self::assertSame('kim', $this->config($response)['userLabel']);
    }

    public function testTheOldMachineParameterNoLongerSelectsAnything(): void
    {
        // ?machine= was dropped when the app switched to "equipment".
        self::assertSame(null, $this->config($this->show(['machine' => Equipment::primary()['slug']]))['equipmentId']);
    }

    public function testTheChoiceIsNotRememberedForTheNextVisit(): void
    {
        $this->show(['equipment' => Equipment::primary()['slug']]);

        self::assertSame(null, $this->config($this->show())['equipmentId']);
    }

    public function testUnknownOrRetiredEquipmentShowsNone(): void
    {
        self::assertSame(null, $this->config($this->show(['equipment' => 'no-such-equipment']))['equipmentId']);

        $retired = Equipment::create('Old microscope');
        Equipment::setActive((int) $retired['id'], false);

        self::assertSame(null, $this->config($this->show(['equipment' => $retired['slug']]))['equipmentId']);
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
