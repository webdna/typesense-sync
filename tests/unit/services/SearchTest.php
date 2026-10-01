<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use Craft;
use RuntimeException;
use webdna\typesensesync\events\DefineScopedKeyEvent;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\services\Search;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\MockedClient;
use webdna\typesensesync\TypesenseSync;
use yii\base\Event;

/**
 * Scoped keys with the clock injected and Typesense never reached (signing is local): what a
 * key embeds (BR-18), what it is signed with (BR-17), and the null answers behind `craft.typesense`
 * (BR-20, TN-11).
 */
class SearchTest extends Unit
{
    private const NOW = 1_800_000_000;

    private TypesenseSync $plugin;

    private Client $originalClient;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->originalClient = $this->plugin->client;
        $this->useSettings($this->settings());
        $this->search()->setNow(self::NOW);
    }

    protected function _after(): void
    {
        $this->search()->setNow(null);
        $this->plugin->targets->setSettings(null);
        $this->plugin->set('client', $this->originalClient);
        Event::off(Search::class, Search::EVENT_DEFINE_SCOPED_KEY);
    }

    // What a key embeds (BR-18) --------------------------------------------------------------

    public function testAKeyEmbedsThePublicationWindowAndExpiresAfterTheDefaultTtl(): void
    {
        $this->assertSame([
            'filter_by' => 'postDate:<=' . self::NOW . ' && expiryDate:>' . self::NOW,
            'expires_at' => self::NOW + 3600,
        ], $this->search()->scopedKeyParams('content'));
    }

    public function testTheCallersFilterIsAndedInParenthesesSoItCannotWidenTheWindow(): void
    {
        $params = $this->search()->scopedKeyParams('content', ['filter' => 'type:=News || type:=Page']);

        $this->assertSame(
            '(postDate:<=' . self::NOW . ' && expiryDate:>' . self::NOW . ') && (type:=News || type:=Page)',
            $params['filter_by'] ?? null,
        );
    }

    public function testAWindowSwitchedOffLeavesOnlyTheCallersFilterAndNoFilterAtAllEmbedsNone(): void
    {
        $this->useSettings($this->settings(['search' => ['publicationWindow' => false]]));

        $this->assertSame('type:=News', $this->search()->scopedKeyParams('content', ['filter' => 'type:=News'])['filter_by'] ?? null);
        $this->assertArrayNotHasKey('filter_by', (array)$this->search()->scopedKeyParams('content'));
    }

    public function testExcludedFieldsComeFromConfigThenTheCallerThenHandlersOnceEach(): void
    {
        $this->useSettings($this->settings(['search' => ['excludeFields' => ['email', 'internalId']]]));
        Event::on(Search::class, Search::EVENT_DEFINE_SCOPED_KEY, function(DefineScopedKeyEvent $event) {
            $event->excludeFields[] = 'notes';
            $event->excludeFields[] = 'email';
        });

        $params = $this->search()->scopedKeyParams('content', ['excludeFields' => ['phone']]);

        $this->assertSame('email,internalId,phone,notes', $params['exclude_fields'] ?? null);
    }

    public function testAHandlerAddsAFilterButCannotTakeTheWindowOrTheCallersFilterAway(): void
    {
        Event::on(Search::class, Search::EVENT_DEFINE_SCOPED_KEY, function(DefineScopedKeyEvent $event) {
            $this->assertSame('content', $event->collection->handle);
            $this->assertSame(self::NOW, $event->now);
            $this->assertSame(['filter' => 'type:=News'], $event->params);
            $event->filters = ['status:=active'];
        });

        $params = $this->search()->scopedKeyParams('content', ['filter' => 'type:=News']);

        $this->assertSame(
            '(postDate:<=' . self::NOW . ' && expiryDate:>' . self::NOW . ') && (type:=News) && (status:=active)',
            $params['filter_by'] ?? null,
        );
    }

    public function testAHandlerThatRefusesTheKeyLeavesNothingToSearchWith(): void
    {
        Event::on(Search::class, Search::EVENT_DEFINE_SCOPED_KEY, function(DefineScopedKeyEvent $event) {
            $event->filters[] = 'status:=active';
            $event->isValid = false;
        });

        $this->assertNull($this->search()->scopedKeyParams('content'));
        $this->assertNull($this->search()->scopedKey('content'));
        $this->assertNull($this->search()->searchConfig('content'));
        $this->assertSame('t_content', $this->search()->collectionName('content'), 'the name is still known');

        $html = Craft::$app->getView()->renderString("{{ craft.typesense.searchConfig('content') is null ? 'unavailable' }}");
        $this->assertSame('unavailable', $html);
    }

    public function testOtherSearchParametersAreEmbeddedButThePluginsOwnCannotBeSet(): void
    {
        $params = $this->search()->scopedKeyParams('content', [
            'limit_hits' => 20,
            'filter_by' => 'id:*',
            'exclude_fields' => '',
            'expires_at' => PHP_INT_MAX,
        ]);

        $this->assertSame(20, $params['limit_hits'] ?? null);
        $this->assertSame('postDate:<=' . self::NOW . ' && expiryDate:>' . self::NOW, $params['filter_by'] ?? null);
        $this->assertArrayNotHasKey('exclude_fields', (array)$params);
        $this->assertSame(self::NOW + 3600, $params['expires_at'] ?? null);
    }

    public function testATtlIsHonouredAndAnUnusableOneFallsBackToTheDefault(): void
    {
        $this->assertSame(self::NOW + 300, $this->search()->scopedKeyParams('content', ['ttl' => 300])['expires_at'] ?? null);
        $this->assertSame(self::NOW + 3600, $this->search()->scopedKeyParams('content', ['ttl' => 0])['expires_at'] ?? null);
        $this->assertSame(self::NOW + 3600, $this->search()->scopedKeyParams('content', ['ttl' => 'soon'])['expires_at'] ?? null);
    }

    // What a key is signed with (BR-17) ------------------------------------------------------

    public function testTheKeyIsSignedWithTheSearchOnlyKeyAndCarriesItsParameters(): void
    {
        $key = (string)$this->search()->scopedKey('content', ['filter' => 'type:=News']);
        $raw = (string)base64_decode($key, true);
        $digest = substr($raw, 0, 44);
        $json = substr($raw, 48);

        $this->assertSame('sear', substr($raw, 44, 4), 'prefixed with the search key');
        $this->assertSame(base64_encode(hash_hmac('sha256', $json, 'search-key', true)), $digest);
        $this->assertNotSame(base64_encode(hash_hmac('sha256', $json, 'admin-key', true)), $digest);
        $this->assertSame($this->search()->scopedKeyParams('content', ['filter' => 'type:=News']), json_decode($json, true));
    }

    public function testSearchConfigHandsOverTheServerTheAliasAndAScopedKeyButNeverTheAdminKey(): void
    {
        $config = (array)$this->search()->searchConfig('content');

        $this->assertSame(['host', 'port', 'protocol', 'collection', 'apiKey', 'expiresAt'], array_keys($config));
        $this->assertSame('typesense.test', $config['host']);
        $this->assertSame(8108, $config['port']);
        $this->assertSame('http', $config['protocol']);
        $this->assertSame('t_content', $config['collection']);
        $this->assertSame(self::NOW + 3600, $config['expiresAt']);
        $this->assertStringNotContainsString('admin-key', (string)json_encode($config));
        $this->assertNotSame('search-key', $config['apiKey']);
    }

    public function testAnExplicitNameIsTheCollectionName(): void
    {
        $this->useSettings($this->settings(['name' => 'site_search']));

        $this->assertSame('site_search', $this->search()->collectionName('content'));
    }

    // Null, never an exception (BR-17, BR-20, TN-11) -----------------------------------------

    public function testNothingIsMadeForAnUndeclaredCollection(): void
    {
        $this->assertNull($this->search()->scopedKeyParams('nope'));
        $this->assertNull($this->search()->scopedKey('nope'));
        $this->assertNull($this->search()->searchConfig('nope'));
        $this->assertNull($this->search()->collectionName('nope'));
    }

    public function testNothingIsMadeWithoutASearchKey(): void
    {
        $this->useSettings($this->settings(values: ['searchApiKey' => '']));

        $this->assertNull($this->search()->scopedKey('content'));
        $this->assertNull($this->search()->searchConfig('content'));
        $this->assertSame('t_content', $this->search()->collectionName('content'), 'the name needs no key');
    }

    public function testNothingIsSignedWithTheAdminKey(): void
    {
        $this->useSettings($this->settings(values: ['searchApiKey' => 'admin-key']));

        $this->assertNull($this->search()->scopedKey('content'));
        $this->assertNull($this->search()->searchConfig('content'));
    }

    public function testNothingIsMadeForAnExplicitNameThatResolvesToNothing(): void
    {
        $this->useSettings($this->settings(['name' => '$TYPESENSE_SYNC_UNSET_NAME']));

        $this->assertNull($this->search()->collectionName('content'));
        $this->assertNull($this->search()->searchConfig('content'));
    }

    public function testTheTemplateHelperReturnsNullWithNoSettingsAndThePageRenders(): void
    {
        $this->useSettings(new Settings());

        $html = Craft::$app->getView()->renderString(
            "{% set search = craft.typesense.searchConfig('content') %}"
            . "{{ search ? 'available' : 'unavailable' }}"
            . "|{{ craft.typesense.scopedKey('content') is null ? 'no key' }}"
            . "|{{ craft.typesense.collectionName('content') is null ? 'no name' }}",
        );

        $this->assertSame('unavailable|no key|no name', $html);
    }

    public function testTheTemplateHelperTurnsAFailureIntoNull(): void
    {
        Event::on(Search::class, Search::EVENT_DEFINE_SCOPED_KEY, function() {
            throw new RuntimeException('a handler broke');
        });

        $html = Craft::$app->getView()->renderString("{{ craft.typesense.searchConfig('content') is null ? 'unavailable' }}");

        $this->assertSame('unavailable', $html);
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<string, mixed> $values
     */
    private function settings(array $collection = [], array $values = []): Settings
    {
        return new Settings($values + [
            'host' => 'typesense.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => 'admin-key',
            'searchApiKey' => 'search-key',
            'collectionPrefix' => 't_',
            'collections' => ['content' => $collection],
            'sources' => [['handle' => 'news', 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $http = new MockedClient();
        $http->setClient($settings->isConfigured() ? $http->createClient($settings, 0) : null);
        $this->plugin->set('client', $http);
    }

    private function search(): Search
    {
        return $this->plugin->search;
    }
}
