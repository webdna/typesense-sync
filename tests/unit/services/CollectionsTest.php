<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use GuzzleHttp\Psr7\Response;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\tests\fixtures\formatters\BackReferencingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ConflictingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\EventsFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NewsFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ReferencingFormatter;
use webdna\typesensesync\tests\fixtures\MockedClient;
use webdna\typesensesync\TypesenseSync;
use yii\base\InvalidConfigException;

/**
 * Collections with Typesense mocked: the desired schema, normalising, the diff, and what apply
 * decides and sends (BR-3, BR-16).
 */
class CollectionsTest extends Unit
{
    private TypesenseSync $plugin;

    private Client $originalClient;

    private MockedClient $http;

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

    // Desired schema ----------------------------------------------------------------------------

    public function testTheDesiredSchemaIsTheUnionOfTheFormattersSortedByName(): void
    {
        $this->assertSame(['startDate', 'title', 'url'], $this->names($this->collections()->getDesiredFields('content')));
    }

    public function testConfigExtrasAreAddedAndWinOverAFormatter(): void
    {
        $this->useSettings($this->settings(['schema' => [
            ['name' => 'popularity', 'type' => 'int32', 'optional' => true],
            ['name' => 'title', 'type' => 'string', 'sort' => true],
        ]]));

        $fields = $this->byName($this->collections()->getDesiredFields('content'));

        $this->assertSame(['popularity', 'startDate', 'title', 'url'], array_keys($fields));
        $this->assertSame(['name' => 'title', 'type' => 'string', 'sort' => true], $fields['title']);
    }

    public function testACollectionNothingRoutesIntoHasNoFieldsEvenWithExtras(): void
    {
        $this->useSettings($this->settings(
            ['schema' => [['name' => 'popularity', 'type' => 'int32']]],
            [['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class, 'enabled' => false]],
        ));

        $this->assertSame([], $this->collections()->getDesiredFields('content'));
    }

    public function testTwoFormattersDeclaringAFieldDifferentlyThrow(): void
    {
        $this->useSettings($this->settings(sources: [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class],
            ['handle' => 'events', 'collection' => 'content', 'formatter' => ConflictingFormatter::class],
        ]));

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('declare field "title" differently');
        $this->collections()->getDesiredFields('content');
    }

    public function testAReferenceResolvesToTheJoinedCollectionsLiveName(): void
    {
        $this->useSettings(new Settings([
            'host' => 'typesense.test',
            'apiKey' => 'admin-key',
            'collectionPrefix' => 'test_',
            'collections' => ['content' => [], 'people' => []],
            'sources' => [['handle' => 'news', 'collection' => 'content', 'formatter' => ReferencingFormatter::class]],
        ]));

        $this->assertSame('test_people.id', $this->collections()->getDesiredFields('content')[0]['reference']);
    }

    public function testAnUndeclaredCollectionThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->collections()->getDesiredFields('nowhere');
    }

    // Normalising -------------------------------------------------------------------------------

    public function testASparseDeclarationAndTheRetrievedFieldCompareEqual(): void
    {
        // As Typesense 30.0 retrieves a field declared as ['name' => 'n', 'type' => 'int32'].
        $retrieved = [
            'facet' => false, 'index' => true, 'infix' => false, 'locale' => '', 'name' => 'n', 'optional' => false,
            'sort' => true, 'stem' => false, 'stem_dictionary' => '', 'store' => true, 'truncate_len' => 100, 'type' => 'int32',
        ];

        $this->assertSame(Collections::normalise(['name' => 'n', 'type' => 'int32']), Collections::normalise($retrieved));
        $this->assertFalse(Collections::normalise(['name' => 's', 'type' => 'string'])['sort'], 'a string is not sortable by default');
        $this->assertTrue(Collections::normalise(['name' => 'g', 'type' => 'geopoint'])['sort']);
    }

    public function testReferenceAttributesAreComparedOnlyOnAReference(): void
    {
        $this->assertArrayNotHasKey('cascade_delete', Collections::normalise(['name' => 's', 'type' => 'string', 'cascade_delete' => false]));

        $reference = Collections::normalise(['name' => 'a', 'type' => 'string', 'reference' => 'people.id']);
        $this->assertSame('people.id', $reference['reference']);
        $this->assertFalse($reference['async_reference']);
        $this->assertTrue($reference['cascade_delete'], 'Typesense cascades unless told not to');
    }

