<?php

namespace webdna\typesensesync\services;

use Craft;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\RequestUnauthorized;
use Typesense\Exceptions\TypesenseClientError;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\TypesenseSync;
use yii\base\Component;

/**
 * The one way the plugin reaches Typesense: a client whose every request is bounded, and the
 * connection test the settings screen runs.
 *
 * `typesense-php` v6 ignores `connection_timeout_seconds` and, left to itself, discovers whatever
 * PSR-18 client is installed, so a request to an unreachable server would wait for PHP's own
 * limits. The client is therefore always handed a Guzzle client with the configured timeouts.
 *
 * @since 1.0.0
 */
class Client extends Component
{
    /**
     * The oldest server the plugin supports (BR-24): analytics rules and reference joins take
     * their v30 form.
     */
    public const MIN_SERVER_VERSION = '30.0';

    /**
     * The only action a search-only key may allow (BR-19).
     */
    public const SEARCH_ACTIONS = ['documents:search'];

    /**
     * Seconds between retries. Short, because every retry is another full timeout for a caller
     * that is waiting.
     */
    private const RETRY_INTERVAL = 0.5;

    private ?TypesenseClient $client = null;

    private bool $resolved = false;

    /**
     * The client for the saved settings, memoised for the request; null when no server and key
     * are configured.
     */
    public function getClient(): ?TypesenseClient
    {
        if ($this->resolved) {
            return $this->client;
        }

        $this->resolved = true;
        $settings = $this->settings();

        if (!$settings->isConfigured()) {
            Craft::warning('Typesense is not configured: set a host and an admin API key.', TypesenseSync::HANDLE);

            return null;
        }

        try {
            $this->client = $this->createClient($settings);
        } catch (Throwable $e) {
            Craft::error('Could not create the Typesense client: ' . $e->getMessage(), TypesenseSync::HANDLE);
        }

        return $this->client;
    }

    /**
     * Replaces the memoised client; null forgets it, so the next call builds one from settings.
     */
    public function setClient(?TypesenseClient $client): void
    {
        $this->client = $client;
        $this->resolved = $client !== null;
    }

    /**
     * A client for the given settings, bounded by their timeouts.
     *
     * @param int|null $numRetries Retries after a failed request; the settings' value when null.
     * @param string|null $apiKey The key to send; the admin key when null.
     */
    public function createClient(Settings $settings, ?int $numRetries = null, ?string $apiKey = null): TypesenseClient
    {
        return new TypesenseClient([
            'api_key' => $apiKey ?? $settings->getApiKey(),
            'nodes' => [[
                'host' => $settings->getHost(),
                'port' => $settings->getPort(),
                'protocol' => $settings->getProtocol(),
            ]],
            'num_retries' => $numRetries ?? $settings->numRetries,
            'retry_interval_seconds' => self::RETRY_INTERVAL,
            'client' => $this->createGuzzleClient([
                'connect_timeout' => $settings->connectTimeout,
                'timeout' => $settings->timeout,
            ]),
        ]);
    }

    /**
     * Connects with the given settings (the saved ones when null) and reports what was found:
     * the server version, and every reason the connection is not fit to use (BR-19, BR-24).
     *
     * Never throws; each failure is a problem, and is logged.
     *
     * @return array{ok: bool, version: string|null, problems: list<string>}
     */
    public function testConnection(?Settings $settings = null): array
    {
        $settings ??= $this->settings();

        if (!$settings->isConfigured()) {
            return $this->result(null, [Craft::t('typesense-sync', 'Enter a host and an admin API key.')]);
        }

        // A timeout of 0 is Guzzle's "wait for ever", so an invalid limit is refused, not tried.
        if (!$settings->validate(['port', 'protocol', 'connectTimeout', 'timeout'])) {
            return $this->result(null, array_values(array_map('strval', $settings->getFirstErrors())));
        }

        // One attempt: the person pressing the button is waiting for the answer.
        $client = $this->createClient($settings, 0);

        try {
            $debug = $client->getDebug()->retrieve();
        } catch (Throwable $e) {
            return $this->result(null, [$this->describeFailure($e, $settings, 'admin API key')]);
        }

        $version = (string)($debug['version'] ?? '');
        $problems = [];

        if (($problem = self::versionProblem($version)) !== null) {
            $problems[] = $problem;
        }

        if ($settings->getSearchApiKey() !== '' && ($problem = $this->searchKeyProblem($client, $settings)) !== null) {
            $problems[] = $problem;
        }

        foreach ($problems as $problem) {
            Craft::warning('Connection test: ' . $problem, TypesenseSync::HANDLE);
        }

        return $this->result($version, $problems);
    }

