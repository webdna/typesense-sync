<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\tests\fixtures\MockedClient;

/**
 * The client service with HTTP mocked: bounded timeouts, the version gate (BR-24), the
 * search-only key check (BR-19), and failures reported rather than thrown (BR-10).
 */
class ClientTest extends Unit
{
    private const ADMIN_KEY = 'admin-key-1234';
    private const SEARCH_KEY = 'srch-key-5678';

    // Version gate ----------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versions(): array
    {
        return [
            'minimum' => ['30.0', true],
            'patch' => ['30.2', true],
            'next major' => ['31.0', true],
            'v prefix' => ['v30.1', true],
            'older major' => ['29.0', false],
            'pre-27 numbering' => ['0.25.2', false],
            'nightly' => ['nightly', false],
            'unknown' => ['', false],
        ];
    }

    /**
     * @dataProvider versions
     */
    public function testVersionGate(string $version, bool $supported): void
    {
        $problem = Client::versionProblem($version);

        if ($supported) {
            $this->assertNull($problem);
        } else {
            $this->assertNotNull($problem);
            $this->assertStringContainsString('30.0 or later', $problem);
            if ($version !== '') {
                $this->assertStringContainsString($version, $problem, 'The refusal names the version found');
            }
        }
    }

    // The client ------------------------------------------------------------------------------

    public function testClientIsBoundedByTheConfiguredTimeouts(): void
    {
        $service = new MockedClient();
        $service->createClient($this->settings(['connectTimeout' => 3, 'timeout' => 7]));

        $this->assertSame([['connect_timeout' => 3, 'timeout' => 7]], $service->guzzleConfigs);
    }

    public function testNoClientWithoutAServerAndKey(): void
    {
        $service = new MockedClient();
        $service->setClient(null);

        $this->assertNull($service->getClient(), 'The test site has no connection configured');
        $this->assertSame([], $service->guzzleConfigs);
    }

    // Connection test -------------------------------------------------------------------------

    public function testUnconfiguredSettingsAreRefusedWithoutARequest(): void
    {
        $service = new MockedClient();
        $result = $service->testConnection(new Settings());

        $this->assertFalse($result['ok']);
        $this->assertSame(['Enter a host and an admin API key.'], $result['problems']);
        $this->assertSame([], $service->history);
    }

