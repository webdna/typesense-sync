<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Queue;
use Throwable;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\events\DeleteDocumentEvent;
use webdna\typesensesync\events\IndexDocumentEvent;
use webdna\typesensesync\events\SyncEvent;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\jobs\DeleteElement;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\ResolvedTarget;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SourceConfig;
use webdna\typesensesync\TypesenseSync;

/**
 * Keeps documents in step with elements: queues work from element events, and writes, deletes,
 * reindexes and prunes documents from queue jobs and commands.
 *
 * Every method takes a collection *handle* from the config file, never a Typesense name; the
 * handle's live name is an alias, so a write always lands in whichever version is serving.
 *
 * Queueing never reaches Typesense (BR-10): an element event costs one cache write and one queue
 * push. Writing does, and a failed call throws a SyncException, so the queue job that made it
 * fails and is retried. A document Typesense rejects is logged and not retried, because it would
 * be rejected again.
 *
 * @since 1.0.0
 */
class Sync extends Component
{
    /**
     * Raised before a document is written, by a single sync or a reindex; cancellable.
     *
     * @see IndexDocumentEvent
     */
    public const EVENT_BEFORE_INDEX_DOCUMENT = 'beforeIndexDocument';

    /**
     * Raised after one element's document has been written or removed by a single sync.
     *
     * @see SyncEvent
     */
    public const EVENT_AFTER_SYNC = 'afterSync';

    /**
     * Raised after a document has been deleted, or found already absent.
     *
     * @see DeleteDocumentEvent
     */
    public const EVENT_AFTER_DELETE_DOCUMENT = 'afterDeleteDocument';

    public const OUTCOME_INDEXED = 'indexed';

    public const OUTCOME_REMOVED = 'removed';

    /**
     * Typesense refused the document; the reason is logged.
     */
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * Nothing declares the element, so it was left alone.
     */
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * How long a queued sync suppresses further queueing for the same element and site (BR-9).
     * Losing the flag early costs one duplicate job; keeping it past a stalled queue costs
     * nothing, because the pending job re-derives the document when it runs.
     */
    public const PENDING_TTL = 600;

    /**
     * Ids per `filter_by` list: Typesense caps the length of a filter's value list.
     */
    private const FILTER_CHUNK = 250;

    /**
     * Ids named in a failure's log line before the rest are counted.
     */
    private const LOGGED_IDS = 10;

    // Queueing ----------------------------------------------------------------------------------

    /**
     * Queue a sync of an element, if it belongs in a declared target (BR-1, BR-6) and no sync of
     * it is already pending (BR-9). Public API (BR-27): a site calls this for elements whose
     * documents depend on something that was saved elsewhere.
     *
     * @return bool Whether a job was queued. False also when one was already pending.
     */
    public function queueElement(ElementInterface $element): bool
    {
        [$target, $siteId] = $this->queueTarget($element);

        if ($target === null || $siteId === null) {
            return false;
        }

        $key = $this->pendingKey((int)$element->id, $siteId);
        $cache = Craft::$app->getCache();

        // Set before the push, not after: a worker can take the job the moment it exists, and a
        // flag set after the job had already cleared it would drop every save for the TTL.
        if (!$cache->add($key, true, self::PENDING_TTL)) {
            return false;
        }

        try {
            Queue::push(new SyncElement([
                'elementId' => (int)$element->id,
                'siteId' => $siteId,
                'collection' => $target->collection,
                'documentId' => $this->documentIdFor($this->targets()->formatterFor($target), $element),
                'description' => Craft::t('typesense-sync', 'Syncing {element} to Typesense', ['element' => $this->describe($element)]),
            ]), $this->settings()->queuePriority);
        } catch (Throwable $e) {
            $cache->delete($key);

            throw $e;
        }

        return true;
    }

    /**
     * Queue removal of an element's document. The collection and document id are resolved now,
     * while the element is still loaded, because by the time the job runs it may be gone (BR-6).
     *
     * @return bool Whether a job was queued.
     */
    public function queueDelete(ElementInterface $element): bool
    {
        [$target, $siteId] = $this->queueTarget($element);

        if ($target === null || $siteId === null) {
            return false;
        }

        Queue::push(new DeleteElement([
            'collection' => $target->collection,
            'documentId' => $this->documentIdFor($this->targets()->formatterFor($target), $element),
            'description' => Craft::t('typesense-sync', 'Removing {element} from Typesense', ['element' => $this->describe($element)]),
        ]), $this->settings()->queuePriority);

        return true;
    }

