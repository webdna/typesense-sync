<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Typesense\Client as TypesenseClient;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NewsFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ReferencingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\WiderFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;

/**
 * Apply against the real server (BR-3, BR-16): what it creates, that what it created then reads
 * back as up to date — which is what calibrates normalise() against Typesense's own defaults —
 * and a formatter change showing as a difference and altering in place (TS-4 step 1).
 *
 * Nothing here needs a section to exist: the schema comes from the declaration alone.
 */
class CollectionsTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
    }

    protected function _after(): void
    {
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    public function testApplyCreatesTheFirstVersionBehindTheAliasThenHasNothingToDo(): void
    {
        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_CREATE, $result['action'], $result['message']);
        $this->assertSame($this->prefix . 'content_1', $result['collection']);
        $alias = $this->admin->aliases[$this->prefix . 'content']->retrieve();
        $this->assertSame($this->prefix . 'content_1', $alias['collection_name']);

        $again = $this->collections()->apply('content');
        $this->assertSame(Collections::ACTION_NONE, $again['action'], 'no phantom difference: ' . json_encode($this->collections()->diff('content')));
        $this->assertTrue($this->collections()->diff('content')['upToDate']);
    }

    public function testASortingFieldAndNestingOffReadBackAsDeclared(): void
    {
        $this->useSettings($this->settings(['defaultSortingField' => 'priority', 'enableNestedFields' => false]));

        $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_NONE, $this->collections()->apply('content')['action']);
    }

    public function testAReferenceFieldReadsBackUpToDate(): void
    {
        $this->useSettings($this->settings(sources: [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => ReferencingFormatter::class],
            ['handle' => 'staff', 'collection' => 'people', 'formatter' => NewsFormatter::class],
        ], collections: ['people' => []]));

        $this->assertSame(Collections::ACTION_CREATE, $this->collections()->apply('people')['action']);
        $this->assertSame(Collections::ACTION_CREATE, $this->collections()->apply('content')['action']);

        $diff = $this->collections()->diff('content');
        $this->assertTrue($diff['upToDate'], json_encode($diff) ?: '');
    }

    public function testAFormatterGainingAFieldShowsAsADifferenceAndIsAlteredInPlace(): void
    {
        $this->collections()->apply('content');
        $this->plugin->sync->import('content', [['id' => '1', 'title' => 'Kept', 'type' => 'Doc', 'priority' => 1, 'postDate' => 0, 'expiryDate' => 0]]);

        // The developer adds a field to the formatter (TS-4 step 1).
        $this->useSettings($this->settings(sources: [$this->source(WiderFormatter::class)]));
        $diff = $this->collections()->diff('content');

        $this->assertFalse($diff['upToDate']);
        $this->assertSame(['subtitle'], $diff['added']);
        $this->assertSame(1, $diff['documents']);

        $dry = $this->collections()->apply('content', true);
        $this->assertSame(Collections::ACTION_ALTER, $dry['action']);
        $this->assertFalse($this->collections()->diff('content')['upToDate'], 'a dry run changed nothing');

        $result = $this->collections()->apply('content');
        $this->assertSame(Collections::ACTION_ALTER, $result['action']);
        $this->assertTrue($result['reindex'], 'BR-16: says a reindex is needed');
        $this->assertSame($this->prefix . 'content_1', $result['collection'], 'altered in place, same version');
        $this->assertTrue($this->collections()->diff('content')['upToDate']);
        $this->assertSame('Kept', $this->admin->collections[$this->prefix . 'content']->documents['1']->retrieve()['title']);
    }

    public function testAChangedSortingFieldIsRefusedAndLeftAlone(): void
    {
        $this->collections()->apply('content');

        $this->useSettings($this->settings(['defaultSortingField' => 'priority']));
        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_NEEDS_RECREATE, $result['action']);
        $this->assertSame('', $this->admin->collections[$this->prefix . 'content_1']->retrieve()['default_sorting_field']);
    }

    /**
     * @param array<string, mixed> $content
     * @param list<array<string, mixed>>|null $sources
     * @param array<string, array<string, mixed>> $collections
     */
    private function settings(array $content = [], ?array $sources = null, array $collections = []): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => $content] + $collections,
            'sources' => $sources ?? [$this->source(DocumentFormatter::class)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $formatter): array
    {
        return ['handle' => 'news', 'collection' => 'content', 'formatter' => $formatter];
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }

    private function collections(): Collections
    {
        return $this->plugin->collections;
    }
}
