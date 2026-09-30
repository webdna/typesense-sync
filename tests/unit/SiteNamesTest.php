<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No name from the site the plugin was extracted from reaches `src/` (BR-25). The same pattern
 * as B5 #2's grep, so the suite fails where the grep would have printed.
 */
class SiteNamesTest extends Unit
{
    private const PATTERN = '/members|marketplace|lll|legacy|goodStanding|companies|Features::/i';

    public function testNoSiteSpecificNameAppearsInSrc(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match(self::PATTERN, $line) === 1) {
                    $found[] = sprintf('%s:%d: %s', $file->getFilename(), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $found);
    }
}
