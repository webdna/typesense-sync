<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Stub;
use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
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
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\jobs\Reindex;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NotAFormatter;
use webdna\typesensesync\tests\fixtures\formatters\RefusingFormatter;
use webdna\typesensesync\tests\Support\Examples;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\console\ExitCode;

/**
 * The console commands, run through the plugin's real console controllers against the real
 * server: setup from nothing (TS-2), reindex and prune (TS-6), and every refusal exiting
 * non-zero with nothing changed (BR-4, BR-19, BR-22, BR-24).
 *
 * Output is captured rather than matched line by line, as Craft's CommandTest would: the
 * commands' wording is theirs to change, their exit codes and effects are the contract.
 */
class ConsoleTest extends Unit
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
        // The test container runs as root, and Craft's console controllers refuse root (exit 0,
        // a message, the action never runs) unless this is set.
        putenv('CRAFT_ALLOW_SUPERUSER=1');

        foreach (['news', 'events', 'private'] as $name) {
            $this->sections[$name] = $this->createSection($name);
        }

        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $this->clearQueue();
    }

    protected function _after(): void
    {
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
        putenv('CRAFT_ALLOW_SUPERUSER');
    }

    // TS-2 - declare and set up (BR-1, BR-3) ----------------------------------------------------

    /**
     * Run on `examples/config/typesense-sync.php` as a developer copies it, with only its section
     * handle renamed to this run's, and the example ContentFormatter (task 8.1).
     */
    public function testSetupOnTheBaseExampleIndexesOnlyDeclaredContent(): void
    {
        $this->useSettings($this->exampleSettings(Examples::config('typesense-sync')));
        $news = $this->saveEntry('news', 'Harbour opens');
        $private = $this->saveEntry('private', 'Board minutes');

        $run = $this->command('setup');

        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('Created ' . $this->prefix . 'content_1', $run['out'], 'step 1: reports the collection created');
        $this->assertStringContainsString('1 documents indexed', $run['out'], 'step 1: and N documents');

        $hits = $this->search('Harbour opens');
        $this->assertSame([(string)$news->id], array_column($hits, 'id'), 'step 2: the news entry is found');
        $this->assertStringStartsWith('/', (string)$hits[0]['url'], 'step 2: with a root-relative URL');
        $this->assertSame('News', $hits[0]['section'] ?? null, 'step 2: built by the example formatter');
        $this->assertSame([], $this->search('Board minutes'), 'step 3: the private entry is not');
        $this->assertSame([], array_filter($this->jobs(SyncElement::class), fn(SyncElement $job) => $job->elementId === $private->id), 'step 3: and no job was queued for it');
        $this->assertSame($this->prefix . 'content_1', $this->plugin->collections->getActiveCollectionName('content'), 'the live name is an alias (BR-3)');
    }

    /**
     * `examples/config/joins.php`: setup creates both collections in the order declared, a
     * search of content returns its author's name through the join, the reference reads back as
     * declared, and taking the author out of search leaves what they wrote searchable.
     */
    public function testSetupOnTheJoinsExampleJoinsContentToItsAuthor(): void
    {
        $this->useSettings($this->exampleSettings(Examples::config('joins')));
        $author = new User(['username' => 'author' . $this->prefix, 'email' => 'author' . $this->prefix . '@example.test', 'fullName' => 'Ada Harbour']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($author), implode(' ', $author->getFirstErrors()));
        Craft::$app->getUsers()->activateUser($author);
        $news = $this->saveEntry('news', 'Harbour opens', $author->id);

        $run = $this->command('setup');

        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $people = $this->prefix . 'people';
        $this->assertLessThan(strpos($run['out'], 'Created ' . $this->prefix . 'content_1'), strpos($run['out'], 'Created ' . $people . '_1'), 'people is created before content');
        $this->assertSame('Ada Harbour', $this->joinedAuthor($people), 'the join returns the author');

        foreach (['people', 'content'] as $handle) {
            $this->assertTrue($this->plugin->collections->diff($handle)['upToDate'], "$handle reads back as declared, reference options included");
        }

        Craft::$app->getUsers()->suspendUser($author);
        $this->plugin->sync->syncElement(Craft::$app->getUsers()->getUserById((int)$author->id));
        $this->assertNotNull($this->document((string)$news->id), 'cascade_delete is off: the entry stays when its author leaves search');
        $this->assertNull($this->joinedAuthor($people));
    }

    public function testSetupRunAgainChangesNothingAndSkipSyncIndexesNothing(): void
    {
        $this->saveEntry('news', 'First');

        $this->assertSame(ExitCode::OK, $this->command('setup', ['skipSync' => true])['exit']);
        $this->assertSame(0, $this->countDocuments(), '--skip-sync creates the collection empty');

        $run = $this->command('setup');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('is up to date', $run['out']);
        $this->assertSame(1, $this->countDocuments());
    }

    public function testSetupRefusesAConfigProblemAndCreatesNothing(): void
    {
        $this->useSettings($this->settings(sources: [$this->source('news', NotAFormatter::class)]));

        $run = $this->command('setup');

        $this->assertSame(ExitCode::CONFIG, $run['exit'], 'TN-3: setup exits non-zero on a config problem');
        $this->assertStringContainsString(NotAFormatter::class, $run['err']);
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'));
    }

    public function testSetupRefusesASearchKeyThatAllowsMoreThanSearch(): void
    {
        $this->useSettings($this->settings(values: ['searchApiKey' => (string)getenv('TYPESENSE_TEST_API_KEY')]));

        $run = $this->command('setup');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $run['exit'], 'BR-19');
        $this->assertStringContainsString('allows more than search', $run['err']);
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'), 'nothing created');
    }

    public function testSetupRefusesAnUnreachableServer(): void
    {
        $this->useSettings($this->settings(values: ['port' => '1']));

        $run = $this->command('setup');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $run['exit']);
        $this->assertStringContainsString('Could not connect', $run['err']);
        $this->useSettings($this->settings());
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'));
    }

    // TS-10 step 1 - Commerce absent (BR-5) -----------------------------------------------------

    public function testAProductsSourceWithoutCommerceWarnsAndSetupStillSucceeds(): void
    {
        $this->assertFalse(Commerce::isInstalled(), 'this leg runs without Commerce');
        $news = $this->saveEntry('news', 'Harbour opens');
        $this->useSettings($this->settings(sources: [
            $this->source('news'),
            ['kind' => 'productType', 'handle' => 'shoes', 'collection' => 'content', 'formatter' => DocumentFormatter::class],
        ]));

        $run = $this->command('setup');

        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('productType:shoes is ignored because Commerce is not installed.', $run['out'], 'a warning');
        $this->assertSame('', $run['err'], 'not an error');
        $this->assertSame([(string)$news->id], array_column($this->search('Harbour opens'), 'id'), 'the rest of the config still indexes');
        $this->assertNotContains(Commerce::PRODUCT_CLASS, $this->plugin->targets->getElementTypes(), 'products are not followed');
    }

    // TS-6 - reindex and prune (BR-12, BR-13, BR-14) --------------------------------------------

    public function testSyncPruneKeepsCountersAndRemovesOnlyWhatIsNoLongerDeclared(): void
    {
        $this->useSettings($this->settings(counters: ['popularity']));
        $news = $this->saveEntry('news', 'Kept');
        $event = $this->saveEntry('events', 'Dropped');
        $this->assertSame(ExitCode::OK, $this->command('setup')['exit']);
        $this->assertSame(2, $this->countDocuments());
        $this->admin->collections[$this->prefix . 'content']->documents[(string)$news->id]->update(['popularity' => 5]);

        // Step 1: a pruning reindex of unchanged config keeps the counter.
        $run = $this->command('sync', ['prune' => true]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('0 stale documents removed', $run['out']);
        $this->assertSame(5, $this->document((string)$news->id)['popularity'] ?? null, 'step 1: popularity unchanged');

        // Step 2: the events section leaves the config.
        $this->useSettings($this->settings(counters: ['popularity'], sources: [$this->source('news')]));
        $run = $this->command('sync', ['prune' => true, 'collection' => 'content']);

        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('1 stale documents removed', $run['out']);
        $this->assertNull($this->document((string)$event->id), 'step 2: its documents gone');
        $this->assertSame(5, $this->document((string)$news->id)['popularity'] ?? null);
    }

    public function testSyncPruneOnARunThatBuildsNothingSkipsThePrune(): void
    {
        $this->saveEntry('news', 'Survivor');
        $this->assertSame(ExitCode::OK, $this->command('setup')['exit']);

        $this->useSettings($this->settings(sources: [$this->source('news', RefusingFormatter::class)]));
        $run = $this->command('sync', ['prune' => true]);

        $this->assertSame(ExitCode::OK, $run['exit'], 'a skipped prune is the designed outcome, not a failure');
        $this->assertStringContainsString('Prune skipped', $run['out']);
        $this->assertSame(1, $this->countDocuments(), 'step 3: collection unchanged');
    }

    public function testSyncRefusesAnUndeclaredCollectionAndOneNotYetCreated(): void
    {
        $run = $this->command('sync', ['collection' => 'nowhere']);
        $this->assertSame(ExitCode::USAGE, $run['exit']);
        $this->assertStringContainsString('Declared: content', $run['err']);

        $run = $this->command('sync');
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $run['exit']);
        $this->assertStringContainsString('collections/apply', $run['err']);
    }

    public function testSyncQueueQueuesOneReindexPerCollection(): void
    {
        $this->assertSame(ExitCode::OK, $this->command('setup', ['skipSync' => true])['exit']);
        $this->clearQueue();

        $this->assertSame(ExitCode::OK, $this->command('sync', ['queue' => true, 'prune' => true])['exit']);

        $jobs = $this->jobs(Reindex::class);
        $this->assertCount(1, $jobs);
        $this->assertSame('content', $jobs[0]->collection);
        $this->assertTrue($jobs[0]->prune);
        $this->assertSame(0, $this->countDocuments(), 'queued, not run');
    }

    public function testSyncElementIndexesADeclaredElementAndRefusesAnUndeclaredOne(): void
    {
        $news = $this->saveEntry('news', 'One');
        $private = $this->saveEntry('private', 'Other');
        $this->assertSame(ExitCode::OK, $this->command('setup', ['skipSync' => true])['exit']);

        $this->assertSame(ExitCode::OK, $this->command('sync/element', [$news->id])['exit']);
        $this->assertNotNull($this->document((string)$news->id));

        $run = $this->command('sync/element', [$private->id]);
        $this->assertSame(ExitCode::USAGE, $run['exit']);
        $this->assertStringContainsString('not declared', $run['err']);
        $this->assertSame(1, $this->countDocuments());
    }

    // Flush (BR-22, TN-10) ----------------------------------------------------------------------

    public function testFlushNeedsTheTypedHandle(): void
    {
        $this->saveEntry('news', 'Stays');
        $this->assertSame(ExitCode::OK, $this->command('setup')['exit']);

        foreach (['', 'Content', 'content ', 'events'] as $typed) {
            $run = $this->command('sync/flush', ['content'], [$typed]);
            $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $run['exit'], "typed “{$typed}”");
            $this->assertSame(1, $this->countDocuments(), "nothing deleted for “{$typed}”");
        }

        // Non-interactive with no --confirm: the prompt answers its default, which refuses.
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $this->command('sync/flush', ['content', 'interactive' => false])['exit']);
        $this->assertSame(1, $this->countDocuments());

        $run = $this->command('sync/flush', ['content'], ['content']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame(0, $this->countDocuments());
        $this->assertSame($this->prefix . 'content_1', $this->plugin->collections->getActiveCollectionName('content'), 'the collection stays');

        $this->plugin->sync->reindex('content');
        $this->assertSame(ExitCode::OK, $this->command('sync/flush', ['content', 'confirm' => 'content'], [])['exit'], '--confirm answers without a prompt');
        $this->assertSame(0, $this->countDocuments());
    }

    // Collections -------------------------------------------------------------------------------

    public function testStatusApplyDryRunAndApply(): void
    {
        $run = $this->command('collections');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('not created yet', $run['out']);

        $run = $this->command('collections/apply', ['dryRun' => true]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('Would create', $run['out']);
        $this->assertStringContainsString('"fields"', $run['out'], 'the payload is printed');
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'), '--dry-run changes nothing');

        $this->assertSame(ExitCode::OK, $this->command('collections/apply', ['collection' => 'content'])['exit']);
        $this->assertStringContainsString('up to date', $this->command('collections/status')['out']);

        // A wider schema reads as out of date, and apply alters it and advises a reindex.
        $this->useSettings($this->settings(values: ['collections' => ['content' => [
            'schema' => [
                ['name' => 'popularity', 'type' => 'int32', 'optional' => true],
                ['name' => 'rating', 'type' => 'float', 'optional' => true],
            ],
        ]]]));
        $this->assertStringContainsString('+ rating', $this->command('collections/status')['out']);
        $run = $this->command('collections/apply');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('typesense-sync/sync --collection=content', $run['out']);
    }

    public function testApplyRefusesAConfigProblemAndAnUndeclaredCollection(): void
    {
        $this->assertSame(ExitCode::USAGE, $this->command('collections/apply', ['collection' => 'nowhere'])['exit']);

        $this->useSettings($this->settings(sources: [$this->source('news', NotAFormatter::class)]));
        $this->assertSame(ExitCode::CONFIG, $this->command('collections/apply')['exit']);
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'));
    }

    public function testRecreateNeedsTheCollectionAndTheTypedHandle(): void
    {
        $this->saveEntry('news', 'Rebuilt');
        $this->assertSame(ExitCode::OK, $this->command('setup')['exit']);

        $this->assertSame(ExitCode::USAGE, $this->command('collections/recreate')['exit'], '--collection is required');
        $this->assertSame(ExitCode::USAGE, $this->command('collections/recreate', ['collection' => 'nowhere'])['exit']);

        $run = $this->command('collections/recreate', ['collection' => 'content'], ['contents']);
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $run['exit'], 'TN-10');
        $this->assertStringContainsString('Nothing was changed', $run['err']);
        $this->assertSame($this->prefix . 'content_1', $this->plugin->collections->getActiveCollectionName('content'), 'alias unmoved');

        $run = $this->command('collections/recreate', ['collection' => 'content'], ['content']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('content_1 → ' . $this->prefix . 'content_2', $run['out']);
        $this->assertSame($this->prefix . 'content_2', $this->plugin->collections->getActiveCollectionName('content'));
        $this->assertSame(1, $this->countDocuments('content_2'));
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * Run a command through the plugin's console controller, capturing its output and answering
     * its prompts in order (an empty string once they run out).
     *
     * @param array<int|string, mixed> $params Positional arguments and options by name.
     * @param list<string> $answers
     * @return array{exit: int, out: string, err: string}
     */
    private function command(string $route, array $params = [], array $answers = []): array
    {
        $created = $this->plugin->createController($route);
        $this->assertIsArray($created, "no command $route");
        [$controller, $action] = $created;
        $this->assertInstanceOf(Controller::class, $controller);

        $out = '';
        $err = '';
        $interactive = $params['interactive'] ?? true;
        unset($params['interactive']);
        $overrides = [
            'stdout' => function(string $string) use (&$out): int {
                $out .= $string;

                return strlen($string);
            },
            'stderr' => function(string $string) use (&$err): int {
                $err .= $string;

                return strlen($string);
            },
        ];

        // Non-interactive, the real prompt runs and returns its default without asking.
        if ($interactive) {
            $overrides['prompt'] = function() use (&$answers): string {
                return array_shift($answers) ?? '';
            };
        }

        /** @var Controller $stub */
        $stub = Stub::construct($controller::class, [$controller->id, $this->plugin], $overrides);
        $stub->interactive = (bool)$interactive;
        $exit = $stub->runAction($action, $params);

        return ['exit' => (int)$exit, 'out' => $out, 'err' => $err];
    }

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

    /**
     * An example config file's array with this run's connection and prefix, and its section
     * handles renamed to this run's sections.
     *
     * @param array<string, mixed> $config
     */
    private function exampleSettings(array $config): Settings
    {
        $handles = array_map(fn(Section $section) => (string)$section->handle, $this->sections);

        return new Settings(Examples::withHandles($config, $handles) + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
        ]);
    }

    /**
     * The author's name as a joined search of content returns it, or null.
     */
    private function joinedAuthor(string $people): ?string
    {
        $result = $this->admin->collections[$this->prefix . 'content']->documents->search([
            'q' => '*',
            'include_fields' => sprintf('title,$%s(title)', $people),
        ]);

        return $result['hits'][0]['document'][$people]['title'] ?? null;
    }

    private function saveEntry(string $section, string $title, ?int $authorId = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->sections[$section]->id,
            'typeId' => $this->sections[$section]->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        if ($authorId !== null) {
            $entry->setAuthorId($authorId);
        }
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }

    /**
     * @return list<array<string, mixed>> The documents hit.
     */
    private function search(string $query): array
    {
        $result = $this->admin->collections[$this->prefix . 'content']->documents->search([
            'q' => $query,
            'query_by' => 'title',
            'prefix' => 'false',
            'num_typos' => 0,
        ]);

        return array_values(array_map(fn(array $hit) => $hit['document'], (array)($result['hits'] ?? [])));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(string $id): ?array
    {
        try {
            return $this->admin->collections[$this->prefix . 'content']->documents[$id]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        }
    }

    private function countDocuments(string $version = 'content_1'): int
    {
        return (int)$this->admin->collections[$this->prefix . $version]->retrieve()['num_documents'];
    }

    private function clearQueue(): void
    {
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function jobs(string $class): array
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->column();
        $jobs = array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );

        return array_values(array_filter($jobs, fn($job) => $job instanceof $class));
    }
}
