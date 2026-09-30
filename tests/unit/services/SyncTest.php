<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\events\DeleteDocumentEvent;
use webdna\typesensesync\events\IndexDocumentEvent;
use webdna\typesensesync\events\SyncEvent;
use webdna\typesensesync\jobs\DeleteElement;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\tests\fixtures\elements\TestEntry;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\RefusingFormatter;
use webdna\typesensesync\tests\fixtures\MockedClient;
use webdna\typesensesync\TypesenseSync;
use yii\base\Event;
use yii\log\Logger;

/**
 * Sync with Typesense mocked: queueing and its dedupe flag (BR-6, BR-9), a single sync's
 * outcomes (BR-8), failures that retry and failures that do not (BR-10, BR-12), counters (BR-14),
 * the stale-id calculation behind a prune (BR-13), and the three events (BR-27).
 */
class SyncTest extends Unit
{
    private TypesenseSync $plugin;

    private Client $originalClient;

    private MockedClient $http;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->originalClient = $this->plugin->client;
        $this->useSettings($this->settings());
        Craft::$app->getCache()->flush();
    }

    protected function _after(): void
    {
        $this->plugin->targets->setSettings(null);
        $this->plugin->set('client', $this->originalClient);
        foreach ([Sync::EVENT_BEFORE_INDEX_DOCUMENT, Sync::EVENT_AFTER_SYNC, Sync::EVENT_AFTER_DELETE_DOCUMENT] as $name) {
            Event::off(Sync::class, $name);
        }
    }

    // Queueing (BR-6, BR-9) ---------------------------------------------------------------------

    public function testQueueingADeclaredElementPushesOneSyncJob(): void
    {
        $this->assertTrue($this->sync()->queueElement($this->entry()));

        $jobs = $this->queuedJobs();
        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(SyncElement::class, $jobs[0]);
        $this->assertSame(42, $jobs[0]->elementId);
        $this->assertSame('content', $jobs[0]->collection);
        $this->assertSame('42', $jobs[0]->documentId);
    }

    public function testAQueueCallWhileASyncIsPendingIsDropped(): void
    {
        $entry = $this->entry();

        for ($save = 0; $save < 5; $save++) {
            $this->sync()->queueElement($entry);
        }

        $this->assertCount(1, $this->queuedJobs());
        $this->assertTrue($this->sync()->isPending(42, $this->primarySiteId()));
    }

    public function testTheFlagIsPerElement(): void
    {
        $this->sync()->queueElement($this->entry());
        $this->sync()->queueElement($this->entry(['id' => 43]));

        $this->assertCount(2, $this->queuedJobs());
    }

    public function testTheJobClearsTheFlagBeforeItLoadsTheElement(): void
    {
        $this->sync()->queueElement($this->entry());

        // Element 42 does not exist, and the job had no document to fall back on: nothing to do.
        $outcome = $this->sync()->syncById(42, $this->primarySiteId());

        $this->assertSame(Sync::OUTCOME_SKIPPED, $outcome);
        $this->assertFalse($this->sync()->isPending(42, $this->primarySiteId()));
        $this->assertTrue($this->sync()->queueElement($this->entry()), 'a save after the job started queues again (TN-13)');
    }

    public function testNothingIsQueuedForAnUndeclaredElement(): void
    {
        $this->assertFalse($this->sync()->queueElement($this->entry(['sectionHandle' => 'jobs'])));
        $this->assertFalse($this->sync()->queueDelete($this->entry(['sectionHandle' => 'jobs'])));
        $this->assertSame([], $this->queuedJobs());
    }

    public function testNothingIsQueuedForADraftAResaveOrPropagation(): void
    {
        $this->assertFalse($this->sync()->queueElement($this->entry(['resaving' => true])));
        $this->assertFalse($this->sync()->queueElement($this->entry(['propagating' => true])));
        $this->assertFalse($this->sync()->queueDelete($this->entry(['draftId' => 7])));
        $this->assertFalse($this->sync()->queueDelete($this->entry(['revisionId' => 7])));
        $this->assertSame([], $this->queuedJobs());
    }

    public function testNothingIsQueuedWhileUnconfigured(): void
    {
        $this->useSettings($this->settings([], [], ['host' => '']));

        $this->assertFalse($this->sync()->queueElement($this->entry()));
        $this->assertSame([], $this->queuedJobs());
    }

    public function testADeleteCapturesItsCollectionAndDocumentIdAtQueueTime(): void
    {
        $this->assertTrue($this->sync()->queueDelete($this->entry()));

        $jobs = $this->queuedJobs();
        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(DeleteElement::class, $jobs[0]);
        $this->assertSame('content', $jobs[0]->collection);
        $this->assertSame('42', $jobs[0]->documentId);
    }

    // A single sync (BR-8) ----------------------------------------------------------------------

    public function testALiveElementIsUpserted(): void
    {
        $this->respond(new Response(200, [], '{"success":true}'));

        $this->assertSame(Sync::OUTCOME_INDEXED, $this->sync()->syncElement($this->entry()));
        $this->assertSame(['POST /collections/test_content/documents/import'], $this->paths());
        $this->assertStringContainsString('action=upsert', (string)$this->http->history[0]['request']->getUri()->getQuery());

        $document = json_decode((string)$this->http->history[0]['request']->getBody(), true);
        $this->assertSame('42', $document['id']);
        $this->assertSame('Hello', $document['title']);
    }

    public function testADisabledElementHasItsDocumentDeleted(): void
    {
        $this->respond(new Response(200, [], '{"id":"42"}'));

        $this->assertSame(Sync::OUTCOME_REMOVED, $this->sync()->syncElement($this->entry(['enabled' => false])));
        $this->assertSame(['DELETE /collections/test_content/documents/42'], $this->paths());
    }

    public function testAnElementTheFormatterRefusesHasItsDocumentDeleted(): void
    {
        $this->useSettings($this->settings([], ['formatter' => RefusingFormatter::class]));
        $this->respond(new Response(200, [], '{"id":"42"}'));

        $this->assertSame(Sync::OUTCOME_REMOVED, $this->sync()->syncElement($this->entry()));
        $this->assertSame(['DELETE /collections/test_content/documents/42'], $this->paths());
    }

    public function testDeletingADocumentThatIsNotThereIsNotAnError(): void
    {
        $this->respond(new Response(404, [], '{"message":"Could not find a document with id: 42"}'));

        $this->sync()->deleteDocument('42', 'content');
        $this->assertCount(1, $this->http->history);
    }

    public function testAnElementGoneBeforeItsJobRunsHasItsQueuedDocumentDeleted(): void
    {
        $this->respond(new Response(200, [], '{"id":"42"}'));

        $outcome = $this->sync()->syncById(999999, $this->primarySiteId(), 'content', '999999');

        $this->assertSame(Sync::OUTCOME_REMOVED, $outcome);
        $this->assertSame(['DELETE /collections/test_content/documents/999999'], $this->paths());
    }

    public function testAnUndeclaredElementIsLeftAlone(): void
    {
        $this->assertSame(Sync::OUTCOME_SKIPPED, $this->sync()->syncElement($this->entry(['sectionHandle' => 'jobs'])));
        $this->assertSame([], $this->http->history);
    }

    // Failures (BR-10, BR-12) -------------------------------------------------------------------

    public function testAnUnreachableServerThrowsNamingTheDocument(): void
    {
        $this->respond(new ConnectException('Connection refused', new Request('POST', '/')));

        $logged = $this->logged(function() {
            try {
                $this->sync()->syncElement($this->entry());
                $this->fail('No exception was thrown.');
            } catch (SyncException $e) {
                $this->assertStringContainsString('document 42', $e->getMessage());
                $this->assertStringContainsString('Connection refused', $e->getMessage());
            }
        });

        $this->assertCount(1, $logged, 'TN-1: logged once, under the plugin category');
        $this->assertStringContainsString('document 42', $logged[0]);
    }

    public function testTheElementJobsRetryAndGiveUpAfterTheirAttempts(): void
    {
        foreach ([new SyncElement(), new DeleteElement()] as $job) {
            $this->assertTrue($job->canRetry(1, new SyncException()));
            $this->assertTrue($job->canRetry(2, new SyncException()));
            $this->assertFalse($job->canRetry(3, new SyncException()));
        }
    }

    public function testARejectedLineIsReportedAndTheOthersKept(): void
    {
        $this->respond(new Response(200, [], implode("\n", [
            '{"success":true}',
            '{"success":false,"error":"Field `priority` must be an int32.","document":"{}"}',
            '{"success":true}',
        ])));

        $result = null;
        $logged = $this->logged(function() use (&$result) {
            $result = $this->sync()->import('content', [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);
        });

        $this->assertSame(2, $result['written']);
        $this->assertSame(['b' => 'Field `priority` must be an int32.'], $result['rejected']);
        $this->assertCount(1, $logged, 'TN-7: the rejected line is logged');
        $this->assertStringContainsString('document b', $logged[0]);
    }

    public function testARejectedSingleDocumentIsNotRetried(): void
    {
        $this->respond(new Response(200, [], '{"success":false,"error":"Bad field."}'));

        $this->assertSame(Sync::OUTCOME_REJECTED, $this->sync()->syncElement($this->entry()));
    }

    // Counters (BR-14) --------------------------------------------------------------------------

    public function testCountersAreCarriedThroughAWriteAndSeededForNewDocuments(): void
    {
        $this->useSettings($this->settings(['counters' => ['popularity']]));
        $this->respond(
            new Response(200, [], '{"id":"a","popularity":7}'),
            new Response(200, [], "{\"success\":true}\n{\"success\":true}"),
        );

        $this->sync()->import('content', [['id' => 'a', 'popularity' => 0], ['id' => 'b']]);

        $this->assertSame([
            'GET /collections/test_content/documents/export',
            'POST /collections/test_content/documents/import',
        ], $this->paths());
        parse_str($this->http->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('id:[`a`,`b`]', $query['filter_by']);
        $this->assertSame('id,popularity', $query['include_fields']);

        $lines = array_map(fn(string $line) => json_decode($line, true), explode("\n", (string)$this->http->history[1]['request']->getBody()));
        $this->assertSame(7, $lines[0]['popularity']);
        $this->assertSame(0, $lines[1]['popularity']);
    }

    public function testABatchIsNotWrittenWhenItsCountersCannotBeRead(): void
    {
        $this->useSettings($this->settings(['counters' => ['popularity']]));
        $this->respond(new Response(503, [], '{"message":"Not Ready or Lagging"}'));

        try {
            $this->sync()->import('content', [['id' => 'a']]);
            $this->fail('No exception was thrown.');
        } catch (SyncException $e) {
            $this->assertStringContainsString('counter fields could not be read', $e->getMessage());
        }

        $this->assertSame(['GET /collections/test_content/documents/export'], $this->paths());
    }

    public function testAFirstWriteIntoAMissingCollectionSeedsZero(): void
    {
        $this->useSettings($this->settings(['counters' => ['popularity']]));

        $this->respond(new Response(404, [], '{"message":"Not Found"}'));

        $this->assertSame([], $this->sync()->fetchCounters('test_content', ['a'], ['popularity']));
    }

    // Prune (BR-13) -----------------------------------------------------------------------------

    public function testStaleIdsAreThoseNotBuilt(): void
    {
        $export = "{\"id\":\"1\"}\n{\"id\":\"2\"}\n{\"id\":\"3\"}\n";

        $this->assertSame(['2'], Sync::staleIds($export, [1, '3']));
        $this->assertSame([], Sync::staleIds('', ['1']));
        $this->assertSame(['1', '2', '3'], Sync::staleIds($export, []));
    }

    // Events (BR-27) ----------------------------------------------------------------------------

    public function testABeforeIndexHandlerCanChangeTheDocumentButNotItsId(): void
    {
        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, function(IndexDocumentEvent $event) {
            $event->document['title'] = 'Changed';
            $event->document['id'] = 'elsewhere';
        });
        $this->respond(new Response(200, [], '{"success":true}'));

        $this->sync()->syncElement($this->entry());

        $document = json_decode((string)$this->http->history[0]['request']->getBody(), true);
        $this->assertSame('Changed', $document['title']);
        $this->assertSame('42', $document['id']);
    }

    public function testACancelledDocumentIsDeletedInstead(): void
    {
        Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, fn(IndexDocumentEvent $event) => $event->isValid = false);
        $this->respond(new Response(200, [], '{"id":"42"}'));

        $this->assertSame(Sync::OUTCOME_REMOVED, $this->sync()->syncElement($this->entry()));
        $this->assertSame(['DELETE /collections/test_content/documents/42'], $this->paths());
    }

    public function testAfterSyncAndAfterDeleteAreRaised(): void
    {
        $seen = [];
        Event::on(Sync::class, Sync::EVENT_AFTER_SYNC, function(SyncEvent $event) use (&$seen) {
            $seen[] = 'sync:' . $event->outcome;
        });
        Event::on(Sync::class, Sync::EVENT_AFTER_DELETE_DOCUMENT, function(DeleteDocumentEvent $event) use (&$seen) {
            $seen[] = 'delete:' . $event->collection . '/' . $event->documentId;
        });
        $this->respond(new Response(200, [], '{"success":true}'), new Response(200, [], '{"id":"42"}'));

        $this->sync()->syncElement($this->entry());
        $this->sync()->syncElement($this->entry(['enabled' => false]));

        $this->assertSame(['sync:indexed', 'delete:content/42', 'sync:removed'], $seen);
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * The messages logged under the plugin's category (the test hook) while $run ran.
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

    private function sync(): Sync
    {
        return $this->plugin->sync;
    }

    /**
     * @param array<string, mixed> $collection Keys merged into the `content` collection.
     * @param array<string, mixed> $source Keys merged into the `news` source.
     * @param array<string, mixed> $values
     */
    private function settings(array $collection = [], array $source = [], array $values = []): Settings
    {
        return new Settings($values + [
            'host' => 'typesense.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => 'admin-key',
            'collectionPrefix' => 'test_',
            'collections' => ['content' => $collection],
            'sources' => [$source + ['handle' => 'news', 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
        ]);
    }

    /**
     * Resolve against these settings and answer HTTP from a fresh queue.
     */
    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->http = new MockedClient();
        $this->http->setClient($settings->isConfigured() ? $this->http->createClient($settings, 0) : null);
        $this->plugin->set('client', $this->http);
    }

    private function respond(Response|\Throwable ...$responses): void
    {
        foreach ($responses as $response) {
            $this->http->handler->append($response);
        }
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
     * @param array<string, mixed> $values
     */
    private function entry(array $values = []): TestEntry
    {
        return new TestEntry($values + [
            'id' => 42,
            'title' => 'Hello',
            'siteId' => $this->primarySiteId(),
            'enabled' => true,
        ]);
    }

    private function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * @return list<object>
     */
    private function queuedJobs(): array
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->orderBy(['id' => SORT_ASC])->column();

        return array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );
    }
}