    /**
     * Whether a sync of the element in the site is queued and has not started.
     */
    public function isPending(int $elementId, int $siteId): bool
    {
        return Craft::$app->getCache()->exists($this->pendingKey($elementId, $siteId));
    }

    // Single element ----------------------------------------------------------------------------

    /**
     * What a sync job does: clear the pending flag, load the element afresh and sync it (BR-8).
     *
     * The flag is cleared before the element is loaded, so a save landing while this runs queues
     * a fresh sync rather than being dropped against a job that has already read the old content
     * (BR-9, TN-13). An element that no longer exists has the document it was queued with
     * removed (TN-2).
     *
     * @param string|null $collection The collection handle the element was queued for.
     * @param string|null $documentId The document id it was queued with.
     * @return string One of the OUTCOME_* constants.
     * @throws SyncException when Typesense cannot be reached or refuses the request.
     */
    public function syncById(int $elementId, int $siteId, ?string $collection = null, ?string $documentId = null): string
    {
        Craft::$app->getCache()->delete($this->pendingKey($elementId, $siteId));

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId, ['status' => null]);

        if ($element !== null) {
            return $this->syncElement($element);
        }

        if ($collection === null || $documentId === null) {
            return self::OUTCOME_SKIPPED;
        }

        $this->deleteDocument($documentId, $collection);

