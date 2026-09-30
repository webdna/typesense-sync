<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\events\IndexDocumentEvent;
use webdna\typesensesync\jobs\DeleteElement;
use webdna\typesensesync\jobs\Reindex;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\RefusingFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\base\Event;

/**
 * Sync against the real server, through real element saves and the real queue: the content
 * lifecycle (TS-3), reindex and prune (TS-6), and TN-1, TN-2, TN-6, TN-7 and TN-13.
 *
 * Each test declares its own sections, and writes into its own collection prefix, which is
 * deleted afterwards.
 */
class SyncTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    /**
     * @var array<string, Section>
     */
    private array $sections = [];

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();

        $this->sections['news'] = $this->createSection('news');
        $this->sections['events'] = $this->createSection('events');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $applied = $this->plugin->collections->apply('content');
        $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);
    }

    protected function _after(): void
    {
        Event::off(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT);

        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    // TS-3 - content lifecycle (BR-6, BR-8, BR-9) -----------------------------------------------

    public function testTheDocumentExistsExactlyWhenTheEntryIsLive(): void
    {
        $entry = $this->saveEntry('news', 'Launch day');
        $this->assertSame(1, $this->countJobs(SyncElement::class));
        $this->runQueue();
        $this->assertSame('Launch day', $this->document($entry)['title'] ?? null);
        $url = (string)($this->document($entry)['url'] ?? '');
        $this->assertStringStartsWith('/', $url, 'root-relative (BR-11)');
        $this->assertStringNotContainsString('://', $url);
        $this->assertStringContainsString('news/launch-day', $url);

        $entry->enabled = false;
        $this->save($entry);
        $this->runQueue();
        $this->assertNull($this->document($entry), 'disabled');

        $entry->enabled = true;
        $this->save($entry);
        $this->runQueue();
        $this->assertNotNull($this->document($entry), 'enabled again');

        Craft::$app->getElements()->deleteElement($entry);
        $this->assertSame(1, $this->countJobs(DeleteElement::class));
        $this->runQueue();
        $this->assertNull($this->document($entry), 'deleted');

        Craft::$app->getElements()->restoreElement($entry);
        $this->runQueue();
        $this->assertNotNull($this->document($entry), 'restored');
        $this->assertSame(0, $this->countFailedJobs());
    }

    public function testFiveQuickSavesQueueOneJob(): void
    {
        $entry = $this->saveEntry('news', 'Busy');

        for ($save = 0; $save < 4; $save++) {
            $entry->title = 'Busy ' . $save;
            $this->save($entry);
        }

        $this->assertSame(1, $this->countJobs(SyncElement::class));
        $this->runQueue();
        $this->assertSame('Busy 3', $this->document($entry)['title'] ?? null, 'the one job syncs the latest content');
    }

    public function testDraftsAndBulkResavesQueueNothing(): void
    {
        $entry = $this->indexedEntry('news', 'Stable');

        $draft = Craft::$app->getDrafts()->createDraft($entry);
        $draft->title = 'Draft title';
        $this->save($draft);

        $entry->resaving = true;
        $this->save($entry);

        $this->assertSame(0, $this->countJobs(SyncElement::class));
        $this->assertSame('Stable', $this->document($entry)['title'] ?? null);
    }

    public function testAnUndeclaredSectionQueuesNothing(): void
    {
        $this->useSettings($this->settings(sources: [$this->source('news')]));

        $this->saveEntry('events', 'Private');

        $this->assertSame(0, $this->countJobs(SyncElement::class));
    }

    // Negative cases ----------------------------------------------------------------------------

    public function testTheServerUnreachableDuringASaveFailsTheJobForARetry(): void
    {
        $settings = $this->settings(values: ['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]);
        $this->useSettings($settings);

        $entry = $this->saveEntry('news', 'Offline');
        $this->assertNotNull($entry->id, 'the save succeeded (TN-1)');
        $this->runQueue();

        $job = (new Query())->select(['attempt', 'fail'])->from(Table::QUEUE)->where(['description' => $this->description($entry)])->one();
        $this->assertNotNull($job);
        $this->assertSame(1, (int)$job['attempt']);
        $this->assertSame(0, (int)$job['fail'], 'waiting for a retry, not given up');
    }

    public function testAnEntryDeletedBeforeItsJobRunsLosesItsDocument(): void
    {
        $entry = $this->indexedEntry('news', 'Short-lived');
        $entry->title = 'Short-lived, edited';
        $this->save($entry);

        // Gone without an event, so only the pending sync job can notice (TN-2).
        Db::delete(Table::ELEMENTS, ['id' => $entry->id]);
        $this->runQueue();

        $this->assertNull($this->document($entry));
        $this->assertSame(0, $this->countFailedJobs());
    }

    public function testAProvisionalDraftDeletedOnSaveQueuesNoDelete(): void
    {
        $entry = $this->indexedEntry('news', 'Edited in the CP');

        $draft = Craft::$app->getDrafts()->createDraft($entry, null, null, null, [], true);
        Craft::$app->getElements()->deleteElement($draft, true);

        $this->assertSame(0, $this->countJobs(DeleteElement::class));
        $this->assertNotNull($this->document($entry), 'TN-6');
    }

    public function testARejectedLineKeepsTheOthers(): void
    {
        $result = $this->plugin->sync->import('content', [
            $this->rawDocument('good-1'),
            ['priority' => 'not a number'] + $this->rawDocument('bad'),
            $this->rawDocument('good-2'),
        ]);

        $this->assertSame(2, $result['written']);
        $this->assertSame(['bad'], array_keys($result['rejected']));
        $this->assertNotNull($this->documentById('good-1'));
        $this->assertNotNull($this->documentById('good-2'));
        $this->assertNull($this->documentById('bad'));
    }

    public function testASaveDuringItsOwnSyncQueuesAnother(): void
    {
        $entry = $this->saveEntry('news', 'Racing');
        $requeued = null;

        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, function(IndexDocumentEvent $event) use (&$requeued) {
            if ($requeued === null) {
                // An editor saves while the job is building the document (TN-13).
                $requeued = $this->plugin->sync->queueElement($event->element);
            }
        });

        $this->runQueue();

        $this->assertTrue($requeued, 'the job had already cleared its flag');
        $this->assertSame(0, $this->countJobs(SyncElement::class), 'and the second job ran too');
    }

    // TS-6 - reindex and prune (BR-12, BR-13, BR-14) --------------------------------------------

    public function testReindexKeepsCountersAndPruneRemovesOnlyWhatIsNoLongerDeclared(): void
    {
        $this->useSettings($this->settings(counters: ['popularity']));
        $news = $this->saveEntry('news', 'Kept');
        $event = $this->saveEntry('events', 'Dropped');
        $this->clearQueue();

        $run = $this->plugin->sync->reindexAndPrune('content');
        $this->assertSame(['indexed' => 2, 'rejected' => 0, 'failed' => 0, 'pruned' => 0], $run);
        $this->admin->collections[$this->prefix . 'content']->documents[(string)$news->id]->update(['popularity' => 5]);

        // Step 2: the events section leaves the config.
        $this->useSettings($this->settings(counters: ['popularity'], sources: [$this->source('news')]));
        $run = $this->plugin->sync->reindexAndPrune('content');

        $this->assertSame(1, $run['pruned']);
        $this->assertNull($this->document($event));
        $this->assertSame(5, $this->document($news)['popularity'] ?? null, 'step 1: the counter survived');
    }

    public function testAReindexWritesInBatchesOfTheBatchSize(): void
    {
        $this->useSettings($this->settings(sources: [$this->source('news')], values: ['batchSize' => 2]));
        foreach (range(1, 5) as $n) {
            $this->saveEntry('news', 'Batch ' . $n);
        }
        $this->clearQueue();

        // Progress is reported once per batch written (BR-12).
        $progress = [];
        $run = $this->plugin->sync->reindex('content', null, function(int $indexed) use (&$progress) {
            $progress[] = $indexed;
        });

        $this->assertSame(5, $run['indexed']);
        $this->assertSame([2, 4, 5], $progress);
        $this->assertSame(5, $this->countDocuments());
    }

    public function testAnEmptyRunDoesNotPrune(): void
    {
        $this->indexedEntry('news', 'Survivor');

        $this->useSettings($this->settings(sources: [$this->source('news', RefusingFormatter::class)]));
        $run = $this->plugin->sync->reindexAndPrune('content');

        $this->assertNull($run['pruned']);
        $this->assertSame(1, $this->countDocuments(), 'step 3: collection unchanged');
    }

    public function testTheReindexJobPrunesWhenAsked(): void
    {
        $this->indexedEntry('news', 'Real');
        $this->plugin->sync->import('content', [$this->rawDocument('orphan')]);

        Craft::$app->getQueue()->push(new Reindex(['collection' => 'content', 'prune' => true]));
        $this->runQueue();

        $this->assertNull($this->documentById('orphan'));
        $this->assertSame(1, $this->countDocuments());
        $this->assertSame(0, $this->countFailedJobs());
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * @param list<string> $counters
     * @param list<array<string, mixed>>|null $sources
     * @param array<string, mixed> $values
     */
    private function settings(array $counters = [], ?array $sources = null, array $values = []): Settings
    {
        return new Settings($values + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'batchSize' => 1,
            // The one field the formatter does not declare: the counter.
            'collections' => ['content' => [
                'schema' => [['name' => 'popularity', 'type' => 'int32', 'optional' => true]],
                'counters' => $counters,
            ]],
            'sources' => $sources ?? [$this->source('news'), $this->source('events')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $section, string $formatter = DocumentFormatter::class): array
    {
        return ['handle' => $this->sections[$section]->handle, 'collection' => 'content', 'formatter' => $formatter];
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }

    private function createSection(string $name): Section
    {
        $handle = $name . substr($this->prefix, 2, 8);
        $entryType = new EntryType(['name' => ucfirst($name), 'handle' => $handle . 'Type']);
        // Without a title field in its layout, an entry's title is not saved.
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new EntryTitleField()]])]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), implode(' ', $entryType->getFirstErrors()));

        $section = new Section([
            'name' => ucfirst($name),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings([
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $name . '/{slug}',
                'template' => '_entry',
            ])],
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(Craft::$app->getEntries()->saveSection($section), implode(' ', $section->getFirstErrors()));

        return $section;
    }

    private function saveEntry(string $section, string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->sections[$section]->id,
            'typeId' => $this->sections[$section]->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->save($entry);

        return $entry;
    }

    /**
     * A saved entry whose document is in the collection, with the queue empty.
     */
    private function indexedEntry(string $section, string $title): Entry
    {
        $entry = $this->saveEntry($section, $title);
        $this->runQueue();
        $this->assertNotNull($this->document($entry));

        return $entry;
    }

    private function save(Entry $entry): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawDocument(string $id): array
    {
        return ['id' => $id, 'title' => 'Raw ' . $id, 'type' => 'Raw', 'priority' => 1, 'postDate' => 0, 'expiryDate' => 0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(Entry $entry): ?array
    {
        return $this->documentById((string)$entry->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentById(string $id): ?array
    {
        try {
            return $this->admin->collections[$this->prefix . 'content']->documents[$id]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        }
    }

    private function countDocuments(): int
    {
        return (int)$this->admin->collections[$this->prefix . 'content_1']->retrieve()['num_documents'];
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
    }

    private function clearQueue(): void
    {
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    private function description(Entry $entry): string
    {
        return Craft::t('typesense-sync', 'Syncing {element} to Typesense', ['element' => $entry->title]);
    }

    /**
     * Jobs of a class waiting in the queue, failed ones excluded.
     */
    private function countJobs(string $class): int
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->column();

        return count(array_filter(
            $rows,
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job) instanceof $class,
        ));
    }

    private function countFailedJobs(): int
    {
        return (int)(new Query())->from(Table::QUEUE)->where(['fail' => true])->count();
    }
}
