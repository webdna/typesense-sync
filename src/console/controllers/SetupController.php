<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\helpers\Console;
use Throwable;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\services\Collections;
use yii\console\ExitCode;

/**
 * Sets up a Typesense server for this site from nothing.
 *
 * Checks the config and the connection, creates or alters every declared collection, and
 * indexes their content. Safe to run again: apply changes only what differs, and indexing upserts.
 *
 * @since 1.0.0
 */
class SetupController extends Controller
{
    public $defaultAction = 'run';

    /**
     * @var bool Create and alter the collections, but index no documents.
     */
    public bool $skipSync = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['skipSync']);
    }

    /**
     * Check the config and connection, apply every collection, then index their content.
     *
     * Refuses, changing nothing, on a config problem, an unreachable server or one older than
     * 30.0, or a search-only key that allows more than search.
     */
    public function actionRun(): int
    {
        // BR-4, BR-24, BR-19, in that order.
        $this->stdout(Craft::t('typesense-sync', 'Configuration') . "\n", Console::BOLD);

        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        $handles = array_keys(self::settings()->getCollectionConfigs());

        if ($handles === []) {
            $this->stderr(Craft::t('typesense-sync', 'No collections are declared. Copy one of the configs in examples/config to config/typesense-sync.php.') . "\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $this->stdout('  ' . Craft::t('typesense-sync', 'no problems') . "\n", Console::FG_GREEN);

        if (!$this->checkConnection()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n" . Craft::t('typesense-sync', 'Collections') . "\n", Console::BOLD);
        $ok = true;
        $live = [];

        foreach ($handles as $handle) {
            try {
                $result = self::plugin()->collections->apply($handle);
            } catch (Throwable $e) {
                $this->stderr("  $handle: " . $e->getMessage() . "\n", Console::FG_RED);
                $ok = false;
                continue;
            }

            $failed = in_array($result['action'], [Collections::ACTION_NEEDS_RECREATE, Collections::ACTION_BLOCKED], true);
            $failed
                ? $this->stderr("  $handle: " . $result['message'] . "\n", Console::FG_RED)
                : $this->stdout("  $handle: " . $result['message'] . "\n", $result['action'] === Collections::ACTION_SKIP ? Console::FG_GREY : Console::FG_GREEN);

            if ($failed) {
                $ok = false;
            } elseif ($result['action'] !== Collections::ACTION_SKIP) {
                $live[] = $handle;
            }
        }

        if (!$this->skipSync) {
            $this->stdout("\n" . Craft::t('typesense-sync', 'Indexing') . "\n", Console::BOLD);

            foreach ($live as $handle) {
                $ok = $this->index($handle) && $ok;
            }

            if ($live === []) {
                $this->stdout('  ' . Craft::t('typesense-sync', 'nothing to index') . "\n", Console::FG_GREY);
            }
        }

        $this->stdout("\n");

        if (!$ok) {
            $this->stderr(Craft::t('typesense-sync', 'Setup did not finish cleanly; see above.') . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Craft::t('typesense-sync', 'Done. `craft typesense-sync/collections/status` shows each collection.') . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The server must answer, be 30.0 or later, and any search-only key must allow search and
     * nothing else. Without a search-only key setup carries on: indexing needs only the admin
     * key, but the front end cannot search, so it is said.
     */
    private function checkConnection(): bool
    {
        $this->stdout("\n" . Craft::t('typesense-sync', 'Server') . "\n", Console::BOLD);

        // Covers what serverProblem() does — unconfigured, unreachable, a refused key, < 30.0 —
        // and the search-only key's actions too.
        $test = self::plugin()->client->testConnection();

        if (!$test['ok']) {
            foreach ($test['problems'] as $problem) {
                $this->stderr('  ' . $problem . "\n", Console::FG_RED);
            }

            return false;
        }

        $this->stdout('  ' . Craft::t('typesense-sync', 'Typesense {version}', ['version' => (string)$test['version']]) . "\n", Console::FG_GREEN);

        if (self::settings()->getSearchApiKey() === '') {
            $this->stdout('  ! ' . Craft::t('typesense-sync', 'No search-only API key is set, so pages cannot search. Create one allowing only `documents:search` and enter it in the plugin settings.') . "\n", Console::FG_YELLOW);
        }

        return true;
    }

    private function index(string $handle): bool
    {
        $run = self::plugin()->sync->reindex($handle, null, function(int $indexed) use ($handle): void {
            $this->stdout("\r  $handle: " . Craft::t('typesense-sync', '{count} indexed', ['count' => $indexed]) . '    ');
        });

        $this->stdout("\r  $handle: " . Craft::t('typesense-sync', '{count} documents indexed', ['count' => $run['indexed']]) . "    \n", Console::FG_GREEN);

        return $this->reportRun($run);
    }
}