    // Diff --------------------------------------------------------------------------------------

    public function testNoAliasDiffsAsAbsentWithEveryFieldAdded(): void
    {
        $this->respond($this->notFound());

        $diff = $this->collections()->diff('content');

        $this->assertFalse($diff['exists']);
        $this->assertFalse($diff['upToDate']);
        $this->assertSame(['startDate', 'title', 'url'], $diff['added']);
        $this->assertSame(['GET /aliases/test_content'], $this->paths());
    }

    public function testALiveCollectionAsTypesenseReportsItIsUpToDate(): void
    {
        $this->respondLive($this->liveFields(), ['num_documents' => 7]);

        $diff = $this->collections()->diff('content');

        $this->assertTrue($diff['exists']);
        $this->assertTrue($diff['upToDate'], 'no phantom changes from Typesense filling in defaults');
        $this->assertSame('test_content_3', $diff['collection']);
        $this->assertSame(7, $diff['documents']);
        $this->assertSame(['GET /aliases/test_content', 'GET /collections/test_content_3'], $this->paths());
    }

    public function testAddedDroppedAndChangedFieldsAreListed(): void
    {
        $live = $this->liveFields();
        unset($live['startDate']);
        $live['url']['index'] = true;
        $live['legacy'] = $this->retrieved('legacy', 'string');
        $live['address.city'] = $this->retrieved('address.city', 'string');
        $this->respondLive($live);

        $diff = $this->collections()->diff('content');

        $this->assertSame(['startDate'], $diff['added']);
        $this->assertSame(['legacy'], $diff['dropped'], 'a flattened nested field is not reported');
        $this->assertSame(['url'], array_keys($diff['changed']));
        $this->assertTrue($diff['changed']['url']['from']['index']);
        $this->assertFalse($diff['changed']['url']['to']['index']);
        $this->assertFalse($diff['needsRecreate']);
        $this->assertFalse($diff['upToDate']);
    }

    public function testASortingFieldOrNestingChangeNeedsARecreate(): void
    {
        $this->useSettings($this->settings(['defaultSortingField' => 'startDate', 'enableNestedFields' => false]));
        $this->respondLive($this->liveFields());

        $diff = $this->collections()->diff('content');

        $this->assertTrue($diff['needsRecreate']);
        $this->assertCount(2, $diff['recreateReasons']);
        $this->assertStringContainsString('"(none)" and should be "startDate"', $diff['recreateReasons'][0]);
        $this->assertFalse($diff['upToDate']);
    }

    public function testAnAliasThatCannotBeReadThrowsRatherThanReadingAsAbsent(): void
    {
        $this->respond(new Response(500, [], '{"message":"Internal error"}'));

        $this->expectException(SyncException::class);
        $this->collections()->diff('content');
    }

    // Apply -------------------------------------------------------------------------------------

    public function testApplyCreatesTheNextVersionAndPointsTheAliasAtIt(): void
    {
        $this->useSettings($this->settings(['defaultSortingField' => 'startDate']));
        // A version left behind by a failed run is never reused.
        $this->respond($this->notFound(), $this->json([['name' => 'test_content_1'], ['name' => 'other_1']]), $this->json(['name' => 'test_content_2']), $this->json(['name' => 'test_content']));

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_CREATE, $result['action']);
        $this->assertSame('test_content_2', $result['collection']);
        $this->assertTrue($result['reindex']);
        $this->assertSame(['GET /aliases/test_content', 'GET /collections', 'POST /collections', 'PUT /aliases/test_content'], $this->paths());

