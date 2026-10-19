<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;

/**
 * TS-1 against the real server: connected with its version, a wrong admin key named as an
 * authentication failure, and the search-only key checked on the server (BR-19, BR-24).
 */
class ConnectionTest extends Unit
{
    private Client $service;

    /**
     * Keys this test created, deleted afterwards.
     *
     * @var list<int>
     */
    private array $createdKeys = [];

    protected function _before(): void
    {
        $this->service = new Client();

        // The container accepts connections a moment before it answers.
        $health = $this->service->createClient($this->settings(), 0)->getHealth();
        for ($attempt = 1; ; $attempt++) {
            try {
                $health->retrieve();
                break;
            } catch (\Throwable $e) {
                if ($attempt >= 20) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }

    protected function _after(): void
    {
        $admin = $this->service->createClient($this->settings());
        foreach ($this->createdKeys as $id) {
            $admin->getKeys()[$id]->delete();
        }
    }

    public function testConnectedWithTheServerVersion(): void
    {
        $result = $this->service->testConnection($this->settings());

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
        $this->assertNotNull($result['version']);
        $this->assertTrue(version_compare($result['version'], Client::MIN_SERVER_VERSION, '>='));
    }

    public function testAWrongAdminKeyNamesAuthentication(): void
    {
        $result = $this->service->testConnection($this->settings(['apiKey' => 'not-the-admin-key']));

        $this->assertFalse($result['ok']);
        $this->assertSame(['Authentication failed: the server refused the admin API key.'], $result['problems']);
    }

    public function testTheAdminKeyAsTheSearchKeyIsRefused(): void
    {
        $result = $this->service->testConnection($this->settings(['searchApiKey' => $this->adminKey()]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allows more than search', $result['problems'][0]);
    }

    public function testASearchOnlyKeyPasses(): void
    {
        $key = $this->createKey(['documents:search']);
        $result = $this->service->testConnection($this->settings(['searchApiKey' => $key]));

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
    }

    public function testAKeyThatCanReadDocumentsIsRefused(): void
    {
        // Cannot list collections, so only the /keys check catches it.
        $key = $this->createKey(['documents:search', 'documents:get']);
        $result = $this->service->testConnection($this->settings(['searchApiKey' => $key]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('allows more than search', $result['problems'][0]);
    }

    public function testAnotherKeySharingTheFirstFourCharactersDoesNotRefuseASearchOnlyKey(): void
    {
        // Typesense lists only a key's first four characters. Seen in a real site: a search-only
        // key and an events key both starting `harb`.
        $prefix = substr(bin2hex(random_bytes(2)), 0, 4);
        $search = $this->createKey(['documents:search'], $prefix . '-demo-search-only');
        $this->createKey(['analytics/events:create'], $prefix . '-demo-events');
        $this->createKey(['documents:*'], $prefix . '-demo-documents');

        $result = $this->service->testConnection($this->settings(['searchApiKey' => $search]));

        $this->assertTrue($result['ok'], implode(' ', $result['problems']));
    }

    public function testABroaderKeySharingItsFirstFourCharactersWithASearchOnlyKeyIsRefused(): void
    {
        $prefix = substr(bin2hex(random_bytes(2)), 0, 4);
        $this->createKey(['documents:search'], $prefix . '-demo-search-only');

        foreach ([['analytics/events:create'], ['documents:search', 'documents:get']] as $i => $actions) {
            $key = $this->createKey($actions, $prefix . '-demo-broad-' . $i);
            $result = $this->service->testConnection($this->settings(['searchApiKey' => $key]));

            $this->assertFalse($result['ok'], implode(',', $actions));
            $this->assertStringContainsString('allows more than search', $result['problems'][0]);
        }
    }

    public function testAKeyTheServerDoesNotHoldIsRefused(): void
    {
        $result = $this->service->testConnection($this->settings(['searchApiKey' => 'zzzz-no-such-key']));

        $this->assertSame(['The search-only API key is not a key on this server.'], $result['problems']);
    }

    public function testAClosedPortFailsWithinTheConnectTimeout(): void
    {
        $started = microtime(true);
        $result = $this->service->testConnection($this->settings(['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]));

        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('Could not reach ', $result['problems'][0]);
        $this->assertLessThan(3, microtime(true) - $started);
    }

    /**
     * @param list<string> $actions
     */
    private function createKey(array $actions, ?string $value = null): string
    {
        $key = $this->service->createClient($this->settings())->getKeys()->create(array_filter([
            'description' => 'typesense-sync connection test',
            'actions' => $actions,
            'collections' => ['*'],
            'value' => $value,
        ]));
        $this->createdKeys[] = (int)$key['id'];

        return (string)$key['value'];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values = []): Settings
    {
        return new Settings($values + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => $this->adminKey(),
        ]);
    }

    private function adminKey(): string
    {
        return (string)getenv('TYPESENSE_TEST_API_KEY');
    }
}
