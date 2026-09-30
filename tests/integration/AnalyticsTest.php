<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Stub;
use Codeception\Test\Unit;
use Craft;
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
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\console\ExitCode;

/**
 * TS-8 against the real server, which the test container starts with analytics on and a
 * one-second flush: rules applied once and idempotently, popular and no-hits searches reported,
 * view events counted with the events-only key and surviving a reindex, and `remove` touching
 * only the declared rules (BR-14, BR-21).
 */
class AnalyticsTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private Section $section;

    /**
     * @var list<int>
     */
    private array $keyIds = [];

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        putenv('CRAFT_ALLOW_SUPERUSER=1');

        $this->section = $this->createSection();
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    protected function _after(): void
    {
        foreach ($this->admin->analytics->rules()->retrieve() as $rule) {
            if (str_starts_with((string)($rule['name'] ?? ''), $this->prefix)) {
                $this->admin->analytics->rules()[(string)$rule['name']]->delete();
            }
        }

        foreach ($this->keyIds as $id) {
            $this->admin->keys[$id]->delete();
        }

        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
        putenv('CRAFT_ALLOW_SUPERUSER');
    }

    // TS-8 step 1 - apply, twice -------------------------------------------------------------------

    public function testRulesAreCreatedOnceAndASecondApplyChangesNothing(): void
    {
        $this->assertSame(ExitCode::OK, $this->command('collections/apply')['exit']);

        $run = $this->command('analytics/apply');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('Created collection ' . $this->prefix . 'content_popular.', $run['out']);
        $this->assertStringContainsString('Created ' . $this->prefix . 'content_views.', $run['out']);
        $this->assertSame(
            [$this->prefix . 'content_noHits', $this->prefix . 'content_popular', $this->prefix . 'content_views'],
            $this->ruleNames(),
        );

        $again = $this->command('analytics/apply');
        $this->assertSame(ExitCode::OK, $again['exit'], $again['err']);
        $this->assertSame(3, substr_count($again['out'], 'is up to date'), 'step 1: "no changes" on the second run');
        $this->assertStringNotContainsString('Created', $again['out']);

        $status = $this->command('analytics/status');
        $this->assertSame(ExitCode::OK, $status['exit'], $status['err']);
        $this->assertSame(3, substr_count($status['out'], 'up to date'));
    }

    public function testAChangedRuleIsReplacedAndADryRunSendsNothing(): void
    {
        $this->command('collections/apply');
        $this->command('analytics/apply');

        $this->useSettings($this->settings(limit: 50));
        $dry = $this->command('analytics/apply', ['dryRun' => true]);
        $this->assertSame(ExitCode::OK, $dry['exit'], $dry['err']);
        $this->assertStringContainsString('Would replace ' . $this->prefix . 'content_popular', $dry['out']);
        $this->assertSame(1000, $this->liveRule('popular')['params']['limit'] ?? null, 'the dry run changed nothing');

        $run = $this->command('analytics/apply');
        $this->assertStringContainsString('Replaced ' . $this->prefix . 'content_popular.', $run['out']);
        $this->assertSame(50, $this->liveRule('popular')['params']['limit'] ?? null);
    }

    public function testACounterIsBlockedUntilItsCollectionExistsAndSetupAppliesInOrder(): void
    {
        $blocked = $this->command('analytics/apply');
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $blocked['exit']);
        $this->assertStringContainsString('does not exist yet', $blocked['err']);
        $this->assertSame([], $this->ruleNames(), 'nothing sent while blocked');

        $setup = $this->command('setup');
        $this->assertSame(ExitCode::OK, $setup['exit'], $setup['err']);
        $this->assertStringContainsString('Analytics', $setup['out']);
        $this->assertStringContainsString('No analytics events key is set', $setup['out']);
        $this->assertCount(3, $this->ruleNames(), 'setup creates the collection, then the rules');
        $this->assertContains('views', array_column($this->admin->collections[$this->prefix . 'content']->retrieve()['fields'], 'name'), 'the counter field is in the schema');
    }

    // TS-8 step 2 - popular and no-hits searches ----------------------------------------------------

    public function testTheReportListsPopularSearchesAndSearchesWithNoResults(): void
    {
        $this->saveEntry('Harbour opens');
        $this->command('setup');

        foreach (['harbour', 'harbour', 'zzqxv'] as $query) {
            $this->admin->collections[$this->prefix . 'content']->documents->search(['q' => $query, 'query_by' => 'title']);
        }

        $this->waitFor(fn() => $this->plugin->analytics->report('popular') !== [] && $this->plugin->analytics->report('noHits') !== []);

        $this->assertContains('harbour', array_column($this->plugin->analytics->report('popular'), 'q'));
        $this->assertSame(['zzqxv'], array_column($this->plugin->analytics->report('noHits'), 'q'));

        $run = $this->command('analytics/report');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertMatchesRegularExpression('/popular .*\n.*harbour/', $run['out']);
        $this->assertMatchesRegularExpression('/noHits .*\n.*zzqxv/', $run['out']);
    }

    // TS-8 step 3 - view events survive a reindex (BR-14) ----------------------------------------

    public function testViewEventsAreCountedAndSurviveAReindex(): void
    {
        $entry = $this->saveEntry('Harbour opens');
        $this->command('setup');

        $created = $this->command('analytics/create-events-key');
        $this->assertSame(ExitCode::OK, $created['exit'], $created['err']);
        $this->assertSame(1, preg_match('/TYPESENSE_EVENTS_KEY="([^"]+)"/', $created['out'], $match), 'the value is shown');
        $key = $match[1];
        $record = $this->keyRecord($key);
        $this->keyIds[] = (int)$record['id'];
        $this->assertSame(['analytics/events:create'], $record['actions'], 'the key can post events and nothing else');

        $this->useSettings($this->settings(eventsKey: $key));
        $config = $this->plugin->analytics->clientConfig();
        $this->assertSame($key, $config['apiKey'] ?? null);
        $this->assertStringNotContainsString((string)getenv('TYPESENSE_TEST_API_KEY'), (string)json_encode($config), 'BR-17');

        // What a page does with analyticsConfig(): post as the browser, with the events key only.
        $browser = $this->plugin->client->createClient($this->settings(), 0, $key);
        foreach (['visitor-a', 'visitor-b'] as $visitor) {
            $browser->analytics->events()->create([
                'name' => $config['rules']['views']['name'],
                'event_type' => $config['rules']['views']['eventType'],
                'data' => ['doc_id' => (string)$entry->id, 'user_id' => $visitor],
            ]);
        }

        $this->waitFor(fn() => ($this->plugin->analytics->viewCounts('views', [$entry->id])[(string)$entry->id] ?? 0) === 2);

        $run = $this->command('sync');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame([(string)$entry->id => 2], $this->plugin->analytics->viewCounts('views', [$entry->id]), 'step 3: 2 after the reindex');

        $html = Craft::$app->getView()->renderString("{{ craft.typesense.viewCounts('views', [id])[id] ?? 'unknown' }}", ['id' => (string)$entry->id]);
        $this->assertSame('2', $html, 'the template helper reads the same count');

        $report = $this->command('analytics/report');
        $this->assertMatchesRegularExpression('/views .*\n\s+2\s+' . $entry->id . '/', $report['out']);
    }

    // TS-8 step 4 - remove only what was declared (BR-21) ------------------------------------------

    public function testRemoveDeletesOnlyTheDeclaredRules(): void
    {
        $this->command('setup', ['skipSync' => true]);
        $foreign = $this->prefix . 'content_handmade';
        $this->admin->analytics->rules()[$foreign]->update([
            'name' => $foreign,
            'type' => 'counter',
            'collection' => $this->prefix . 'content',
            'event_type' => 'click',
            'params' => ['destination_collection' => $this->prefix . 'content', 'counter_field' => 'views', 'weight' => 1],
        ]);

        $status = $this->command('analytics/status');
        $this->assertStringContainsString('not declared here (left alone): ' . $foreign, $status['out']);

        $dry = $this->command('analytics/remove', ['dryRun' => true]);
        $this->assertSame(ExitCode::OK, $dry['exit'], $dry['err']);
        $this->assertCount(4, $this->ruleNames(), 'a dry run deletes nothing');

        $run = $this->command('analytics/remove');
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame([$foreign], $this->ruleNames(), 'step 4: only the declared rules removed');
        $this->admin->collections[$this->prefix . 'content_popular']->retrieve();
        $this->addToAssertionCount(1); // the history is kept: retrieve() would throw otherwise
    }

    public function testNothingDeclaredIsNotAFailure(): void
    {
        $this->useSettings($this->settings(analytics: false));

        foreach (['analytics/status', 'analytics/apply', 'analytics/report', 'analytics/remove'] as $route) {
            $run = $this->command($route);
            $this->assertSame(ExitCode::OK, $run['exit'], $route);
            $this->assertStringContainsString('No analytics rules are declared', $run['out'], $route);
        }

        $this->assertSame([], $this->ruleNames());
    }

    public function testAConfigProblemRefusesAndSendsNothing(): void
    {
        $this->useSettings($this->settings(analytics: ['rules' => ['popular' => ['type' => 'popular', 'collection' => 'content']]]));
        $this->command('collections/apply');

        $run = $this->command('analytics/apply');
        $this->assertSame(ExitCode::CONFIG, $run['exit']);
        $this->assertStringContainsString('unknown type "popular"', $run['err']);
        $this->assertSame([], $this->ruleNames());
    }

    // Helpers ------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|false|null $analytics False declares none; null the default rules.
     */
    private function settings(int $limit = 1000, string $eventsKey = '', array|false|null $analytics = null): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => []],
            'sources' => [['handle' => $this->section->handle, 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
            'analytics' => $analytics === false ? [] : ($analytics ?? [
                'eventsKey' => $eventsKey,
                'rules' => [
                    'popular' => ['type' => 'popular_queries', 'collection' => 'content', 'limit' => $limit],
                    'noHits' => ['type' => 'nohits_queries', 'collection' => 'content'],
                    'views' => ['type' => 'counter', 'collection' => 'content', 'counterField' => 'views', 'eventType' => 'visit'],
                ],
            ]),
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }

    /**
     * @return list<string> This test's rules on the server, sorted.
     */
    private function ruleNames(): array
    {
        $names = array_values(array_filter(
            array_keys($this->plugin->analytics->liveRules()),
            fn(string $name) => str_starts_with($name, $this->prefix),
        ));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRule(string $handle): array
    {
        return $this->plugin->analytics->liveRules()[$this->prefix . 'content_' . $handle] ?? [];
    }

    /**
     * The server's record of a key, found by the prefix it shows of the value.
     *
     * @return array{id: int, actions: list<string>}
     */
    private function keyRecord(string $value): array
    {
        foreach ((array)$this->admin->keys->retrieve()['keys'] as $key) {
            if (str_starts_with($value, (string)$key['value_prefix'])) {
                return ['id' => (int)$key['id'], 'actions' => array_values((array)$key['actions'])];
            }
        }

        $this->fail('the key is not on the server');
    }

    /**
     * Poll until the analytics have landed, forcing the server's flush each time: left to itself,
     * 30.0 applies queries every ~10 s and counters every ~30 s, whatever the flush interval says.
     */
    private function waitFor(callable $done, float $seconds = 15): void
    {
        $until = microtime(true) + $seconds;
        $flush = sprintf('%s://%s:%s/analytics/flush', getenv('TYPESENSE_TEST_PROTOCOL'), getenv('TYPESENSE_TEST_HOST'), getenv('TYPESENSE_TEST_PORT'));

        while (!$done()) {
            $this->assertLessThan($until, microtime(true), 'analytics never flushed');
            Craft::createGuzzleClient()->post($flush, ['headers' => ['X-TYPESENSE-API-KEY' => (string)getenv('TYPESENSE_TEST_API_KEY')]]);
            usleep(250_000);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{exit: int, out: string, err: string}
     */
    private function command(string $route, array $params = []): array
    {
        $created = $this->plugin->createController($route);
        $this->assertIsArray($created, "no command $route");
        [$controller, $action] = $created;
        $this->assertInstanceOf(Controller::class, $controller);

        $out = '';
        $err = '';

        /** @var Controller $stub */
        $stub = Stub::construct($controller::class, [$controller->id, $this->plugin], [
            'stdout' => function(string $string) use (&$out): int {
                $out .= $string;

                return strlen($string);
            },
            'stderr' => function(string $string) use (&$err): int {
                $err .= $string;

                return strlen($string);
            },
        ]);
        $stub->interactive = false;
        $exit = $stub->runAction($action, $params);

        return ['exit' => (int)$exit, 'out' => $out, 'err' => $err];
    }

    private function createSection(): Section
    {
        $handle = 'news' . substr($this->prefix, 2, 8);
        $entryType = new EntryType(['name' => 'News', 'handle' => $handle . 'Type']);
        // Without a title field in its layout, an entry's title is not saved.
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new EntryTitleField()]])]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), implode(' ', $entryType->getFirstErrors()));

        $section = new Section([
            'name' => 'News',
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings([
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => 'news/{slug}',
                'template' => '_entry',
            ])],
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(Craft::$app->getEntries()->saveSection($section), implode(' ', $section->getFirstErrors()));

        return $section;
    }

    private function saveEntry(string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section->id,
            'typeId' => $this->section->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }
}
