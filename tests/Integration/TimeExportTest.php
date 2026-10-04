<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Auth;
use Macrolab\Clock;
use Macrolab\Controller\AdminTimeController;
use Macrolab\Csv;
use Macrolab\Http\Request;
use Macrolab\Time\Projects;
use Macrolab\Time\TimeEntryService;
use Macrolab\Users;

/**
 * The CSV export at /admin/time.csv, in both flavours: commas with a decimal
 * point, and semicolons with a decimal comma for Excel with European settings.
 */
final class TimeExportTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Clock::freeze('2026-09-17 06:00:00');

        $user = Users::create('lee', 'Lee');
        $project = Projects::create('Equipment maintenance', 'MNT');

        TimeEntryService::create(
            actor: Actor::forUser($user),
            projectId: $project->id,
            workedOn: new DateTimeImmutable('2026-09-16', new DateTimeZone('UTC')),
            minutes: 210,
            note: 'Pump; filters, seals',
        );

        Auth::completeAdminLogin(1, 'admin');
    }

    public function testTheCommaExportUsesCommasAndADecimalPoint(): void
    {
        $lines = $this->export('comma');

        self::assertSame('date,netid,name,activity,activity_code,hours,minutes,note,entry_id', $lines[0]);
        self::assertStringContainsString(',3.50,210,"Pump; filters, seals",', $lines[1]);
    }

    public function testTheSemicolonExportUsesSemicolonsAndADecimalComma(): void
    {
        $lines = $this->export('semicolon');

        self::assertSame('date;netid;name;activity;activity_code;hours;minutes;note;entry_id', $lines[0]);
        self::assertStringContainsString(';3,50;210;"Pump; filters, seals";', $lines[1]);
    }

    public function testWithoutAChoiceItIsCommas(): void
    {
        self::assertStringStartsWith('date,netid', $this->export(null)[0]);
    }

    /** @return list<string> The CSV's lines, without the byte-order mark. */
    private function export(?string $separator): array
    {
        $query = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        if ($separator !== null) {
            $query['sep'] = $separator;
        }

        $response = (new AdminTimeController())->export(new Request('GET', '/admin/time.csv', query: $query));

        self::assertStringStartsWith(Csv::BOM, $response->body);

        return explode("\n", trim(substr($response->body, strlen(Csv::BOM))));
    }
}
