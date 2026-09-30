<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use RuntimeException;
use Typesense\Client as TypesenseClient;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\events\IndexDocumentEvent;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\JoiningFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\base\Event;

/**
 * Recreate against the real server and real entries (BR-15): search answers throughout, a save
 * made mid-build reaches the new version (TS-4 steps 2–4), a join survives the joined-to
 * collection being rebuilt (TS-7 steps 1–2), and a failed build — of the collection or of a
 * dependant — changes nothing (TN-12).
 *
 * "While a loop searches the alias" is the progress callback: it runs inside the build, between
 * the reindex writing to the new version and the alias moving.
 */
class RecreateTest extends Unit
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

        foreach (['people', 'posts'] as $name) {
            $this->sections[$name] = $this->createSection($name);
        }

        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);

        foreach (['people', 'posts'] as $collection) {
            $applied = $this->plugin->collections->apply($collection);
            $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);
        }
    }

    protected function _after(): void
    {
        Event::off(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT);
        JoiningFormatter::$authorId = '';

        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    // TS-4 steps 2–4 ----------------------------------------------------------------------------

    public function testSearchAnswersThroughoutAndAMidBuildSaveReachesTheNewVersion(): void
    {
        $entries = [];
        foreach (['Ada', 'Grace', 'Edsger'] as $title) {
            $entries[] = $this->indexedEntry('people', $title);
        }

        // A change only a recreate can make.
        $this->useSettings($this->settings(['defaultSortingField' => 'priority']));
        $this->assertSame(Collections::ACTION_NEEDS_RECREATE, $this->plugin->collections->apply('people')['action']);

        $searches = [];
        $edited = false;

        $result = $this->plugin->collections->recreate('people', function(string $collection, int $indexed) use (&$searches, &$edited, $entries): void {
            $searches[] = $this->search('people')['found'];

            // An editor saves mid-build, and its queued sync runs, writing to the old version.
            if (!$edited) {
                $edited = true;
                $entries[0]->title = 'Ada Lovelace';
                $this->save($entries[0]);
                $this->runQueue();
            }
        });

        $this->assertNotEmpty($searches, 'the build was observed');
        $this->assertSame(array_fill(0, count($searches), 3), $searches, 'every search during the build answered in full');

        $this->assertSame(['people', 'posts'], array_column($result, 'collection'), 'posts joins into people');
        $this->assertSame($this->prefix . 'people_1', $result[0]['from']);
        $this->assertSame($this->prefix . 'people_2', $result[0]['to']);
        $this->assertSame(3, $result[0]['indexed']);
        $this->assertGreaterThanOrEqual(1, $result[0]['resynced']);
        $this->assertSame($this->prefix . 'people_1', $result[0]['dropped']);

        $this->assertSame('Ada Lovelace', $this->document('people', $entries[0])['title'] ?? null, 'no lost update (BR-15)');
        $this->assertSame(3, $this->search('people')['found']);
        $this->assertSame([$this->prefix . 'people_2'], $this->physical('people'), 'only the new version is left');
        $this->assertSame($this->prefix . 'people_2', $this->aliasTarget('people'));
        $this->assertTrue($this->plugin->collections->diff('people')['upToDate']);
    }

    // TN-12 -------------------------------------------------------------------------------------

    public function testAFailedBuildDeletesTheNewVersionAndLeavesTheAliasAlone(): void
    {
        $this->indexedEntry('people', 'Ada');
        $this->indexedEntry('people', 'Grace');

        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, static function(IndexDocumentEvent $event): void {
            throw new RuntimeException('formatter blew up');
        });

        try {
            $this->plugin->collections->recreate('people');
            $this->fail('the failed build was swapped in');
        } catch (SyncException $e) {
            $this->assertStringContainsString('formatter blew up', $e->getMessage(), 'the error is shown');
            $this->assertStringContainsString($this->prefix . 'people_2 was deleted', $e->getMessage());
        }

        $this->assertSame([$this->prefix . 'people_1'], $this->physical('people'), 'the new version is deleted');
        $this->assertSame($this->prefix . 'people_1', $this->aliasTarget('people'), 'the alias is unchanged');
        $this->assertSame(2, $this->search('people')['found']);
        $this->assertSame($this->prefix . 'posts_1', $this->aliasTarget('posts'), 'no dependant was touched');
    }

    public function testABuildThatMakesNothingIsNotSwappedOverALiveVersion(): void
    {
        $this->indexedEntry('people', 'Ada');

        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, static function(IndexDocumentEvent $event): void {
            $event->isValid = false;
        });

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/made no documents/');

        try {
            $this->plugin->collections->recreate('people');
        } finally {
            $this->assertSame($this->prefix . 'people_1', $this->aliasTarget('people'));
            $this->assertSame(1, $this->search('people')['found']);
        }
    }

    // TS-7 steps 1–2 ----------------------------------------------------------------------------

    public function testAJoinKeepsAnsweringWhileAndAfterTheJoinedCollectionIsRecreated(): void
    {
        $author = $this->indexedEntry('people', 'Ada');
        JoiningFormatter::$authorId = (string)$author->id;
        $this->indexedEntry('posts', 'On engines');

        $this->assertSame('Ada', $this->joinedAuthor(), 'step 1: the join works');

        $order = [];
        $joins = [];

        $result = $this->plugin->collections->recreate('people', function(string $collection) use (&$order, &$joins): void {
            $order[] = $collection;
            $joins[] = $this->joinedAuthor();
        });

        $this->assertSame(['people', 'posts'], array_values(array_unique($order)), 'posts rebuilt after people');
        $this->assertSame(array_fill(0, count($joins), 'Ada'), $joins, 'the join answered during both builds');
        $this->assertSame(['people', 'posts'], array_column($result, 'collection'));
        $this->assertSame('Ada', $this->joinedAuthor(), 'step 2: the join works after the run');
        $this->assertSame([$this->prefix . 'people_2'], $this->physical('people'));
        $this->assertSame([$this->prefix . 'posts_2'], $this->physical('posts'));
        $this->assertTrue($this->plugin->collections->diff('posts')['upToDate'], 'a reference to the new version reads as the declared alias');
    }

    public function testADependantThatFailsToBuildLeavesEverythingAsItWas(): void
    {
        $author = $this->indexedEntry('people', 'Ada');
        JoiningFormatter::$authorId = (string)$author->id;
        $this->indexedEntry('posts', 'On engines');

        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, static function(IndexDocumentEvent $event): void {
            if ($event->target->collection === 'posts') {
                throw new RuntimeException('posts formatter blew up');
            }
        });

        try {
            $this->plugin->collections->recreate('people');
            $this->fail('a failed dependant went unreported');
        } catch (SyncException $e) {
            $this->assertStringContainsString('Nothing was recreated', $e->getMessage());
            $this->assertStringContainsString('posts formatter blew up', $e->getMessage());
        }

        Event::off(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT);

        $this->assertSame([$this->prefix . 'people_1'], $this->physical('people'), 'the people version already built is deleted too');
        $this->assertSame([$this->prefix . 'posts_1'], $this->physical('posts'));
        $this->assertSame($this->prefix . 'people_1', $this->aliasTarget('people'));
        $this->assertSame('Ada', $this->joinedAuthor());

        // Once the problem is gone, the same command finishes the job.
        $this->plugin->collections->recreate('people');
        $this->assertSame([$this->prefix . 'people_2'], $this->physical('people'));
        $this->assertSame([$this->prefix . 'posts_2'], $this->physical('posts'));
        $this->assertSame('Ada', $this->joinedAuthor());
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $people
     */
    private function settings(array $people = []): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['people' => $people, 'posts' => []],
            'sources' => [
                ['handle' => $this->sections['people']->handle, 'collection' => 'people', 'formatter' => DocumentFormatter::class],
                ['handle' => $this->sections['posts']->handle, 'collection' => 'posts', 'formatter' => JoiningFormatter::class],
            ],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function search(string $collection, array $params = []): array
    {
        return $this->admin->collections[$this->prefix . $collection]->documents->search($params + ['q' => '*', 'query_by' => 'title']);
    }

    /**
     * The author title the first post's join brings back, or null.
     */
    private function joinedAuthor(): ?string
    {
        $people = $this->prefix . 'people';
        $hit = $this->search('posts', ['include_fields' => sprintf('title,$%s(title)', $people)])['hits'][0]['document'] ?? [];

        return $hit[$people]['title'] ?? null;
    }

    /**
     * The versions of a collection on the server, sorted.
     *
     * @return string[]
     */
    private function physical(string $collection): array
    {
        $names = array_values(array_filter(
            array_map(static fn($c) => (string)$c['name'], (array)$this->admin->collections->retrieve()),
            fn(string $name) => preg_match('/^' . preg_quote($this->prefix . $collection, '/') . '_\d+$/', $name) === 1,
        ));
        sort($names);

        return $names;
    }

    private function aliasTarget(string $collection): string
    {
        return (string)$this->admin->aliases[$this->prefix . $collection]->retrieve()['collection_name'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(string $collection, Entry $entry): ?array
    {
        $hits = $this->search($collection, ['filter_by' => 'id:=' . $entry->id])['hits'] ?? [];

        return $hits[0]['document'] ?? null;
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

    /**
     * A saved entry whose document is in its collection, with the queue empty.
     */
    private function indexedEntry(string $section, string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->sections[$section]->id,
            'typeId' => $this->sections[$section]->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->save($entry);
        $this->runQueue();
        $this->assertNotNull($this->document($section, $entry), sprintf('"%s" was indexed', $title));

        return $entry;
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
    }

    private function save(Entry $entry): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));
    }
}
