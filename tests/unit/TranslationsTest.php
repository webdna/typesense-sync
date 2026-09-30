<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use Craft;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every string the plugin shows goes through `Craft::t('typesense-sync', …)` and is in the English
 * source file a translator copies (BR-26). A string added to `src/` without an entry here fails,
 * and so does an exception thrown with a bare literal or a `sprintf()`.
 */
class TranslationsTest extends Unit
{
    private const LITERAL = "'((?:[^'\\\\]|\\\\.)*)'";

    public function testEveryTranslatedStringInSrcIsInTheEnglishFile(): void
    {
        $file = require dirname(__DIR__, 2) . '/src/translations/en/typesense-sync.php';
        $this->assertIsArray($file);

        $missing = array_values(array_diff($this->sourceStrings(), array_keys($file)));
        $this->assertSame([], $missing, 'Add these to src/translations/en/typesense-sync.php');

        foreach ($file as $source => $translation) {
            $this->assertSame($source, $translation, 'The English file maps each string to itself');
        }
    }

    public function testNoExceptionIsThrownWithAnUntranslatedMessage(): void
    {
        $bare = [];

        foreach ($this->files('php') as $path => $source) {
            if (preg_match_all('/new \w*Exception\(\s*(?:[\'"]|sprintf\()/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [, $offset]) {
                    $bare[] = $path . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $bare);
    }

    public function testPlaceholdersAndPluralsAreFilledThroughTheFile(): void
    {
        $this->assertSame('prod_content_1 is up to date.', Craft::t('typesense-sync', '{name} is up to date.', ['name' => 'prod_content_1']));
        $this->assertSame(
            'Nothing was recreated; a_2, b_2 built for this run were deleted',
            Craft::t('typesense-sync', 'Nothing was recreated; {names} built for this run {n, plural, =1{was} other{were}} deleted', ['names' => 'a_2, b_2', 'n' => 2]),
        );
    }

    /**
     * The message argument of every `Craft::t('typesense-sync', …)` in PHP — both branches of a
     * ternary, and the one double-quoted string — and every `'…'|t('typesense-sync')` in Twig.
     *
     * @return list<string>
     */
    private function sourceStrings(): array
    {
        $unquote = static fn(string $s): string => (string)preg_replace('/\\\\([\'\\\\])/', '$1', $s);
        $strings = [];

        foreach ($this->files('php') as $source) {
            preg_match_all("/Craft::t\\('typesense-sync',\\s*(?:[^'\"()]*?\\?\\s*)?" . self::LITERAL . '(?:\s*:\s*' . self::LITERAL . ')?/', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                foreach (array_slice($match, 1) as $literal) {
                    if ($literal !== '') {
                        $strings[] = $unquote($literal);
                    }
                }
            }

            preg_match_all('/Craft::t\(\'typesense-sync\',\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $matches);
            foreach ($matches[1] as $literal) {
                $strings[] = (string)preg_replace('/\\\\([$"\\\\])/', '$1', $literal);
            }
        }

        foreach ($this->files('twig') as $source) {
            preg_match_all('/' . self::LITERAL . "\\s*\\|\\s*t\\(\\s*'typesense-sync'/", $source, $matches);
            foreach ($matches[1] as $literal) {
                $strings[] = $unquote($literal);
            }
        }

        $strings = array_values(array_unique($strings));
        $this->assertGreaterThan(200, count($strings), 'the extraction found the strings');

        return $strings;
    }

    /**
     * @return array<string, string> Source by path, for every file under src/ with the extension.
     */
    private function files(string $extension): array
    {
        $src = dirname(__DIR__, 2) . '/src';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[substr($file->getPathname(), strlen($src) + 1)] = (string)file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