    /**
     * Why the saved server cannot be worked on right now — not configured, unreachable, a key
     * refused, or older than 30.0 (BR-24) — or null when it can. One attempt, bounded by the
     * timeouts, so a CP action refuses quickly rather than failing half way through its work.
     */
    public function serverProblem(): ?string
    {
        $settings = $this->settings();

        if (!$settings->isConfigured()) {
            return Craft::t('typesense-sync', 'Typesense Sync is not connected. Enter the server details in its settings.');
        }

        try {
            $debug = $this->createClient($settings, 0)->getDebug()->retrieve();
        } catch (Throwable $e) {
            return $this->describeFailure($e, $settings, 'admin API key');
        }

        return self::versionProblem((string)($debug['version'] ?? ''));
    }

    /**
     * Why a server of this version cannot be used, or null when it can (BR-24). A version that
     * does not start with a number (a nightly build) cannot be shown to be 30.0 or later, so it
     * is refused too.
     */
    public static function versionProblem(string $version): ?string
    {
        if (
            preg_match('/^v?(\d+(?:\.\d+)*)/', $version, $matches) === 1
            && version_compare($matches[1], self::MIN_SERVER_VERSION, '>=')
        ) {
            return null;
        }

        return Craft::t('typesense-sync', 'Typesense {version} found; version {min} or later is required.', [
            'version' => $version === '' ? Craft::t('typesense-sync', '(unknown version)') : $version,
            'min' => self::MIN_SERVER_VERSION,
        ]);
    }

    /**
     * Why the search-only key cannot be used to sign search keys, or null when it can (BR-19).
     *
     * Typesense never returns a key's value, only its first four characters, so the key is
     * checked two ways. It is used to list collections, which a search-only key cannot do; that
     * catches the admin key and the server's bootstrap key, which `/keys` does not list. Then
     * every listed key sharing its prefix must allow `documents:search` and nothing else.
     */
    public function searchKeyProblem(TypesenseClient $admin, Settings $settings): ?string
    {
        $searchKey = $settings->getSearchApiKey();
        $tooBroad = Craft::t('typesense-sync', 'The search-only API key allows more than search: it must allow `documents:search` and nothing else.');

        if ($searchKey === $settings->getApiKey()) {
            return $tooBroad;
        }

        try {
            $this->createClient($settings, 0, $searchKey)->getCollections()->retrieve();

            return $tooBroad;
        } catch (RequestUnauthorized) {
            // Refused, as a search-only key should be.
        } catch (Throwable $e) {
            return $this->describeFailure($e, $settings, 'search-only API key');
        }

        try {
            $keys = $admin->getKeys()->retrieve()['keys'] ?? [];
        } catch (Throwable $e) {
            return Craft::t('typesense-sync', 'The admin API key could not list keys, so the search-only key could not be checked: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        $matches = array_filter(
            is_array($keys) ? $keys : [],
            fn($key) => is_array($key) && ($key['value_prefix'] ?? null) === substr($searchKey, 0, 4),
        );

        if ($matches === []) {
            return Craft::t('typesense-sync', 'The search-only API key is not a key on this server.');
        }

        foreach ($matches as $key) {
            $actions = is_array($key['actions'] ?? null) ? $key['actions'] : [];
            if (array_values($actions) !== self::SEARCH_ACTIONS) {
                return $tooBroad;
            }
        }

        return null;
    }

    /**
     * The Guzzle client every Typesense request goes through. Tests replace it to answer
     * without a server.
     *
     * @param array<string, mixed> $config
     */
    protected function createGuzzleClient(array $config): GuzzleClient
    {
        return Craft::createGuzzleClient($config);
    }

    private function describeFailure(Throwable $e, Settings $settings, string $keyName): string
    {
        $message = match (true) {
            $e instanceof RequestUnauthorized => Craft::t('typesense-sync', 'Authentication failed: the server refused the {key}.', [
                'key' => Craft::t('typesense-sync', $keyName),
            ]),
            $e instanceof TypesenseClientError => Craft::t('typesense-sync', 'The server answered with an error: {message}', [
                'message' => $e->getMessage(),
            ]),
            default => Craft::t('typesense-sync', 'Could not reach {url}: {message}', [
                'url' => sprintf('%s://%s:%d', $settings->getProtocol(), $settings->getHost(), $settings->getPort()),
                'message' => $e->getMessage(),
            ]),
        };

        Craft::warning('Connection test: ' . $message, TypesenseSync::HANDLE);

        return $message;
    }

    /**
     * @param list<string> $problems
     * @return array{ok: bool, version: string|null, problems: list<string>}
     */
    private function result(?string $version, array $problems): array
    {
        return ['ok' => $problems === [], 'version' => $version, 'problems' => $problems];
    }

    private function settings(): Settings
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        // Through targets, as every other service, so settings a test injects reach here too.
        return $plugin->targets->getSettings();
    }
}
