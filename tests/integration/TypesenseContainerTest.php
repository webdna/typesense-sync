<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;

/**
 * The integration tier's Typesense container is reachable and is the minimum supported
 * server (30.0), so every later integration test runs against a server the plugin claims.
 */
class TypesenseContainerTest extends Unit
{
    public function testServerIsHealthyAndAtLeastVersion30(): void
    {
        $base = sprintf(
            '%s://%s:%s',
            getenv('TYPESENSE_TEST_PROTOCOL'),
            getenv('TYPESENSE_TEST_HOST'),
            getenv('TYPESENSE_TEST_PORT'),
        );
        $client = Craft::createGuzzleClient(['base_uri' => $base, 'timeout' => 5, 'connect_timeout' => 2]);

        $health = json_decode((string)$this->retry(fn() => $client->get('/health'))->getBody(), true);
        $this->assertSame(['ok' => true], $health);

        $debug = json_decode((string)$client->get('/debug', [
            'headers' => ['X-TYPESENSE-API-KEY' => getenv('TYPESENSE_TEST_API_KEY')],
        ])->getBody(), true);
        $this->assertIsArray($debug);
        $this->assertTrue(version_compare((string)$debug['version'], '30.0', '>='), 'Found Typesense ' . $debug['version']);
    }

    /**
     * The container accepts connections a moment before it answers /health.
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    private function retry(callable $request): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $request();
            } catch (\Throwable $e) {
                if ($attempt >= 20) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }
}
