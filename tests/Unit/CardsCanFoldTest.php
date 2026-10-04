<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every card folds (wireFoldableCards() in app.js), which needs a heading at
 * the top of the card: an h1 or h2, or the calendar's header row holding one.
 * A card without that would be the one card on the site that does not fold.
 */
final class CardsCanFoldTest extends TestCase
{
    public function testEveryCardStartsWithItsHeading(): void
    {
        $offenders = [];
        $views = dirname(__DIR__, 2) . '/app/views';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Blank out short echo tags, so a ">" inside one does not end a tag early.
            $source = (string) preg_replace('#<\?=.*?\?>#s', 'X', (string) file_get_contents($file->getPathname()));
            preg_match_all('#<section class="card[^"]*"[^>]*>(.{0,200})#s', $source, $matches);

            foreach ($matches[1] as $after) {
                // Skip whitespace and PHP comments; then the heading must come.
                $after = (string) preg_replace('#^(\s|<\?php\s*/\*.*?\*/\s*\?>)*#s', '', $after);
                if (preg_match('#^(<h1|<h2|<div class="calendar-head">)#', $after) !== 1) {
                    $offenders[] = substr($file->getPathname(), strlen($views) + 1) . ': ' . strtok($after, "\n");
                }
            }
        }

        self::assertSame([], $offenders, 'start each card with its h1 or h2');
    }

    public function testNoCardFoldsItsOwnWay(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/app/views/{,*/}*.php', GLOB_BRACE) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), '<details')) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        self::assertSame([], $offenders, 'cards fold through app.js, not <details>');
    }
}
