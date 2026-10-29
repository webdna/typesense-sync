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
use DateTime;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\jobs\PushRules;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\PrefixedIdFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\log\Logger;

/**
 * The rules service against the real server: the sets it pushes, what search then does with them,
 * the state it reports and a set deleted without breaking search (TS-1, TS-3, TS-4, TS-11, TN-5,
 * TN-8, TN-9; BR-1, 4, 5, 8, 9, 11, 12, 14).
 */
class RulesTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private string $searchKey = '';

    private ?int $searchKeyId = null;

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
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);

        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $key = $this->admin->keys->create(['description' => $this->prefix . 'search', 'actions' => ['documents:search'], 'collections' => ['*']]);
        $this->searchKey = (string)$key['value'];
        $this->searchKeyId = (int)$key['id'];

        $this->sections['news'] = $this->createSection('news');
        // Feeds nothing: its entries may not be pinned (BR-8).
        $this->sections['other'] = $this->createSection('other');
        $this->useSettings($this->settings());
        $applied = $this->plugin->collections->apply('content');
        $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);
    }

    protected function _after(): void
    {
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);
        Db::delete(Table::QUEUE);
        TestCollections::deleteAll($this->admin, $this->prefix);

        if ($this->searchKeyId !== null) {
            $this->admin->keys[$this->searchKeyId]->delete();
        }

        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    public function testPinnedEntriesLeadAndAHiddenOneIsGone(): void
    {
        // TS-1 step 1.
        $a = $this->indexedEntry('Garden A');
        $b = $this->indexedEntry('Garden B');
        $c = $this->indexedEntry('Garden C');
        $this->assertEqualsCanonicalizing([(string)$a->id, (string)$b->id, (string)$c->id], $this->search('garden'));

        // Step 2.
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$b->id, $a->id], hides: [$c->id]);
        $this->assertNull($this->rules()->pushOrQueue('content'));

        $expected = [
            'id' => 'rule-0001',
            'rule' => ['query' => 'garden', 'match' => 'exact'],
            'includes' => [['id' => (string)$b->id, 'position' => 1], ['id' => (string)$a->id, 'position' => 2]],
            'excludes' => [['id' => (string)$c->id]],
            'filter_curated_hits' => true,
            'stop_processing' => true,
        ];
        $this->assertSame(['items' => [$expected]], $this->rules()->desiredSet('content', Rules::KIND_RULES));
        $this->assertSame([Rules::normaliseItem($expected)], array_map([Rules::class, 'normaliseItem'], $this->liveItems(Rules::KIND_RULES)));

        // BR-1: named after the live alias, and the synonym set exists though empty.
        $this->assertSame($this->prefix . 'content_rules', $this->rules()->setName('content', Rules::KIND_RULES));
        $this->assertSame([], $this->liveItems(Rules::KIND_SYNONYMS));

        // Step 3.
        $this->assertSame([(string)$b->id, (string)$a->id], $this->search('garden'));
    }

    public function testOnlyTheHighestEnabledRuleApplies(): void
    {
        $a = $this->indexedEntry('Garden A');
        $b = $this->indexedEntry('Garden B');

        // TS-3 step 1.
        $first = $this->saveRule('garden', Rule::MATCH_CONTAINS, pins: [$a->id]);
        $second = $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$b->id]);
        $this->rules()->push('content');
        $this->assertSame([['rule-0001', (string)$a->id], ['rule-0002', (string)$b->id]], $this->pushedPins());

        // Step 2.
        $this->assertSame((string)$a->id, $this->search('garden')[0]);

        // Step 3.
        $this->rules()->reorder(Rules::KIND_RULES, 'content', [(int)$second->id, (int)$first->id]);
        $this->rules()->push('content');
        $this->assertSame([['rule-0001', (string)$b->id], ['rule-0002', (string)$a->id]], $this->pushedPins());
        $this->assertSame((string)$b->id, $this->search('garden')[0]);

        // Step 4.
        $second = $this->rules()->getRuleById((int)$second->id);
        $this->assertNotNull($second);
        $second->enabled = false;
        $this->assertTrue($this->rules()->saveRule($second));
        $this->rules()->push('content');
        $this->assertSame([['rule-0001', (string)$a->id]], $this->pushedPins());
        $this->assertSame((string)$a->id, $this->search('garden')[0]);
    }

    public function testASynonymGroupFindsTheOtherWords(): void
    {
        // TS-4 step 1.
        $d = $this->indexedEntry('Nursing home');
        $e = $this->indexedEntry('Care home');
        $this->assertSame([(string)$e->id], $this->search('care home'));

        // Step 2.
        $group = new SynonymGroup(['collection' => 'content', 'words' => ['care home', 'nursing home']]);
        $this->assertTrue($this->rules()->saveSynonymGroup($group), implode(' ', $group->getFirstErrors()));
        $this->rules()->push('content');
        $this->assertSame([['id' => 'synonym-0001', 'synonyms' => ['care home', 'nursing home']]], $this->rules()->desiredSet('content', Rules::KIND_SYNONYMS)['items']);

        // Step 3.
        $this->assertContains((string)$d->id, $this->search('care home'));

        // Step 4: one way, as Typesense means it — the main term finds the words, not the reverse.
        $group->root = 'care home';
        $group->words = ['nursing home'];
        $this->assertTrue($this->rules()->saveSynonymGroup($group), implode(' ', $group->getFirstErrors()));
        $this->rules()->push('content');
        $this->assertSame([['id' => 'synonym-0001', 'root' => 'care home', 'synonyms' => ['nursing home']]], $this->liveItems(Rules::KIND_SYNONYMS));
        $this->assertContains((string)$d->id, $this->search('care home'));
        $this->assertSame([(string)$d->id], $this->search('nursing home'));

        // A switched-off group is not pushed (BR-5).
        $group->enabled = false;
        $this->assertTrue($this->rules()->saveSynonymGroup($group));
        $this->rules()->push('content');
        $this->assertSame([], $this->liveItems(Rules::KIND_SYNONYMS));
    }

    public function testAPinnedEntryOutsideTheKeysWindowIsNeverShown(): void
    {
        // TS-11: indexed, but not yet published.
        $live = $this->indexedEntry('Garden live');
        $future = $this->indexedEntry('Garden future', new DateTime('+1 day'));
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$future->id, $live->id]);
        $this->rules()->push('content');

        $this->assertSame([(string)$live->id], $this->search('garden'));
    }

    public function testDatesArePushedAsUtcTimestamps(): void
    {
        $a = $this->indexedEntry('Hall A');
        $from = new DateTime('tomorrow 09:00', new \DateTimeZone('Europe/London'));
        $to = new DateTime('+1 week');
        $this->saveRule('hall', Rule::MATCH_EXACT, pins: [$a->id], from: $from, to: $to);

        $item = $this->rules()->desiredSet('content', Rules::KIND_RULES)['items'][0];
        $this->assertSame($from->getTimestamp(), $item['effective_from_ts']);
        $this->assertSame($to->getTimestamp(), $item['effective_to_ts']);
    }

    public function testOnlyEntriesFeedingTheCollectionCanBePinned(): void
    {
        $outside = $this->saveEntry('other', 'Garden elsewhere');
        $rule = new Rule(['collection' => 'content', 'query' => 'garden', 'pins' => [$outside->id]]);

        $this->assertFalse($this->rules()->saveRule($rule));
        $this->assertArrayHasKey('pins', $rule->getErrors());
        $this->assertSame(0, (int)RuleRecord::find()->count());
    }

    public function testADeletedPinIsSkippedWithOneLogLine(): void
    {
        // TN-5.
        $a = $this->indexedEntry('Garden A');
        $gone = $this->indexedEntry('Garden gone');
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$gone->id, $a->id]);
        $this->assertTrue(Craft::$app->getElements()->deleteElement($gone));

        $lines = $this->logged(function() use ($a) {
            $this->saveRule('hall', Rule::MATCH_EXACT, pins: [$a->id]);
            $this->assertNull($this->rules()->pushOrQueue('content'));
        });

        $this->assertSame([['rule-0001', (string)$a->id], ['rule-0002', (string)$a->id]], $this->pushedPins());
        $this->assertCount(1, $lines, implode("\n", $lines));
        $this->assertStringContainsString((string)$gone->id, $lines[0]);
    }

    public function testAnOverriddenDocumentIdIsWhatIsPinned(): void
    {
        // TN-9.
        $this->useSettings($this->settings(PrefixedIdFormatter::class));
        $a = $this->indexedEntry('Garden A');
        $b = $this->indexedEntry('Garden B');
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$b->id]);
        $this->rules()->push('content');

        $this->assertSame([['rule-0001', 'doc-' . $b->id]], $this->pushedPins());
        $this->assertSame(['doc-' . $b->id, 'doc-' . $a->id], $this->search('garden'));
    }

    public function testStateReportsMissingDriftedAndInStep(): void
    {
        $a = $this->indexedEntry('Garden A');
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$a->id]);

        $this->assertSame(Rules::STATE_NOT_LIVE, $this->rules()->state('content')[Rules::KIND_RULES]['state'], 'nothing pushed yet');

        $this->rules()->push('content');
        $state = $this->rules()->state('content');
        $this->assertSame(Rules::STATE_IN_STEP, $state[Rules::KIND_RULES]['state']);
        $this->assertSame(Rules::STATE_IN_STEP, $state[Rules::KIND_SYNONYMS]['state']);

        // Changed in the dashboard (BR-14).
        $this->admin->curationSets->upsert($this->prefix . 'content_rules', ['items' => [
            ['id' => 'rule-0001', 'rule' => ['query' => 'garden', 'match' => 'contains'], 'includes' => [['id' => (string)$a->id, 'position' => 1]]],
        ]]);
        $state = $this->rules()->state('content')[Rules::KIND_RULES];
        $this->assertSame(Rules::STATE_OUT_OF_STEP, $state['state']);
        $this->assertSame(1, $state['differs']);

        $this->rules()->push('content');
        $this->assertSame(Rules::STATE_IN_STEP, $this->rules()->state('content')[Rules::KIND_RULES]['state']);

        // Unlinked from the live version.
        $this->admin->collections[$this->prefix . 'content_1']->update(['curation_sets' => []]);
        $state = $this->rules()->state('content')[Rules::KIND_RULES];
        $this->assertSame(Rules::STATE_NOT_LIVE, $state['state']);
        $this->assertFalse($state['linked']);
    }

    public function testAFailedPushKeepsTheEditAndARetryMakesItLive(): void
    {
        // BR-4 (the integration half of TS-10).
        $a = $this->indexedEntry('Garden A');
        $this->useSettings($this->settings(values: ['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]));
        $this->saveRule('garden', Rule::MATCH_EXACT, pins: [$a->id]);

        $this->assertNotNull($this->rules()->pushOrQueue('content'));
        $this->assertSame(1, (int)RuleRecord::find()->count(), 'the row is kept');
        $this->assertTrue($this->rules()->isPending('content'));
        $this->assertSame(Rules::STATE_UNKNOWN, $this->rules()->state('content')[Rules::KIND_RULES]['state']);
        $this->assertSame(1, $this->countJobs(PushRules::class));

        $this->useSettings($this->settings());
        $this->runQueue();

        $this->assertFalse($this->rules()->isPending('content'));
        $this->assertSame(Rules::STATE_IN_STEP, $this->rules()->state('content')[Rules::KIND_RULES]['state']);
    }

    public function testDeletingALinkedSetUnlinksItFirstAndSearchStillAnswers(): void
    {
        // TN-8.
        $d = $this->indexedEntry('Nursing home');
        $group = new SynonymGroup(['collection' => 'content', 'words' => ['care home', 'nursing home']]);
        $this->assertTrue($this->rules()->saveSynonymGroup($group));
        $this->rules()->push('content');
        $name = $this->prefix . 'content_synonyms';

        $this->assertSame([$this->prefix . 'content_1'], $this->rules()->deleteSet(Rules::KIND_SYNONYMS, $name));

        $this->assertSame([(string)$d->id], $this->search('nursing home'), 'search still answers');
        $this->assertNull($this->rules()->liveSet(Rules::KIND_SYNONYMS, $name));
        $this->assertSame([], $this->admin->collections[$this->prefix . 'content_1']->retrieve()['synonym_sets']);
        $this->assertSame([], $this->rules()->deleteSet(Rules::KIND_SYNONYMS, $name), 'a set already gone is not an error');
    }

    /**
     * @param int[] $pins
     * @param int[] $hides
     */
    private function saveRule(string $query, string $match, array $pins = [], array $hides = [], ?DateTime $from = null, ?DateTime $to = null): Rule
    {
        $rule = new Rule([
            'collection' => 'content',
            'query' => $query,
            'matchType' => $match,
            'pins' => $pins,
            'hides' => $hides,
            'effectiveFrom' => $from,
            'effectiveTo' => $to,
        ]);
        $this->assertTrue($this->rules()->saveRule($rule), implode(' ', $rule->getFirstErrors()));

        return $rule;
    }

    /**
     * Each pushed rule's id and first pinned document.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pushedPins(): array
    {
        return array_map(
            fn(array $item) => [(string)$item['id'], (string)($item['includes'][0]['id'] ?? '')],
            $this->liveItems(Rules::KIND_RULES),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveItems(string $kind): array
    {
        $set = $this->rules()->liveSet($kind, $this->prefix . 'content_' . $kind);
        $this->assertNotNull($set, 'the set exists');

        return array_values((array)$set['items']);
    }

    /**
     * Document ids, in order, for a search with a scoped key, as a visitor would search.
     *
     * @return list<string>
     */
    private function search(string $q): array
    {
        $key = (string)$this->plugin->search->scopedKey('content');
        $client = $this->plugin->client->createClient($this->settings(), 0, $key);

        try {
            $result = $client->collections[$this->prefix . 'content']->documents->search([
                'q' => $q,
                'query_by' => 'title',
                'drop_tokens_threshold' => 0,
                'typo_tokens_threshold' => 0,
            ]);
        } catch (ObjectNotFound $e) {
            $this->fail('search failed: ' . $e->getMessage());
        }

        return array_map(fn(array $hit) => (string)$hit['document']['id'], (array)$result['hits']);
    }

    private function rules(): Rules
    {
        return $this->plugin->rules;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function settings(string $formatter = DocumentFormatter::class, array $values = []): Settings
    {
        return new Settings($values + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'searchApiKey' => $this->searchKey,
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => []],
            'sources' => isset($this->sections['news'])
                ? [['handle' => $this->sections['news']->handle, 'collection' => 'content', 'formatter' => $formatter]]
                : [],
        ]);
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

    private function saveEntry(string $section, string $title, ?DateTime $postDate = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->sections[$section]->id,
            'typeId' => $this->sections[$section]->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
            'postDate' => $postDate,
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }

    /**
     * A news entry whose document is in the collection, with the queue empty.
     */
    private function indexedEntry(string $title, ?DateTime $postDate = null): Entry
    {
        $entry = $this->saveEntry('news', $title, $postDate);
        $this->runQueue();

        return $entry;
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
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

    /**
     * The plugin's log lines written while running a callable.
     *
     * @return list<string>
     */
    private function logged(callable $run): array
    {
        // The harness's logger keeps only its last few lines, as text; this one keeps them all.
        $original = Craft::getLogger();
        $capture = new Logger(['flushInterval' => 0]);
        Craft::setLogger($capture);

        try {
            $run();
        } finally {
            Craft::setLogger($original);
        }

        return array_values(array_map(
            fn(array $message) => (string)$message[0],
            array_filter($capture->messages, fn(array $message) => $message[2] === TypesenseSync::HANDLE),
        ));
    }
}
