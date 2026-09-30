<?php

namespace webdna\typesensesync\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\TypesenseSync;

/**
 * Re-send every element of one collection, and optionally prune what the run did not build
 * (BR-12, BR-13).
 *
 * Not retried: a failed batch is logged and the run carries on, and the job then fails naming
 * how many documents were not written, so the queue shows it. Running it again is safe — it
 * upserts.
 *
 * @since 1.0.0
 */
class Reindex extends BaseJob
{
    /**
     * Collection handle.
     */
    public ?string $collection = null;

    public bool $prune = false;

    public function execute($queue): void
    {
        if ($this->collection === null) {
            return;
        }

        $sync = TypesenseSync::getInstance()->sync;

        if (TypesenseSync::getInstance()->targets->getSettings()->getCollectionConfig($this->collection) === null) {
            throw new SyncException(Craft::t('typesense-sync', 'No collection "{collection}" is declared in config/typesense-sync.php.', ['collection' => (string)$this->collection]));
        }

        $progress = function(int $indexed) use ($queue): void {
            // There is no total to divide by without counting everything first.
            $this->setProgress($queue, 0.5, Craft::t('typesense-sync', '{count} indexed', ['count' => $indexed]));
        };

        $run = $this->prune
            ? $sync->reindexAndPrune($this->collection, $progress)
            : $sync->reindex($this->collection, null, $progress);

        Craft::info(sprintf(
            'Reindexed "%s": %d indexed, %d rejected, %d not written%s.',
            $this->collection,
            $run['indexed'],
            $run['rejected'],
            $run['failed'],
            $this->prune ? ', ' . ($run['pruned'] ?? 'none') . ' pruned' : '',
        ), TypesenseSync::HANDLE);

        if ($run['failed'] > 0) {
            throw new SyncException(Craft::t('typesense-sync', '{count} documents of "{collection}" could not be written; see the typesense-sync log.', ['count' => $run['failed'], 'collection' => (string)$this->collection]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense-sync', 'Reindexing {collection} in Typesense', ['collection' => (string)$this->collection]);
    }
}
