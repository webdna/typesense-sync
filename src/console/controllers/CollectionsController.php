<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\helpers\Console;
use craft\helpers\Json;
use Throwable;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\services\Collections;
use yii\console\ExitCode;

/**
 * Shows, creates, alters and rebuilds the declared collections.
 *
 * @since 1.0.0
 */
class CollectionsController extends Controller
{
    public $defaultAction = 'status';

    /**
     * @var string|null One collection handle. Defaults to every declared collection; required by
     * recreate.
     */
    public ?string $collection = null;

    /**
     * @var bool Say what apply would send, and send nothing.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'apply' => ['collection', 'dryRun'],
            'recreate' => ['collection', 'confirm'],
            default => ['collection'],
        });
    }

    /**
     * Compare each declared schema with the live collection. Changes nothing.
     */
    public function actionStatus(): int
    {
        $configOk = $this->checkConfig();
        $handles = $this->handles($this->collection);

        if ($handles === null) {
            return ExitCode::USAGE;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $ok = true;

        foreach ($handles as $handle) {
            $ok = $this->status($handle) && $ok;
        }

        $this->stdout("\n");

        return $configOk ? self::exit($ok) : ExitCode::CONFIG;
    }

    /**
     * Create missing collections, or alter live ones in place to match the config.
     *
     * Never reindexes; says when one is needed. --dry-run prints what would be sent.
     */
    public function actionApply(): int
    {
        // BR-16.
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        $handles = $this->handles($this->collection);

        if ($handles === null) {
            return ExitCode::USAGE;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $ok = true;

        foreach ($handles as $handle) {
            $ok = $this->apply($handle) && $ok;
        }

        return self::exit($ok);
    }

    /**
     * Rebuild a collection and every collection joining into it, then swap their aliases.
     *
     * Each is built into a new version while search keeps answering from the current one. The
     * handle must be typed to confirm, or given with --confirm.
     */
    public function actionRecreate(): int
    {
        // BR-15; the typed handle is BR-22.
        if ($this->collection === null) {
            $this->stderr(Craft::t('typesense-sync', 'Name the collection to recreate with --collection.') . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $handle = $this->collection;

        if (!$this->checkDeclared($handle)) {
            return ExitCode::USAGE;
        }

        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $collections = self::plugin()->collections;

        try {
            $current = $collections->getActiveCollectionName($handle);
            $dependants = $collections->getDependants($handle);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Craft::t('typesense-sync', 'Recreating “{collection}” (live: {current}).', [
            'collection' => $handle,
            'current' => $current ?? Craft::t('typesense-sync', 'not created yet'),
        ]) . "\n");

        if ($dependants !== []) {
            $this->stdout(Craft::t('typesense-sync', 'Joined into by {dependants}, rebuilt in the same run so their joins keep answering.', [
                'dependants' => implode(', ', $dependants),
            ]) . "\n", Console::FG_YELLOW);
        }

        $question = Craft::t('typesense-sync', 'Each is built into a new version and reindexed, then the aliases move and the old versions are dropped.');

        if (!$this->confirmByHandle($handle, $question)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $building = null;

        try {
            $rows = $collections->recreate($handle, function(string $collection, int $indexed, string $target) use (&$building): void {
                if ($collection !== $building) {
                    $this->stdout(($building === null ? '' : "\n") . "  $collection\n", Console::BOLD);
                    $building = $collection;
                }

                $this->stdout("\r    " . Craft::t('typesense-sync', '{count} indexed ({target})', ['count' => $indexed, 'target' => $target]) . '    ');
            });
        } catch (Throwable $e) {
            $this->stderr("\n" . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr(Craft::t('typesense-sync', 'No alias was moved; search still answers from the current versions.') . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");
        $ok = true;

        foreach ($rows as $row) {
            $this->stdout(Craft::t('typesense-sync', '{collection}: {from} → {to}, {indexed} documents, {resynced} re-synced after the swap', [
                'collection' => $row['collection'],
                'from' => $row['from'] ?? '-',
                'to' => $row['to'],
                'indexed' => $row['indexed'],
                'resynced' => $row['resynced'],
            ]) . "\n", Console::FG_GREEN);

            if ($row['rejected'] > 0) {
                $this->stderr('  ' . Craft::t('typesense-sync', '{count} documents were rejected by Typesense; the typesense-sync log names them.', [
                    'count' => $row['rejected'],
                ]) . "\n", Console::FG_RED);
                $ok = false;
            }
        }

        return self::exit($ok);
    }

    private function status(string $handle): bool
    {
        try {
            $alias = self::settings()->getCollectionConfig($handle)?->getName() ?? $handle;
        } catch (Throwable $e) {
            $alias = $e->getMessage();
        }

        $this->stdout("\n$handle ", Console::BOLD);
        $this->stdout("($alias)\n");

        try {
            $diff = self::plugin()->collections->diff($handle);
        } catch (Throwable $e) {
            $this->stderr('  ' . $e->getMessage() . "\n", Console::FG_RED);

            return false;
        }

        if (!$diff['exists']) {
            $this->stdout('  ' . Craft::t('typesense-sync', 'not created yet; apply would create {count} fields', ['count' => count($diff['added'])]) . "\n", Console::FG_YELLOW);

            return true;
        }

        $this->stdout('  ' . Craft::t('typesense-sync', 'alias → {collection}, {count} documents', [
            'collection' => (string)$diff['collection'],
            'count' => $diff['documents'],
        ]) . "\n");

        if ($diff['upToDate']) {
            $this->stdout('  ' . Craft::t('typesense-sync', 'up to date') . "\n", Console::FG_GREEN);

            return true;
        }

        foreach ($diff['added'] as $name) {
            $this->stdout("  + $name\n", Console::FG_GREEN);
        }

        foreach ($diff['dropped'] as $name) {
            $this->stdout("  - $name\n", Console::FG_RED);
        }

        foreach ($diff['changed'] as $name => $change) {
            $this->stdout(sprintf("  ~ %s  %s → %s\n", $name, Json::encode($change['from']), Json::encode($change['to'])), Console::FG_YELLOW);
        }

        if ($diff['needsRecreate']) {
            foreach ($diff['recreateReasons'] as $reason) {
                $this->stdout("  ! $reason\n", Console::FG_RED);
            }

            $this->stdout('  ' . Craft::t('typesense-sync', 'Needs `craft typesense-sync/collections/recreate --collection={collection}`; apply cannot make this change.', [
                'collection' => $handle,
            ]) . "\n", Console::FG_RED);
        }

        return true;
    }

    private function apply(string $handle): bool
    {
        $this->stdout("$handle: ", Console::BOLD);

        try {
            $result = self::plugin()->collections->apply($handle, $this->dryRun);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return false;
        }

        $failed = in_array($result['action'], [Collections::ACTION_NEEDS_RECREATE, Collections::ACTION_BLOCKED], true);

        if ($failed) {
            $this->stderr($result['message'] . "\n", Console::FG_RED);

            return false;
        }

        $quiet = in_array($result['action'], [Collections::ACTION_NONE, Collections::ACTION_SKIP], true);
        $this->stdout($result['message'] . "\n", $quiet ? Console::FG_GREY : Console::FG_GREEN);

        if ($this->dryRun && $result['payload'] !== null) {
            $this->stdout(Json::encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", Console::FG_GREY);
        }

        if (!$this->dryRun && $result['reindex']) {
            $this->stdout('  ' . Craft::t('typesense-sync', 'Run `craft typesense-sync/sync --collection={collection}` so every document has the new fields.', [
                'collection' => $handle,
            ]) . "\n", Console::FG_YELLOW);
        }

        return true;
    }
}