        return self::OUTCOME_REMOVED;
    }

    /**
     * Write or remove one element's document, re-deriving everything from the element (BR-8).
     *
     * A disabled or trashed element, one whose formatter says `shouldIndex() === false`, and one
     * an EVENT_BEFORE_INDEX_DOCUMENT handler cancels, have their document deleted: there is no
     * "disabled" flag in a document. An element not declared anywhere is left alone.
     *
     * @return string One of the OUTCOME_* constants.
     * @throws SyncException when Typesense cannot be reached or refuses the request.
     */
    public function syncElement(ElementInterface $element): string
    {
        $targets = $this->targets();
        $target = $targets->resolveTargetFor($element);

        if ($target === null) {
            return self::OUTCOME_SKIPPED;
        }

        $site = $targets->siteFor($target);

        if ($site === null) {
            Craft::warning(sprintf('Element %s was not synced: %s names a site that does not exist.', $element->id, $target->getDescription()), TypesenseSync::HANDLE);

            return self::OUTCOME_SKIPPED;
        }

        $formatter = $targets->formatterFor($target);
        $documentId = $this->documentIdFor($formatter, $element);
        $collection = (string)$target->collection;

        // The target indexes one site; the element may have arrived in another.
        if ((int)$element->siteId !== (int)$site->id) {
            $element = Craft::$app->getElements()->getElementById((int)$element->id, $element::class, $site->id, ['status' => null]);
        }

        $document = $element !== null ? $this->documentFor($element, $target, $formatter) : null;

        if ($document === null) {
            $this->deleteDocument($documentId, $collection);
            $this->afterSync($element, $target, self::OUTCOME_REMOVED, null);

            return self::OUTCOME_REMOVED;
        }

        $result = $this->import($collection, [$document]);

        if ($result['rejected'] !== []) {
            return self::OUTCOME_REJECTED;
        }

        $this->afterSync($element, $target, self::OUTCOME_INDEXED, $document);

        return self::OUTCOME_INDEXED;
    }

    /**
     * Delete one document. A document that is already absent is not an error.
     *
     * @throws SyncException when Typesense cannot be reached or refuses the request.
     */
    public function deleteDocument(string $documentId, string $collection): void
    {
        $name = $this->collectionName($collection);

        try {
            $this->client()->collections[$name]->documents[$documentId]->delete();
        } catch (ObjectNotFound) {
            // Never indexed, or already removed: the outcome wanted.
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not delete document %s from "%s"', $documentId, $name), $e);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_DOCUMENT)) {
            $this->trigger(self::EVENT_AFTER_DELETE_DOCUMENT, new DeleteDocumentEvent([
                'collection' => $collection,
                'documentId' => $documentId,
            ]));
        }
    }

    // Writing -----------------------------------------------------------------------------------

    /**
     * Upsert documents into a collection, keeping its counter fields (BR-14), and read the result
     * of every line (BR-12).
     *
     * @param array<int, array<string, mixed>> $documents Each with an `id`.
     * @param string|null $into A physical collection name to write to instead of the live alias —
     * a version being built, before the alias points at it. Counters are still read from the
     * alias, where the live values are.
     * @return array{written: int, rejected: array<string, string>} Rejected: document id => reason.
     * @throws SyncException when the call fails, or the counters cannot be read (and so nothing
     * is written, since writing would reset them).
     */
    public function import(string $collection, array $documents, ?string $into = null): array
    {
        $documents = array_values($documents);

        if ($documents === []) {
            return ['written' => 0, 'rejected' => []];
        }

        $alias = $this->collectionName($collection);
        $name = $into ?? $alias;
        $documents = $this->withCounters($collection, $alias, $documents);

        try {
            $results = $this->client()->collections[$name]->documents->import($documents, ['action' => 'upsert']);
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not import %s into "%s"', $this->describeIds($documents), $name), $e);
        }

        // import() reports each document's failure in its result rather than throwing, so a
        // partial failure is silent unless every line is read.
        $rejected = [];

        foreach ((array)$results as $index => $result) {
            if (is_array($result) && ($result['success'] ?? true) === false) {
                $id = (string)($documents[$index]['id'] ?? $index);
                $rejected[$id] = (string)($result['error'] ?? 'unknown error');

                Craft::error(sprintf('Typesense rejected document %s for "%s": %s', $id, $name, $rejected[$id]), TypesenseSync::HANDLE);
            }
        }

        return ['written' => count($documents) - count($rejected), 'rejected' => $rejected];
    }

    /**
     * Fields in a collection that Typesense maintains itself and a write must carry through.
     *
     * @return string[]
     */
    public function counterFields(string $collection): array
    {
        return array_values(array_unique($this->settings()->getCollectionConfig($collection)->counters ?? []));
    }

    /**
     * Current counter values for a set of document ids. An id absent from the result is not in
     * the collection; so is every id when the collection does not exist yet.
     *
     * @param string[] $ids
     * @param string[] $fields
     * @return array<string, array<string, int>>
     * @throws SyncException when the values cannot be read — never treat that as zero.
     */
    public function fetchCounters(string $collectionName, array $ids, array $fields): array
    {
        $counters = [];

        foreach (array_chunk(array_values($ids), self::FILTER_CHUNK) as $chunk) {
            try {
                $export = $this->client()->collections[$collectionName]->documents->export([
                    'filter_by' => $this->idFilter($chunk),
                    'include_fields' => implode(',', array_merge(['id'], $fields)),
                ]);
            } catch (ObjectNotFound) {
                return [];
            } catch (Throwable $e) {
                throw $this->failure(sprintf('Could not read the counter fields of "%s"', $collectionName), $e);
            }

            foreach (self::jsonLines($export) as $document) {
                foreach ($fields as $field) {
                    $counters[(string)($document['id'] ?? '')][$field] = (int)($document[$field] ?? 0);
                }
            }
        }

        return $counters;
    }

    // Reindex and prune -------------------------------------------------------------------------

    /**
     * Re-send every element of a collection's declared targets, in batches of the batch size
     * (BR-12). Upserts: documents for elements that no longer qualify stay; reindexAndPrune()
     * removes them.
     *
     * A batch that fails is logged and counted and the run carries on. Its ids still count as
     * built, so a prune that follows does not delete documents because their refresh failed.
     *
     * @param string|null $into A physical collection name to write to instead of the alias.
     * @param callable(int, string): void|null $progress Called with the documents written so far
     * and the target being walked.
     * @return array{indexed: int, rejected: int, failed: int, ids: list<string>} Failed counts
     * documents in batches that could not be written; ids are every document built.
     */
    public function reindex(string $collection, ?string $into = null, ?callable $progress = null): array
    {
        $settings = $this->settings();
        $targets = $this->targets();
        $batchSize = max(1, $settings->batchSize);
        // A handler may reroute or exclude single elements; honour it here too, or a reindex
        // would put back what a single sync keeps out.
        $rerouted = $targets->hasEventHandlers(Targets::EVENT_RESOLVE_TARGET);
        $run = ['indexed' => 0, 'rejected' => 0, 'failed' => 0, 'ids' => []];
        $buffer = [];

        foreach ($settings->getTargetsForCollection($collection) as $target) {
            $query = $this->queryForTarget($target);

            if ($query === null) {
                continue;
            }

            $formatter = $targets->formatterFor($target);

            foreach ($query->batch($batchSize) as $elements) {
                /** @var ElementInterface $element */
                foreach ($elements as $element) {
                    if ($rerouted && $targets->resolveTargetFor($element)?->collection !== $collection) {
                        continue;
                    }

                    $document = $this->documentFor($element, $target, $formatter);

                    if ($document === null) {
                        continue;
                    }

                    $buffer[] = $document;
                    $run['ids'][] = (string)$document['id'];

                    if (count($buffer) >= $batchSize) {
                        $this->writeBatch($collection, $into, $buffer, $run);
                        $buffer = [];

                        if ($progress !== null) {
                            $progress($run['indexed'], $target->getDescription());
                        }
                    }
                }
            }

            $this->writeBatch($collection, $into, $buffer, $run);
            $buffer = [];

            if ($progress !== null) {
                $progress($run['indexed'], $target->getDescription());
            }
        }

        return $run;
    }

    /**
     * Write one reindex batch, counting the outcome into the run. A failed batch is logged by
     * import() and counted, and the run carries on.
     *
     * @param array<int, array<string, mixed>> $batch
     * @param array{indexed: int, rejected: int, failed: int, ids: list<string>} $run
     */
    private function writeBatch(string $collection, ?string $into, array $batch, array &$run): void
    {
        if ($batch === []) {
            return;
        }

        try {
            $result = $this->import($collection, $batch, $into);
            $run['indexed'] += $result['written'];
            $run['rejected'] += count($result['rejected']);
        } catch (SyncException) {
            $run['failed'] += count($batch);
        }
    }

    /**
     * Reindex, then delete every document no formatter built during the run (BR-13).
     *
     * An empty run prunes nothing: a broken query or config would otherwise empty the
     * collection. An element saved while the run is between batches can be pruned; the sync its
     * save queued puts it back.
     *
     * @param callable(int, string): void|null $progress
     * @return array{indexed: int, rejected: int, failed: int, pruned: int|null} Pruned is null
     * when the prune was skipped or failed (logged).
     */
    public function reindexAndPrune(string $collection, ?callable $progress = null): array
    {
        $run = $this->reindex($collection, null, $progress);
        $ids = $run['ids'];
        unset($run['ids']);

        if ($ids === []) {
            Craft::warning(sprintf('The prune of "%s" was skipped: the reindex built no documents.', $collection), TypesenseSync::HANDLE);

            return $run + ['pruned' => null];
        }

        return $run + ['pruned' => $this->prune($collection, $ids)];
    }

    /**
     * Delete every document in a collection whose id is not in `$keep`. Call it only with the ids
     * of a run that built something (BR-13); reindexAndPrune() does.
     *
     * @param string[] $keep
     * @return int|null Documents deleted, or null when Typesense failed (logged).
     */
    public function prune(string $collection, array $keep): ?int
    {
        try {
            $name = $this->collectionName($collection);
            $client = $this->client();
            $export = $client->collections[$name]->documents->export(['include_fields' => 'id']);
            $deleted = 0;

            foreach (array_chunk(self::staleIds($export, $keep), self::FILTER_CHUNK) as $chunk) {
                $result = $client->collections[$name]->documents->delete(['filter_by' => $this->idFilter($chunk)]);
                $deleted += (int)($result['num_deleted'] ?? 0);
            }
        } catch (Throwable $e) {
            Craft::error(sprintf('Could not prune "%s": %s', $collection, $e->getMessage()), TypesenseSync::HANDLE);

            return null;
        }

        return $deleted;
    }

    /**
     * Delete every document in a collection, leaving the collection, its schema and its alias in
     * place. Callers confirm first; the console asks for the handle to be typed (BR-22).
     *
     * @return int Documents deleted.
     * @throws SyncException when Typesense cannot be reached or refuses the request.
     */
    public function flush(string $collection): int
    {
        $name = $this->collectionName($collection);

        try {
            // `truncate` (Typesense 28+) empties the collection without a filter to match.
            $result = $this->client()->collections[$name]->documents->delete(['truncate' => 'true']);
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not flush "%s"', $name), $e);
        }

        $deleted = (int)($result['num_deleted'] ?? 0);
        Craft::info(sprintf('Flushed "%s": %d documents deleted.', $name, $deleted), TypesenseSync::HANDLE);

        return $deleted;
    }

    /**
     * The ids in a JSONL export that are not in `$keep`.
     *
     * @param array<int|string> $keep
     * @return list<string>
     */
    public static function staleIds(string $export, array $keep): array
    {
        $keep = array_flip(array_map('strval', $keep));
        $stale = [];

        foreach (self::jsonLines($export) as $document) {
            $id = (string)($document['id'] ?? '');

            if ($id !== '' && !isset($keep[$id])) {
                $stale[] = $id;
            }
        }

        return $stale;
    }

    /**
     * The element query feeding one target in its site, whatever the elements' status (the sync
     * decides), or null when the target's kind has no query here or its site does not exist.
     *
     * A section's default target excludes the entry types that have their own override, or those
     * entries would be built twice — once by their own formatter and once by the default.
     */
    public function queryForTarget(ResolvedTarget $target): ?ElementQueryInterface
    {
        $source = $target->source;
        $site = $this->targets()->siteFor($target);

        if ($source === null || $site === null) {
            return null;
        }

        $query = match ($source->kind) {
            SourceConfig::KIND_SECTION => $this->entryQuery($target, $source),
            SourceConfig::KIND_CATEGORY_GROUP => Category::find()->group($source->handle),
            // Every user: the formatter decides who is listed, as it does for a single sync.
            SourceConfig::KIND_USERS => User::find(),
            default => null,
        };

        return $query?->siteId($site->id)->status(null);
    }

    // Internals ---------------------------------------------------------------------------------

    /**
     * The target and site an element event would queue for, or nulls when it queues nothing.
     *
     * @return array{0: ResolvedTarget|null, 1: int|null}
     */
    private function queueTarget(ElementInterface $element): array
    {
        $targets = $this->targets();

        // Unconfigured, there is nowhere to write; a reindex catches up once there is.
        if (!$this->settings()->isConfigured() || !$targets->isSyncable($element)) {
            return [null, null];
        }

        $target = $targets->resolveTargetFor($element);

        if ($target === null) {
            return [null, null];
        }

        $site = $targets->siteFor($target);

        if ($site === null) {
            Craft::warning(sprintf('Nothing was queued for element %s: %s names a site that does not exist.', $element->id, $target->getDescription()), TypesenseSync::HANDLE);

            return [null, null];
        }

        return [$target, (int)$site->id];
    }

    /**
     * The document to write for an element, or null when it does not belong in search: disabled,
     * trashed, refused by the formatter, or cancelled by a handler.
     *
     * @return array<string, mixed>|null
     */
    private function documentFor(ElementInterface $element, ResolvedTarget $target, FormatterInterface $formatter): ?array
    {
        if (!$element->enabled || $element->getEnabledForSite() === false || $element->trashed) {
            return null;
        }

        if (!$formatter->shouldIndex($element)) {
            return null;
        }

        $id = $this->documentIdFor($formatter, $element);
        $document = ['id' => $id] + $formatter->format($element);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_DOCUMENT)) {
            $event = new IndexDocumentEvent(['element' => $element, 'target' => $target, 'document' => $document]);
            $this->trigger(self::EVENT_BEFORE_INDEX_DOCUMENT, $event);

            if (!$event->isValid) {
                return null;
            }

            $document = $event->document;
        }

        // The id is what a delete is addressed by (BR-11); nothing may change it on the way.
        $document['id'] = $id;

        return $document;
    }

    /**
     * BR-11: a BaseFormatter says; any other formatter gets the element id.
     */
    private function documentIdFor(FormatterInterface $formatter, ElementInterface $element): string
    {
        return $formatter instanceof BaseFormatter ? $formatter->documentId($element) : (string)$element->id;
    }

    /**
     * Carry each document's counter fields through the write, seeding new documents with 0: a
     * counter only increments a field that exists, and clicks on a document without it are
     * accepted and silently dropped.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     * @throws SyncException when the live values cannot be read.
     */
    private function withCounters(string $collection, string $alias, array $documents): array
    {
        $fields = $this->counterFields($collection);

        if ($fields === []) {
            return $documents;
        }

        $ids = array_map(static fn(array $document) => (string)$document['id'], $documents);

        try {
            $existing = $this->fetchCounters($alias, $ids, $fields);
        } catch (SyncException $e) {
            throw new SyncException(sprintf(
                'Did not write %s to "%s": its counter fields could not be read, and writing would reset them. %s',
                $this->describeIds($documents),
                $alias,
                $e->getMessage(),
            ), 0, $e);
        }

        foreach ($documents as $index => $document) {
            foreach ($fields as $field) {
                $documents[$index][$field] = $existing[(string)$document['id']][$field] ?? 0;
            }
        }

        return $documents;
    }

    private function entryQuery(ResolvedTarget $target, SourceConfig $source): ElementQueryInterface
    {
        $query = Entry::find()->section($source->handle);

        if ($target->entryType !== null) {
            return $query->type($target->entryType);
        }

        if ($source->entryTypes !== []) {
            return $query->type(array_merge(['not'], array_map('strval', array_keys($source->entryTypes))));
        }

        return $query;
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private function afterSync(?ElementInterface $element, ResolvedTarget $target, string $outcome, ?array $document): void
    {
        if ($element === null || !$this->hasEventHandlers(self::EVENT_AFTER_SYNC)) {
            return;
        }

        $this->trigger(self::EVENT_AFTER_SYNC, new SyncEvent([
            'element' => $element,
            'target' => $target,
            'outcome' => $outcome,
            'document' => $document,
        ]));
    }

    /**
     * @throws SyncException when the plugin has no connection.
     */
    private function client(): TypesenseClient
    {
        return TypesenseSync::getInstance()->client->getClient()
            ?? throw new SyncException('Typesense is not configured: set a host and an admin API key.');
    }

    /**
     * @throws SyncException for an undeclared collection, or a name that resolves to nothing.
     */
    private function collectionName(string $collection): string
    {
        $config = $this->settings()->getCollectionConfig($collection)
            ?? throw new SyncException(sprintf('No collection "%s" is declared in config/typesense-sync.php.', $collection));

        try {
            return $config->getName();
        } catch (Throwable $e) {
            throw new SyncException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Log a failed call and wrap it, so the queue job that made it fails and is retried.
     */
    private function failure(string $what, Throwable $e): SyncException
    {
        $message = sprintf('%s: %s', $what, $e->getMessage());
        Craft::error($message, TypesenseSync::HANDLE);

        return new SyncException($message, 0, $e);
    }

    /**
     * @param string[] $ids
     */
    private function idFilter(array $ids): string
    {
        // Backticks, so an id containing a comma or bracket is one value.
        return 'id:[' . implode(',', array_map(static fn(string $id) => '`' . $id . '`', $ids)) . ']';
    }

    /**
     * "document 12" or "documents 12, 13, … (40 in all)", for log lines (TN-1).
     *
     * @param array<int, array<string, mixed>> $documents
     */
    private function describeIds(array $documents): string
    {
        $ids = array_map(static fn(array $document) => (string)($document['id'] ?? '?'), $documents);

        if (count($ids) === 1) {
            return 'document ' . $ids[0];
        }

        $shown = implode(', ', array_slice($ids, 0, self::LOGGED_IDS));

        return count($ids) > self::LOGGED_IDS
            ? sprintf('documents %s, … (%d in all)', $shown, count($ids))
            : 'documents ' . $shown;
    }

    private function describe(ElementInterface $element): string
    {
        $title = trim((string)$element);

        return $title !== '' ? $title : '#' . $element->id;
    }

    private function pendingKey(int $elementId, int $siteId): string
    {
        return sprintf('%s:pending:%d:%d', TypesenseSync::HANDLE, $elementId, $siteId);
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private static function jsonLines(string $jsonl): iterable
    {
        foreach (preg_split('/\R/', trim($jsonl)) ?: [] as $line) {
            $document = json_decode($line, true);

            if (is_array($document)) {
                yield $document;
            }
        }
    }

    private function targets(): Targets
    {
        return TypesenseSync::getInstance()->targets;
    }

    private function settings(): Settings
    {
        return $this->targets()->getSettings();
    }
}