        $created = $this->sent(2);
        $this->assertSame('test_content_2', $created['name']);
        $this->assertSame('startDate', $created['default_sorting_field']);
        $this->assertTrue($created['enable_nested_fields'], 'on unless the collection says otherwise');
        $this->assertSame(['startDate', 'title', 'url'], $this->names($created['fields']));
        $this->assertSame(['collection_name' => 'test_content_2'], $this->sent(3));
    }

    public function testADryRunCreateSendsNothing(): void
    {
        $this->respond($this->notFound(), $this->json([]));

        $result = $this->collections()->apply('content', true);

        $this->assertSame(Collections::ACTION_CREATE, $result['action']);
        $this->assertSame('test_content_1', $result['collection']);
        $this->assertStringStartsWith('Would create', $result['message']);
        $this->assertSame(['GET /aliases/test_content', 'GET /collections'], $this->paths());
    }

    public function testApplyRefusesWhenTheLiveNameIsARealCollection(): void
    {
        $this->respond($this->notFound(), $this->json([['name' => 'test_content']]));

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_BLOCKED, $result['action']);
        $this->assertSame(['GET /aliases/test_content', 'GET /collections'], $this->paths());
    }

    public function testApplyAltersInPlaceDroppingAndReAddingAChangedField(): void
    {
        $live = $this->liveFields();
        unset($live['startDate']);
        $live['url']['index'] = true;
        $live['legacy'] = $this->retrieved('legacy', 'string');
        $this->respondLive($live);
        $this->respond($this->json(['fields' => []]));

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_ALTER, $result['action']);
        $this->assertTrue($result['reindex']);
        $this->assertStringContainsString('+1, -1, ~1', $result['message']);
        $this->assertSame('PATCH /collections/test_content_3', $this->paths()[2]);
        $this->assertSame(['fields' => [
            ['name' => 'legacy', 'drop' => true],
            ['name' => 'url', 'drop' => true],
            ['name' => 'startDate', 'type' => 'int64'],
            ['name' => 'url', 'type' => 'string', 'index' => false],
        ]], $this->sent(2));
    }

    public function testDroppingAFieldAloneNeedsNoReindex(): void
    {
        $live = $this->liveFields();
        $live['legacy'] = $this->retrieved('legacy', 'string');
        $this->respondLive($live);
        $this->respond($this->json(['fields' => []]));

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_ALTER, $result['action']);
        $this->assertFalse($result['reindex']);
    }

    public function testADryRunAlterSendsNothing(): void
    {
        $live = $this->liveFields();
        unset($live['startDate']);
        $this->respondLive($live);

        $result = $this->collections()->apply('content', true);

        $this->assertSame(Collections::ACTION_ALTER, $result['action']);
        $this->assertSame([['name' => 'startDate', 'type' => 'int64']], $result['payload']['fields'] ?? null);
        $this->assertCount(2, $this->http->history);
    }

    public function testAnUpToDateCollectionIsLeftAlone(): void
    {
        $this->respondLive($this->liveFields());

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_NONE, $result['action']);
        $this->assertFalse($result['reindex']);
        $this->assertCount(2, $this->http->history);
    }

    public function testADifferenceAnAlterCannotMakeIsRefusedWithTheReason(): void
    {
        $this->useSettings($this->settings(['defaultSortingField' => 'startDate']));
        $this->respondLive($this->liveFields());

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_NEEDS_RECREATE, $result['action']);
        $this->assertStringContainsString('default_sorting_field', $result['message']);
        $this->assertCount(2, $this->http->history, 'nothing altered');
    }

    public function testACollectionNothingRoutesIntoIsSkippedWithoutAsking(): void
    {
        $this->useSettings($this->settings(sources: [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class, 'enabled' => false],
        ]));

        $result = $this->collections()->apply('content');

        $this->assertSame(Collections::ACTION_SKIP, $result['action']);
        $this->assertSame([], $this->http->history);
    }

    // Recreate order (BR-15) --------------------------------------------------------------------

    public function testDependantsAreEveryCollectionJoiningInDirectlyOrNotInReferenceOrder(): void
    {
        // digest → posts → people: a rebuild of people must rebuild posts, then digest.
        $this->useSettings($this->joinedSettings([
            ['handle' => 'digest', 'collection' => 'digest', 'formatter' => BackReferencingFormatter::class],
            ['handle' => 'posts', 'collection' => 'posts', 'formatter' => ReferencingFormatter::class],
            ['handle' => 'staff', 'collection' => 'people', 'formatter' => NewsFormatter::class],
        ]));

        $this->assertSame(['posts'], $this->collections()->referencingCollections('people'));
        $this->assertSame(['posts', 'digest'], $this->collections()->getDependants('people'));
        $this->assertSame(['digest'], $this->collections()->getDependants('posts'));
        $this->assertSame([], $this->collections()->getDependants('digest'));
        $this->assertSame([], $this->http->history, 'worked out from the config alone');
    }

    public function testRecreateRefusesAReferenceCycleBeforeTouchingTheServer(): void
    {
        // TS-7 step 3: people joins back into posts.
        $this->useSettings($this->joinedSettings([
            ['handle' => 'posts', 'collection' => 'posts', 'formatter' => ReferencingFormatter::class],
            ['handle' => 'staff', 'collection' => 'people', 'formatter' => BackReferencingFormatter::class],
        ]));

        try {
            $this->collections()->recreate('people');
            $this->fail('a cycle was recreated');
        } catch (InvalidConfigException $e) {
            $this->assertStringContainsString('cycle', $e->getMessage());
        }

        $this->assertSame([], $this->http->history);
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function joinedSettings(array $sources): Settings
    {
        return new Settings([
            'host' => 'typesense.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => 'admin-key',
            'collectionPrefix' => 'test_',
            'collections' => ['people' => [], 'posts' => [], 'digest' => []],
            'sources' => $sources,
        ]);
    }

    /**
     * @param array<string, mixed> $collection
     * @param list<array<string, mixed>>|null $sources
     */
    private function settings(array $collection = [], ?array $sources = null): Settings
    {
        return new Settings([
            'host' => 'typesense.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => 'admin-key',
            'collectionPrefix' => 'test_',
            'collections' => ['content' => $collection],
            'sources' => $sources ?? [
                ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class],
                ['handle' => 'events', 'collection' => 'content', 'formatter' => EventsFormatter::class],
            ],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->http = new MockedClient();
        $this->http->setClient($this->http->createClient($settings, 0));
        $this->plugin->set('client', $this->http);
    }

    private function collections(): Collections
    {
        return $this->plugin->collections;
    }

    /**
     * The desired fields as Typesense 30.0 retrieves them, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function liveFields(): array
    {
        return [
            'startDate' => $this->retrieved('startDate', 'int64'),
            'title' => $this->retrieved('title', 'string'),
            'url' => ['index' => false] + $this->retrieved('url', 'string'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieved(string $name, string $type): array
    {
        return [
            'facet' => false, 'index' => true, 'infix' => false, 'locale' => '', 'name' => $name, 'optional' => false,
            'sort' => in_array($type, ['int32', 'int64', 'float', 'bool', 'geopoint'], true),
            'stem' => false, 'stem_dictionary' => '', 'store' => true, 'truncate_len' => 100, 'type' => $type,
        ];
    }

    /**
     * Answer the alias and the live collection it points at.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $extra
     */
    private function respondLive(array $fields, array $extra = []): void
    {
        $this->respond(
            $this->json(['name' => 'test_content', 'collection_name' => 'test_content_3']),
            $this->json($extra + [
                'name' => 'test_content_3',
                'default_sorting_field' => '',
                'enable_nested_fields' => true,
                'fields' => array_values($fields),
                'num_documents' => 0,
            ]),
        );
    }

    private function respond(Response ...$responses): void
    {
        foreach ($responses as $response) {
            $this->http->handler->append($response);
        }
    }

    private function json(mixed $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($body));
    }

    private function notFound(): Response
    {
        return new Response(404, [], '{"message":"Not found."}');
    }

    /**
     * The JSON body of the nth request.
     *
     * @return array<string, mixed>
     */
    private function sent(int $index): array
    {
        return json_decode((string)$this->http->history[$index]['request']->getBody(), true);
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_map(
            fn(array $entry) => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
            $this->http->history,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return list<string>
     */
    private function names(array $fields): array
    {
        return array_values(array_map(static fn(array $field) => (string)$field['name'], $fields));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $fields): array
    {
        return array_column($fields, null, 'name');
    }
}
