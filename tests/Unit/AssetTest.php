<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Config;
use PHPUnit\Framework\TestCase;

/**
 * Scripts and styles are cached for a week, so their URL carries the file's
 * modification time: a deployment changes the URL and browsers fetch it anew.
 */
final class AssetTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['app' => ['base_url' => 'https://example.test']]);
    }

    public function testAnExistingFileGetsItsModificationTimeAsVersion(): void
    {
        $file = dirname(__DIR__, 2) . '/public_html/assets/app.js';

        self::assertSame('/assets/app.js?v=' . filemtime($file), asset('/assets/app.js'));
    }

    public function testTheVersionHonoursASubdirectoryMount(): void
    {
        Config::set(['app' => ['base_url' => 'https://example.test/macrolab']]);

        self::assertStringStartsWith('/macrolab/assets/app.css?v=', asset('assets/app.css'));
    }

    public function testAMissingFileIsLinkedWithoutAVersion(): void
    {
        self::assertSame('/assets/nothing-here.js', asset('/assets/nothing-here.js'));
    }
}
