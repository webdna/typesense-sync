<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\TypesenseClientError;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;

/**
 * TS-5 against the real server: a page rendered with `craft.typesense.searchConfig()` gets a key
 * that finds published content, never content outside its window whatever the browser asks, never
 * the admin key, and stops working once it expires (BR-17, BR-18, BR-20).
 */
class SearchTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private string $searchKey = '';

    private ?int $searchKeyId = null;

    private int $now;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        $this->now = time();

        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $key = $this->admin->keys->create([
            'description' => $this->prefix . 'search',
            'actions' => ['documents:search'],
            'collections' => ['*'],
        ]);
        $this->searchKey = (string)$key['value'];
        $this->searchKeyId = (int)$key['id'];

        $this->useSettings($this->settings());
        $this->plugin->collections->apply('content');
        $this->plugin->sync->import('content', [
            $this->document('live', 'Published', $this->now - 3600, BaseFormatter::FAR_FUTURE),
            $this->document('future', 'Scheduled', $this->now + 86400, BaseFormatter::FAR_FUTURE),
            $this->document('expired', 'Withdrawn', $this->now - 86400, $this->now - 60),
        ]);
    }

    protected function _after(): void
    {
        $this->plugin->search->setNow(null);
        TestCollections::deleteAll($this->admin, $this->prefix);

        if ($this->searchKeyId !== null) {
            $this->admin->keys[$this->searchKeyId]->delete();
        }

        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    public function testAPageKeyFindsOnlyPublishedContentAndNeverCarriesTheAdminKey(): void
    {
        // Step 1: a template asks for the configuration, as a search page would.
        $html = Craft::$app->getView()->renderString(
            "{% set search = craft.typesense.searchConfig('content') %}"
            . "<div data-search=\"{{ search|json_encode|e('html_attr') }}\"></div>",
        );
        preg_match('/data-search="([^"]*)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);

        $this->assertIsArray($config, $html);
        $this->assertSame((string)getenv('TYPESENSE_TEST_HOST'), $config['host']);
        $this->assertSame($this->prefix . 'content', $config['collection'], 'the alias, not a version');

        // Step 3: the admin key is nowhere in the page, and neither is the search key itself.
        $this->assertStringNotContainsString((string)getenv('TYPESENSE_TEST_API_KEY'), $html);
        $this->assertStringNotContainsString($this->searchKey, $html);

        // Step 2: the key searches.
        $this->assertSame(['live'], $this->ids($config['apiKey'], []));

        // Step 4: outside the window, whatever the browser asks for.
        $this->assertSame([], $this->ids($config['apiKey'], ['filter_by' => 'id:[future,expired]']));
        $this->assertSame([], $this->ids($config['apiKey'], ['filter_by' => 'postDate:>' . $this->now . ' || expiryDate:<' . $this->now]));
    }

    public function testACallersFilterNarrowsButNeverReachesOutsideTheWindow(): void
    {
        $key = (string)$this->plugin->search->scopedKey('content', ['filter' => 'title:=[Published,Scheduled,Withdrawn] || id:*']);

        $this->assertSame(['live'], $this->ids($key, []));
        $this->assertSame([], $this->ids((string)$this->plugin->search->scopedKey('content', ['filter' => 'id:=future']), []));
    }

    public function testExcludedFieldsAreNeverReturned(): void
    {
        $this->useSettings($this->settings(['search' => ['excludeFields' => ['keywords']]]));
        $key = (string)$this->plugin->search->scopedKey('content');

        $hit = $this->search($key, ['exclude_fields' => '', 'include_fields' => 'id,title,keywords'])['hits'][0]['document'] ?? [];

        $this->assertSame('live', $hit['id'] ?? null);
        $this->assertArrayNotHasKey('keywords', $hit);
    }

    public function testAKeyPastItsTtlIsRefused(): void
    {
        // Step 5: made 3601 s ago, so it expired a second ago.
        $this->plugin->search->setNow($this->now - 3601);
        $key = (string)$this->plugin->search->scopedKey('content');

        $refused = null;
        try {
            $this->search($key, []);
        } catch (TypesenseClientError $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'an expired scoped key still searched');

        $this->plugin->search->setNow(null);
        $this->assertSame(['live'], $this->ids((string)$this->plugin->search->scopedKey('content'), []));
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function ids(string $key, array $params): array
    {
        $ids = array_map(fn(array $hit) => (string)$hit['document']['id'], (array)($this->search($key, $params)['hits'] ?? []));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function search(string $key, array $params): array
    {
        $client = $this->plugin->client->createClient($this->settings(), 0, $key);

        return $client->collections[$this->prefix . 'content']->documents->search($params + ['q' => '*', 'query_by' => 'title']);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $id, string $title, int $postDate, int $expiryDate): array
    {
        return ['id' => $id, 'title' => $title, 'type' => 'Doc', 'priority' => 1, 'postDate' => $postDate, 'expiryDate' => $expiryDate, 'keywords' => 'secret words'];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function settings(array $content = []): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'searchApiKey' => $this->searchKey,
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => $content],
            'sources' => [['handle' => 'news', 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }
}
