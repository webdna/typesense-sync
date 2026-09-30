<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use Craft;
use webdna\typesensesync\models\AnalyticsRule;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Analytics;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\MockedClient;
use webdna\typesensesync\TypesenseSync;

/**
 * Analytics without a server: what a declaration resolves to and what it is refused for (BR-4,
 * BR-21), counter fields reaching both the sync and the schema (BR-14), rule comparison, and the
 * browser's config never carrying the admin key (BR-17, BR-20).
 */
class AnalyticsTest extends Unit
{
    private TypesenseSync $plugin;

    private Client $originalClient;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->originalClient = $this->plugin->client;
        $this->useSettings($this->settings());
    }

    protected function _after(): void
    {
        $this->plugin->targets->setSettings(null);
        $this->plugin->set('client', $this->originalClient);
    }

    // Declaring (BR-21) ------------------------------------------------------------------------

    public function testNothingIsOnUnlessRulesAreDeclaredAndEnabled(): void
    {
        $this->assertFalse($this->settings(analytics: [])->isAnalyticsEnabled());
        $this->assertFalse($this->settings(analytics: ['enabled' => false] + $this->analytics())->isAnalyticsEnabled(), 'switched off per environment');
        $this->assertSame(['popular', 'views'], array_keys($this->settings(analytics: ['rules' => [
            'noHits' => ['type' => 'nohits_queries', 'collection' => 'content', 'enabled' => false],
        ] + $this->analytics()['rules']])->getAnalyticsRules()), 'a disabled rule is left out');
    }

    public function testRulesAreNamedAfterTheLiveCollectionAndDeriveTheirDestination(): void
    {
        $rules = $this->settings()->getAnalyticsRules();

        $this->assertSame([
            'name' => 't_content_popular',
            'type' => 'popular_queries',
            'collection' => 't_content',
            'event_type' => 'search',
            'params' => ['destination_collection' => 't_content_popular', 'limit' => 1000],
        ], $rules['popular']->getPayload('t_content'));

        $this->assertSame([
            'name' => 't_content_views',
            'type' => 'counter',
            'collection' => 't_content',
            'event_type' => 'visit',
            'params' => ['destination_collection' => 't_content', 'counter_field' => 'views', 'weight' => 1],
        ], $rules['views']->getPayload('t_content'), 'a counter writes into its own collection');
    }

    public function testADestinationMayBeAnEnvReference(): void
    {
        putenv('TS_TEST_QUERIES=site_queries');
        $rule = AnalyticsRule::fromConfig('popular', ['collection' => 'content', 'destination' => '$TS_TEST_QUERIES']);

        $this->assertSame('site_queries', $rule->getDestination('t_content'));
        putenv('TS_TEST_QUERIES');
    }

    // Problems (BR-4) --------------------------------------------------------------------------

    public function testAValidDeclarationHasNoProblems(): void
    {
        $this->assertSame([], $this->settings()->getProblems([]));
    }

    public function testUnknownKeysAreNamed(): void
    {
        $problems = $this->settings(analytics: ['rule' => []] + $this->analytics(['popular' => ['colection' => 'x']]))->getProblems([]);

        $this->assertContains('Analytics sets an unknown key "rule".', $problems);
        $this->assertContains('Analytics rule "popular" sets an unknown key "colection".', $problems);
    }

    public function testATypeOrCollectionThatDoesNotExist(): void
    {
        $problems = $this->settings(analytics: $this->analytics([
            'popular' => ['type' => 'popular'],
            'views' => ['collection' => 'products'],
        ]))->getProblems([]);

        $this->assertContains('Analytics rule "popular" has unknown type "popular"; expected one of popular_queries, nohits_queries, counter.', $problems);
        $this->assertContains('Analytics rule "views" names collection "products", which is not declared.', $problems);
    }

    public function testADisabledRuleIsCheckedOnlyForItsKeys(): void
    {
        $this->assertSame([], $this->settings(analytics: $this->analytics([
            'popular' => ['collection' => 'products', 'enabled' => false],
        ]))->getProblems([]));
    }

    public function testDestinationsMustBeTheirOwn(): void
    {
        $problems = $this->settings(analytics: $this->analytics([
            'popular' => ['destination' => 't_content'],
            'noHits' => ['type' => 'nohits_queries', 'collection' => 'content', 'destination' => 't_content'],
        ]))->getProblems([]);

        $this->assertContains('Analytics rule "popular" aggregates into "t_content", which is a declared collection.', $problems);
        $this->assertContains('Analytics rules "popular" and "noHits" both aggregate into "t_content".', $problems);
    }

    public function testAHandleThatWouldReadAsAVersion(): void
    {
        $problems = $this->settings(analytics: ['rules' => ['2nd' => ['collection' => 'content']]])->getProblems([]);

        $this->assertContains('Analytics rule "2nd" must start with a letter and hold only letters, digits, "_" and "-".', $problems);
    }

    public function testTheEventsKeyMustNotBeTheAdminKey(): void
    {
        $problems = $this->settings(analytics: ['eventsKey' => 'admin-key'] + $this->analytics())->getProblems([]);

        $this->assertStringStartsWith('The analytics events key is the admin API key.', $problems[0] ?? '');
    }

    // Counters (BR-14) -------------------------------------------------------------------------

    public function testACounterRulesFieldIsCarriedThroughEveryWrite(): void
    {
        $this->useSettings($this->settings(collection: ['counters' => ['likes']]));

        $this->assertSame(['likes', 'views'], $this->plugin->sync->counterFields('content'));
    }

    public function testACounterRulesFieldIsAddedToTheSchemaUnlessDeclared(): void
    {
        $fields = array_column($this->plugin->collections->getDesiredFields('content'), null, 'name');
        $this->assertSame(['name' => 'views', 'type' => 'int32', 'optional' => true], $fields['views'] ?? null);

        $this->useSettings($this->settings(collection: ['schema' => [['name' => 'views', 'type' => 'int64']]]));
        $fields = array_column($this->plugin->collections->getDesiredFields('content'), null, 'name');
        $this->assertSame('int64', $fields['views']['type'] ?? null, 'a declared field wins');

        $this->useSettings($this->settings(analytics: []));
        $this->assertArrayNotHasKey('views', array_column($this->plugin->collections->getDesiredFields('content'), null, 'name'), 'nothing added without analytics');
    }

    // Matching ---------------------------------------------------------------------------------

    public function testAMatchIgnoresWhatTheServerFillsInButNotAChangedParameter(): void
    {
        $wanted = $this->settings()->getAnalyticsRules()['popular']->getPayload('t_content');
        $live = $wanted + ['rule_tag' => ''];
        $live['params'] += ['capture_search_requests' => true, 'expand_query' => false];

        $this->assertTrue(Analytics::matches($live, $wanted));

        $live['params']['limit'] = 500;
        $this->assertFalse(Analytics::matches($live, $wanted));
        $this->assertFalse(Analytics::matches(['event_type' => 'click'] + $wanted, $wanted));
    }

    // The browser's config (BR-17, BR-20) --------------------------------------------------------

    public function testTheBrowserGetsTheEventsKeyAndEachCounterRule(): void
    {
        $this->useSettings($this->settings(analytics: ['eventsKey' => 'events-key'] + $this->analytics()));

        $this->assertSame([
            'host' => 'typesense.test',
            'port' => 8108,
            'protocol' => 'http',
            'apiKey' => 'events-key',
            'rules' => ['views' => ['name' => 't_content_views', 'collection' => 't_content', 'eventType' => 'visit']],
        ], $this->plugin->analytics->clientConfig());
    }

    public function testNoBrowserConfigWithoutAKeyOrACounterOrWithTheAdminKey(): void
    {
        $this->assertNull($this->plugin->analytics->clientConfig(), 'no events key');

        $this->useSettings($this->settings(analytics: ['eventsKey' => 'admin-key'] + $this->analytics()));
        $this->assertNull($this->plugin->analytics->clientConfig(), 'the admin key never reaches a page');

        $this->useSettings($this->settings(analytics: ['eventsKey' => 'events-key', 'rules' => ['popular' => ['collection' => 'content']]]));
        $this->assertNull($this->plugin->analytics->clientConfig(), 'no counter rule');
    }

    public function testViewCountsNeedADeclaredCounterRule(): void
    {
        $this->assertNull($this->plugin->analytics->viewCounts('popular', [1]), 'a query rule counts nothing');
        $this->assertNull($this->plugin->analytics->viewCounts('nope', [1]));
        $this->assertSame([], $this->plugin->analytics->viewCounts('views', []), 'no ids, no request');
    }

    public function testTheTemplateHelpersAreNullWhenUnconfigured(): void
    {
        $this->useSettings(new Settings(['analytics' => ['eventsKey' => 'events-key'] + $this->analytics()]));

        $html = Craft::$app->getView()->renderString(
            "{{ craft.typesense.analyticsConfig() is null ? 'no config' }}"
            . "|{{ craft.typesense.viewCounts('views', [1, 2]) is null ? 'no counts' }}",
        );

        $this->assertSame('no config|no counts', $html);
    }

    /**
     * @param array<string, array<string, mixed>> $overrides Merged into the rule of that handle.
     * @return array<string, mixed>
     */
    private function analytics(array $overrides = []): array
    {
        $rules = [
            'popular' => ['type' => 'popular_queries', 'collection' => 'content'],
            'views' => ['type' => 'counter', 'collection' => 'content', 'counterField' => 'views', 'eventType' => 'visit'],
        ];

        foreach ($overrides as $handle => $values) {
            $rules[$handle] = $values + ($rules[$handle] ?? []);
        }

        return ['rules' => $rules];
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<string, mixed>|null $analytics
     */
    private function settings(array $collection = [], ?array $analytics = null): Settings
    {
        return new Settings([
            'host' => 'typesense.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => 'admin-key',
            'searchApiKey' => 'search-key',
            'collectionPrefix' => 't_',
            'collections' => ['content' => $collection],
            'sources' => [['handle' => 'news', 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
            'analytics' => $analytics ?? $this->analytics(),
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $http = new MockedClient();
        $http->setClient($settings->isConfigured() ? $http->createClient($settings, 0) : null);
        $this->plugin->set('client', $http);
    }
}
