<?php

namespace webdna\typesensesync\utilities;

use Craft;
use craft\base\Utility as BaseUtility;
use Throwable;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\TypesenseSync;

/**
 * Utilities → Typesense Sync: the connection, the config's problems, and each collection's
 * health — its alias and live version, document count and schema difference — with the actions
 * that fix what it shows.
 *
 * Everything is read live from the server, because this is the page a person opens when search
 * is wrong. So nothing here may throw: an unreachable server or a broken formatter becomes a
 * message on the page (BR-10).
 *
 * @since 1.0.0
 */
class Utility extends BaseUtility
{
    /**
     * The permission Craft derives from the utility's id; the utility's actions, the element
     * action and the edit-screen menu item all require it (BR-22).
     */
    public const PERMISSION = 'utility:typesense-sync';

    public static function displayName(): string
    {
        return Craft::t('typesense-sync', 'Typesense Sync');
    }

    public static function id(): string
    {
        return 'typesense-sync';
    }

    public static function icon(): ?string
    {
        return 'magnifying-glass';
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('typesense-sync/_utility.twig', self::variables());
    }

    /**
     * What the utility shows, as data. Public so it can be tested without rendering.
     *
     * `state` is one of `unconfigured` (no host or admin key), `unreachable` (the server did not
     * answer, or is older than 30.0), `empty` (connected, nothing declared) or `ready`. Once
     * `ready`, `analytics` holds each declared rule's state, or null while analytics is off.
     *
     * @return array<string, mixed>
     */
    public static function variables(): array
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);
        $settings = $plugin->targets->getSettings();

        $variables = [
            'state' => 'unconfigured',
            'version' => null,
            'connectionProblems' => [],
            'problems' => $settings->getProblems(),
            'warnings' => $settings->getWarnings(),
            'collections' => [],
            'analytics' => null,
        ];

        if (!$settings->isConfigured()) {
            return $variables;
        }

        $connection = $plugin->client->testConnection();
        $variables['version'] = $connection['version'];
        $variables['connectionProblems'] = $connection['problems'];

        // A search-key problem is shown, but the collections can still be worked on; a server that
        // did not answer, or is too old, cannot (BR-24).
        if ($connection['version'] === null || Client::versionProblem($connection['version']) !== null) {
            $variables['state'] = 'unreachable';

            return $variables;
        }

        $handles = array_keys($settings->getCollectionConfigs());
        $variables['state'] = $handles === [] ? 'empty' : 'ready';

        foreach ($handles as $handle) {
            $variables['collections'][] = self::describeCollection($handle);
        }

        $variables['analytics'] = self::describeAnalytics();

        return $variables;
    }

    /**
     * Each declared analytics rule against the server, or null while analytics is off or nothing
     * is declared. Read-only: rules are applied from the console (`analytics/apply`), where the
     * events key they may need is also made.
     *
     * @return array{rules: list<array<string, mixed>>, unmanaged: string[], error: string|null}|null
     */
    private static function describeAnalytics(): ?array
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        if (!$plugin->analytics->isEnabled()) {
            return null;
        }

        try {
            $diff = $plugin->analytics->diff();

            return ['rules' => array_values($diff['rules']), 'unmanaged' => $diff['unmanaged'], 'error' => null];
        } catch (Throwable $e) {
            // A server without analytics switched on refuses the read; say so on the page.
            return ['rules' => [], 'unmanaged' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeCollection(string $handle): array
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);
        $config = $plugin->targets->getSettings()->getCollectionConfig($handle);

        $row = [
            'handle' => $handle,
            'alias' => $config?->getName() ?? '',
            'fieldCount' => 0,
            'diff' => null,
            'dependants' => [],
            'error' => null,
        ];

        try {
            $row['fieldCount'] = count($plugin->collections->getDesiredFields($handle));
            $row['diff'] = $plugin->collections->diff($handle);
            $row['dependants'] = $plugin->collections->getDependants($handle);
        } catch (Throwable $e) {
            // A field conflict, a failing formatter or a cycle shows on its own row, not as an
            // error page.
            $row['error'] = $e->getMessage();
        }

        return $row;
    }
}
