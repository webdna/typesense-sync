<?php

namespace webdna\typesensesync\tests\Support;

/**
 * Loads the copy-in examples under `examples/config/`, so the tests run on what a developer
 * copies rather than on a fixture that only resembles it.
 */
final class Examples
{
    public const SNIPPETS = ['users', 'categories', 'products', 'analytics'];

    public static function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/examples/' . $relative;
    }

    /**
     * One file's array, as `require` returns it.
     *
     * @return array<string, mixed>
     */
    public static function config(string $name): array
    {
        return require self::path("config/$name.php");
    }

    /**
     * The base example with snippets merged in the way `examples/README.md` says: collections by
     * handle, sources appended, analytics set.
     *
     * @return array<string, mixed>
     */
    public static function merged(string ...$snippets): array
    {
        $config = self::config('typesense-sync');

        foreach ($snippets as $name) {
            $snippet = self::config($name);
            $config['collections'] = ($config['collections'] ?? []) + ($snippet['collections'] ?? []);
            $config['sources'] = [...($config['sources'] ?? []), ...($snippet['sources'] ?? [])];

            if (isset($snippet['analytics'])) {
                $config['analytics'] = $snippet['analytics'];
            }
        }

        return $config;
    }

    /**
     * The config with each source's handle renamed, for a test site whose handles carry a
     * per-run suffix.
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $handles Example handle => test handle.
     * @return array<string, mixed>
     */
    public static function withHandles(array $config, array $handles): array
    {
        foreach ($config['sources'] as $index => $source) {
            if (isset($source['handle'], $handles[$source['handle']])) {
                $config['sources'][$index]['handle'] = $handles[$source['handle']];
            }
        }

        return $config;
    }
}
