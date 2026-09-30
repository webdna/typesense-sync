<?php

namespace webdna\typesensesync\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\typesensesync\TypesenseSync;

/**
 * Recreate one collection and every collection joining into it, behind their aliases (BR-15).
 *
 * Queued from the utility rather than run in the request: a build walks every element of the
 * collection and its dependants, which outlasts a CP request's time limit on any real site. A
 * failed build changes nothing, so the job fails and is not retried; running it again is safe.
 *
 * @since 1.0.0
 */
class Recreate extends BaseJob
{
    /**
     * Seconds the queue lets the job run before reclaiming it; push it with this. A queue that
     * reclaimed it mid-build would start a second run, which the recreate mutex then refuses.
     */
    public const TTR = 3600;

    /**
     * Collection handle.
     */
    public ?string $collection = null;

    public function execute($queue): void
    {
        if ($this->collection === null) {
            return;
        }

        $rows = TypesenseSync::getInstance()->collections->recreate(
            $this->collection,
            function(string $handle, int $indexed) use ($queue): void {
                $this->setProgress($queue, 0.5, Craft::t('typesense-sync', '{collection}: {count} indexed', [
                    'collection' => $handle,
                    'count' => $indexed,
                ]));
            },
        );

        foreach ($rows as $row) {
            Craft::info(sprintf(
                'Recreated "%s": %s → %s, %d indexed, %d rejected, %d re-synced.',
                $row['collection'],
                $row['from'] ?? 'nothing',
                $row['to'],
                $row['indexed'],
                $row['rejected'],
                $row['resynced'],
            ), TypesenseSync::HANDLE);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense-sync', 'Recreating {collection} in Typesense', ['collection' => (string)$this->collection]);
    }
}