    public function testAZeroTimeoutIsRefusedNotTried(): void
    {
        $service = new MockedClient();
        $result = $service->testConnection($this->settings(['timeout' => 0]));

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['problems']);
        $this->assertStringContainsString('Request timeout', $result['problems'][0]);
        $this->assertSame([], $service->history);
    }

    public function testConnectedReportsTheVersion(): void
    {
        $service = new MockedClient([$this->json(['version' => '30.2'])]);
        $result = $service->testConnection($this->settings());

        $this->assertSame(['ok' => true, 'version' => '30.2', 'problems' => []], $result);
        $this->assertSame(['GET /debug [' . self::ADMIN_KEY . ']'], $service->requests());
    }

    public function testAWrongAdminKeyNamesAuthentication(): void
    {
        $service = new MockedClient([$this->json(['message' => 'Forbidden'], 401)]);
        $result = $service->testConnection($this->settings());

        $this->assertFalse($result['ok']);
        $this->assertNull($result['version']);
        $this->assertSame(['Authentication failed: the server refused the admin API key.'], $result['problems']);
    }

    public function testAnOldServerIsRefusedWithItsVersion(): void
    {
        $service = new MockedClient([$this->json(['version' => '29.1'])]);
        $result = $service->testConnection($this->settings());

        $this->assertFalse($result['ok']);
        $this->assertSame('29.1', $result['version']);
        $this->assertSame(['Typesense 29.1 found; version 30.0 or later is required.'], $result['problems']);
    }

    public function testAnUnreachableServerIsAProblemNotAnException(): void
    {
        $service = new MockedClient([
            new ConnectException('Connection refused', new Request('GET', 'http://ts.test:8108/debug')),
        ]);
        $result = $service->testConnection($this->settings());

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['problems']);
        $this->assertStringStartsWith('Could not reach http://ts.test:8108: ', $result['problems'][0]);
        $this->assertCount(1, $service->history, 'The test makes one attempt, never the configured retries');
    }

    // Search-only key (BR-19) -----------------------------------------------------------------

    public function testTheAdminKeyInTheSearchFieldIsRefusedWithoutAProbe(): void
    {
        $service = new MockedClient([$this->json(['version' => '30.2'])]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::ADMIN_KEY]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allows more than search', $result['problems'][0]);
        $this->assertCount(1, $service->history);
    }

    public function testASearchKeyThatCanListCollectionsIsRefused(): void
    {
        // The server's bootstrap key is not in /keys, so only the probe can catch it.
        $service = new MockedClient([$this->json(['version' => '30.2']), $this->json([])]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::SEARCH_KEY]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allows more than search', $result['problems'][0]);
        $this->assertSame('GET /collections [' . self::SEARCH_KEY . ']', $service->requests()[1]);
    }

    public function testASearchOnlyKeyPasses(): void
    {
        $service = $this->withKeys([
            ['value_prefix' => 'srch', 'actions' => ['documents:search'], 'collections' => ['*']],
            ['value_prefix' => 'othr', 'actions' => ['*'], 'collections' => ['*']],
        ]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::SEARCH_KEY]));

        $this->assertSame(['ok' => true, 'version' => '30.2', 'problems' => []], $result);
        $this->assertSame([
            'GET /debug [' . self::ADMIN_KEY . ']',
            'GET /collections [' . self::SEARCH_KEY . ']',
            'GET /keys [' . self::ADMIN_KEY . ']',
        ], $service->requests());
    }

    public function testASearchKeyWithAnyOtherActionIsRefused(): void
    {
        $service = $this->withKeys([
            ['value_prefix' => 'srch', 'actions' => ['documents:search', 'documents:get']],
        ]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::SEARCH_KEY]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allows more than search', $result['problems'][0]);
    }

    public function testASharedPrefixIsRefusedUnlessEveryMatchIsSearchOnly(): void
    {
        $service = $this->withKeys([
            ['value_prefix' => 'srch', 'actions' => ['documents:search']],
            ['value_prefix' => 'srch', 'actions' => ['documents:*']],
        ]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::SEARCH_KEY]));

        $this->assertFalse($result['ok']);
    }

    public function testASearchKeyTheServerDoesNotHoldIsRefused(): void
    {
        $service = $this->withKeys([['value_prefix' => 'othr', 'actions' => ['documents:search']]]);
        $result = $service->testConnection($this->settings(['searchApiKey' => self::SEARCH_KEY]));

        $this->assertSame(['The search-only API key is not a key on this server.'], $result['problems']);
    }

    // Form values -----------------------------------------------------------------------------

    public function testFormValuesApplyToACopy(): void
    {
        $saved = $this->settings();
        $tested = $saved->withConnection([
            'host' => ' other.test ',
            'apiKey' => '',
            'timeout' => '9',
            'connectTimeout' => 'soon',
            'batchSize' => '1',
            'collections' => ['x' => []],
        ]);

        $this->assertNotSame($saved, $tested);
        $this->assertSame('ts.test', $saved->host);
        $this->assertSame('other.test', $tested->host);
        $this->assertSame(self::ADMIN_KEY, $tested->apiKey, 'A blank admin key keeps the saved one');
        $this->assertSame(9, $tested->timeout);
        $this->assertSame(0, $tested->connectTimeout, 'Not a number: left for validation to refuse');
        $this->assertSame(100, $tested->batchSize, 'Not a connection setting');
        $this->assertSame([], $tested->collections);
    }

    public function testAConfigFileValueWinsOverTheForm(): void
    {
        $tested = $this->settings()->withConnection(['host' => 'form.test', 'port' => '9000'], ['host']);

        $this->assertSame('ts.test', $tested->host);
        $this->assertSame('9000', $tested->port);
    }

    // Helpers ---------------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function withKeys(array $keys): MockedClient
    {
        return new MockedClient([
            $this->json(['version' => '30.2']),
            $this->json(['message' => 'Forbidden - a valid `x-typesense-api-key` header must be sent.'], 401),
            $this->json(['keys' => $keys]),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values = []): Settings
    {
        return new Settings($values + [
            'host' => 'ts.test',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => self::ADMIN_KEY,
        ]);
    }

    /**
     * @param array<mixed> $body
     */
    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($body));
    }
}
