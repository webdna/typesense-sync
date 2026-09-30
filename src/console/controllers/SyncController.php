<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Console;
use craft\helpers\Queue;
use Throwable;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\jobs\Reindex;
use webdna\typesensesync\services\Sync;
use yii\console\ExitCode;

/**
 * Sends content to Typesense, or empties a collection.
 *
 * Reindexes every declared collection or one of them, syncs a single element, and flushes.
 *
 * @since 1.0.0
 */
class SyncController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var string|null One collection handle. Defaults to every declared collection.
     */
    public ?string $collection = null;

    /**
     * @var bool After the reindex, delete every document the run did not build.
     */
    public bool $prune = false;

    /**
     * @var bool Push a reindex job per collection to the queue instead of running it here.
     */
    public bool $queue = false;

    /**
     * @var string|null The handle of the site to sync the element in. Defaults to the site its
     * declared source indexes.
     */
    public ?string $site = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'index' => ['collection', 'prune', 'queue'],
            'element' => ['site'],
            'flush' => ['confirm'],
            default => [],
        });
    }

    /**
     * Reindex every declared collection, or the one named with --collection.
     *
     * Upserts in batches, so search keeps answering throughout. Documents for content that no
     * longer qualifies stay unless --prune is given; a run that builds nothing prunes nothing.
     * Counter fields keep their live values.
     */
    public function actionIndex(): int
    {
        // BR-12, BR-13, BR-14.
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
            if (self::settings()->getTargetsForCollection($handle) === []) {
                $this->stdout("$handle: " . Craft::t('typesense-sync', 'nothing enabled is declared to feed it, skipped') . "\n", Console::FG_GREY);
                continue;
            }

            // A reindex into a collection that does not exist fails document by document.
            try {
                $live = self::plugin()->collections->getActiveCollectionName($handle);
            } catch (Throwable $e) {
                $this->stderr("$handle: " . $e->getMessage() . "\n", Console::FG_RED);
                $ok = false;
                continue;
            }

            if ($live === null) {
                $this->stderr("$handle: " . Craft::t('typesense-sync', 'does not exist on the server yet. Run `craft typesense-sync/collections/apply` first.') . "\n", Console::FG_RED);
                $ok = false;
                continue;
            }

            $ok = ($this->queue ? $this->push($handle) : $this->reindex($handle)) && $ok;
        }

        return self::exit($ok);
    }

    /**
     * Sync one element now, writing its document or removing it if it no longer qualifies.
     *
     * @param int $id The element id.
     */
    public function actionElement(int $id): int
    {
        // Re-derived from the element, as a queued sync is (BR-8).
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        $siteId = null;

        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);

            if ($site === null) {
                $this->stderr(Craft::t('typesense-sync', 'No site has the handle “{site}”.', ['site' => $this->site]) . "\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $siteId = $site->id;
        }

        $element = Craft::$app->getElements()->getElementById($id, null, $siteId, ['status' => null]);

        if (!$element instanceof ElementInterface) {
            $this->stderr(Craft::t('typesense-sync', 'No element exists with the ID {id}.', ['id' => $id]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $targets = self::plugin()->targets;

        if (!$targets->isSyncable($element)) {
            $this->stderr(Craft::t('typesense-sync', 'Element {id} is a draft or revision; only the published element is synced.', ['id' => $id]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($targets->resolveTargetFor($element) === null) {
            $this->stderr(Craft::t('typesense-sync', 'Element {id} is not declared for search, so it is not synced.', ['id' => $id]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $outcome = self::plugin()->sync->syncElement($element);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $message = match ($outcome) {
            Sync::OUTCOME_INDEXED => Craft::t('typesense-sync', 'Element {id} was indexed.', ['id' => $id]),
            Sync::OUTCOME_REMOVED => Craft::t('typesense-sync', 'Element {id} does not qualify for search, so its document was removed.', ['id' => $id]),
            Sync::OUTCOME_REJECTED => Craft::t('typesense-sync', 'Typesense rejected element {id}’s document; the typesense-sync log says why.', ['id' => $id]),
            default => Craft::t('typesense-sync', 'Element {id} was not synced; the typesense-sync log says why.', ['id' => $id]),
        };

        $ok = in_array($outcome, [Sync::OUTCOME_INDEXED, Sync::OUTCOME_REMOVED], true);
        $ok ? $this->stdout($message . "\n", Console::FG_GREEN) : $this->stderr($message . "\n", Console::FG_RED);

        return self::exit($ok);
    }

    /**
     * Delete every document in a collection, keeping the collection and its schema.
     *
     * Search returns nothing until it is reindexed. The handle must be typed to confirm, or
     * given with --confirm.
     *
     * @param string $collection The collection handle.
     */
    public function actionFlush(string $collection): int
    {
        // The typed handle is BR-22.
        if (!$this->checkDeclared($collection)) {
            return ExitCode::USAGE;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $live = self::plugin()->collections->getActiveCollectionName($collection);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($live === null) {
            $this->stderr(Craft::t('typesense-sync', '“{collection}” does not exist on the server, so there is nothing to flush.', ['collection' => $collection]) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $question = Craft::t('typesense-sync', 'This deletes every document in {name}, and search returns nothing until it is reindexed.', ['name' => $live]);

        if (!$this->confirmByHandle($collection, $question)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $deleted = self::plugin()->sync->flush($collection);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Craft::t('typesense-sync', 'Flushed {name}: {count} documents deleted.', ['name' => $live, 'count' => $deleted]) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function reindex(string $handle): bool
    {
        $this->stdout("$handle\n", Console::BOLD);
        $sync = self::plugin()->sync;
        $progress = function(int $indexed, string $target): void {
            $this->stdout("\r  " . Craft::t('typesense-sync', '{count} indexed ({target})', ['count' => $indexed, 'target' => $target]) . '    ');
        };

        $run = $this->prune ? $sync->reindexAndPrune($handle, $progress) : $sync->reindex($handle, null, $progress);
        $this->stdout("\r  " . Craft::t('typesense-sync', '{count} documents indexed', ['count' => $run['indexed']]) . "    \n", Console::FG_GREEN);
        $ok = $this->reportRun($run);

        if (!$this->prune) {
            return $ok;
        }

        if ($run['pruned'] !== null) {
            $this->stdout('  ' . Craft::t('typesense-sync', '{count} stale documents removed', ['count' => $run['pruned']]) . "\n", Console::FG_GREEN);

            return $ok;
        }

        // Nothing built means nothing was compared, so the prune was skipped rather than failed:
        // pruning then would have emptied the collection (BR-13).
        if ($run['indexed'] + $run['rejected'] + $run['failed'] === 0) {
            $this->stdout('  ! ' . Craft::t('typesense-sync', 'Prune skipped: the run built no documents, so nothing was removed.') . "\n", Console::FG_YELLOW);

            return $ok;
        }

        $this->stderr('  ' . Craft::t('typesense-sync', 'The prune failed; nothing was removed. The typesense-sync log says why.') . "\n", Console::FG_RED);

        return false;
    }

    private function push(string $handle): bool
    {
        Queue::push(new Reindex(['collection' => $handle, 'prune' => $this->prune]), self::settings()->queuePriority);
        $this->stdout("$handle: " . Craft::t('typesense-sync', 'reindex queued') . "\n", Console::FG_GREEN);

        return true;
    }
}
