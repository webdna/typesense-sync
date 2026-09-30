<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use Throwable;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\models\AnalyticsRule;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\TypesenseSync;

/**
 * Search analytics: what visitors search for, what they search for and do not find, and how
 * often each document is viewed (BR-21).
 *
 * Nothing happens unless rules are declared under `analytics.rules`. Typesense does the
 * aggregating itself: a query rule collects the searches it already serves into a destination
 * collection, so popular and no-hits reporting needs no browser code and no writable key. A
 * counter rule increments a field on the collection's own documents for each event the browser
 * posts, with a key that can create events and nothing else. Neither the search key nor a scoped
 * key can do that: a scoped key cannot write events, and a key that can write events cannot be
 * scoped — hence the separate events-only key.
 *
 * Rules are applied as a diff, so apply is idempotent, and every rule's name on the server is its
 * collection's live name plus its handle, so `remove` deletes only what this site declared. A
 * rule names the collection's alias, and Typesense follows the alias, so a recreate does not
 * break it (verified on 30.0, 30 Sep 2026). Only "not found" reads as absent; any other failure
 * throws, as in Collections.
 *
 * @since 1.0.0
 */
class Analytics extends Component
{
    /**
     * The rule was created.
     */
    public const ACTION_CREATE = 'create';

    /**
     * The rule existed but said something else, and was replaced in place.
     */
    public const ACTION_REPLACE = 'replace';

    /**
     * The rule already says what is declared.
     */
    public const ACTION_NONE = 'none';

    /**
     * The rule cannot be applied yet: its collection, or a counter's field, is not live.
     */
    public const ACTION_BLOCKED = 'blocked';

    /**
     * The only shape Typesense accepts for a query rule's destination collection.
     */
    private const DESTINATION_FIELDS = [
        ['name' => 'q', 'type' => 'string'],
        ['name' => 'count', 'type' => 'int32'],
    ];

    /**
     * Whether any rule is declared and enabled.
     */
    public function isEnabled(): bool
    {
        return $this->settings()->isAnalyticsEnabled();
    }

    /**
     * @return array<string, AnalyticsRule>
     */
    public function getRules(): array
    {
        return $this->settings()->getAnalyticsRules();
    }

    /**
     * Each declared rule against the server: whether it exists and matches, and whether its
     * destination exists. `unmanaged` lists rules on the server this site did not declare —
     * another environment's, or made by hand — which nothing here touches.
     *
     * @return array{rules: array<string, array{handle: string, name: string, type: string, collection: string, destination: string, destinationExists: bool, state: string}>, unmanaged: string[]}
     * @throws SyncException when the server cannot be asked.
     */
    public function diff(): array
    {
        $live = $this->liveRules();
        $rows = [];

        foreach ($this->payloads() as $handle => [$rule, $payload]) {
            $current = $live[$payload['name']] ?? null;
            $destination = $payload['params']['destination_collection'];

            $rows[$handle] = [
                'handle' => $handle,
                'name' => $payload['name'],
                'type' => $rule->type,
                'collection' => $rule->collection,
                'destination' => $destination,
                'destinationExists' => $this->collectionExists($destination),
                'state' => $current === null ? 'missing' : (self::matches($current, $payload) ? 'ok' : 'differs'),
            ];
        }

        $declared = array_column($rows, 'name');

        return [
            'rules' => $rows,
            'unmanaged' => array_values(array_diff(array_keys($live), $declared)),
        ];
    }

    /**
     * Create each query rule's destination and each rule that is missing, and replace one that
     * differs. A rule already matching is left alone, so a second run changes nothing.
     *
     * A counter needs its collection live with the counter field in it (collections apply adds
     * the field), so until then it is `blocked` and nothing is sent for it.
     *
     * @return list<array{handle: string, name: string, action: string, message: string}>
     * @throws SyncException when the server cannot be asked or refuses a write.
     */
    public function apply(bool $dryRun = false): array
    {
        $client = $this->client();
        $live = $this->liveRules();
        $results = [];

        foreach ($this->payloads() as $handle => [$rule, $payload]) {
            $name = $payload['name'];
            $blocked = $this->blockedBecause($rule);

            if ($blocked !== null) {
                $results[] = ['handle' => $handle, 'name' => $name, 'action' => self::ACTION_BLOCKED, 'message' => $blocked];
                continue;
            }

            $current = $live[$name] ?? null;

            if ($current !== null && self::matches($current, $payload)) {
                $results[] = ['handle' => $handle, 'name' => $name, 'action' => self::ACTION_NONE, 'message' => Craft::t('typesense-sync', '{name} is up to date.', ['name' => $name])];
                continue;
            }

            $action = $current === null ? self::ACTION_CREATE : self::ACTION_REPLACE;
            $destination = $payload['params']['destination_collection'];
            $messages = [];

            if (!$rule->isCounter() && !$this->collectionExists($destination)) {
                if (!$dryRun) {
                    try {
                        $client->collections->create(['name' => $destination, 'fields' => self::DESTINATION_FIELDS]);
                    } catch (Throwable $e) {
                        throw $this->failure(Craft::t('typesense-sync', 'Could not create the analytics collection "{name}"', ['name' => $destination]), $e);
                    }
                }

                $messages[] = $dryRun
                    ? Craft::t('typesense-sync', 'Would create collection {destination}.', ['destination' => $destination])
                    : Craft::t('typesense-sync', 'Created collection {destination}.', ['destination' => $destination]);
            }

            if ($dryRun) {
                $messages[] = Craft::t('typesense-sync', $action === self::ACTION_CREATE ? 'Would create {name}: {payload}' : 'Would replace {name}: {payload}', [
                    'name' => $name,
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ]);
            } else {
                // PUT creates or replaces in place; POST refuses a name that exists.
                try {
                    $client->analytics->rules()[$name]->update($payload);
                } catch (Throwable $e) {
                    throw $this->failure(Craft::t('typesense-sync', 'Could not write analytics rule "{name}"', ['name' => $name]), $e);
                }

                $messages[] = Craft::t('typesense-sync', $action === self::ACTION_CREATE ? 'Created {name}.' : 'Replaced {name}.', ['name' => $name]);
            }

            $results[] = ['handle' => $handle, 'name' => $name, 'action' => $action, 'message' => implode(' ', $messages)];
        }

        return $results;
    }

    /**
     * Delete the declared rules that exist on the server, and nothing else (BR-21). Destination
     * collections and counter values are kept: the history is not the rule's to throw away, and
     * apply restores the rules.
     *
     * @return list<array{handle: string, name: string, removed: bool, message: string}>
     * @throws SyncException when the server cannot be asked or refuses a delete.
     */
    public function remove(bool $dryRun = false): array
    {
        $client = $this->client();
        $live = $this->liveRules();
        $results = [];

        foreach ($this->payloads() as $handle => [, $payload]) {
            $name = $payload['name'];

            if (!isset($live[$name])) {
                $results[] = ['handle' => $handle, 'name' => $name, 'removed' => false, 'message' => Craft::t('typesense-sync', '{name} is not on the server.', ['name' => $name])];
                continue;
            }

            if (!$dryRun) {
                try {
                    $client->analytics->rules()[$name]->delete();
                } catch (ObjectNotFound) {
                    // Gone between the read and the delete: the outcome wanted.
                } catch (Throwable $e) {
                    throw $this->failure(Craft::t('typesense-sync', 'Could not delete analytics rule "{name}"', ['name' => $name]), $e);
                }
            }

            $results[] = [
                'handle' => $handle,
                'name' => $name,
                'removed' => !$dryRun,
                'message' => Craft::t('typesense-sync', $dryRun ? 'Would delete {name}.' : 'Deleted {name}.', ['name' => $name]),
            ];
        }

        return $results;
    }

    /**
     * A query rule's most frequent queries, ignored queries left out. Empty while nothing has
     * been aggregated.
     *
     * @return list<array{q: string, count: int}>
     * @throws SyncException when the rule is not a declared query rule, or cannot be read.
     */
    public function report(string $handle, int $limit = 20): array
    {
        [$rule, $payload] = $this->payloads()[$handle] ?? [null, null];

        if ($rule === null || $payload === null || $rule->isCounter()) {
            throw new SyncException(Craft::t('typesense-sync', 'No query rule "{rule}" is declared.', ['rule' => $handle]));
        }

        $destination = $payload['params']['destination_collection'];
        $ignored = $this->settings()->getIgnoredQueries();

        try {
            // Over-fetch: `q` is not a facet, so ignored queries are filtered out here.
            $result = $this->client()->collections[$destination]->documents->search([
                'q' => '*',
                'query_by' => 'q',
                'sort_by' => 'count:desc',
                'per_page' => min(250, $limit + count($ignored)),
            ]);
        } catch (ObjectNotFound) {
            return [];
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not read the analytics collection "{name}"', ['name' => $destination]), $e);
        }

        $rows = [];

        foreach ((array)($result['hits'] ?? []) as $hit) {
            $q = (string)($hit['document']['q'] ?? '');

            if (!in_array($q, $ignored, true)) {
                $rows[] = ['q' => $q, 'count' => (int)($hit['document']['count'] ?? 0)];
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * A counter rule's highest-counted documents, counted at least once.
     *
     * @return list<array{id: string, count: int, document: array<string, mixed>}>
     * @throws SyncException when the rule is not a declared counter rule, or cannot be read.
     */
    public function topCounted(string $handle, int $limit = 20): array
    {
        [$rule, $payload] = $this->payloads()[$handle] ?? [null, null];

        if ($rule === null || $payload === null || !$rule->isCounter()) {
            throw new SyncException(Craft::t('typesense-sync', 'No counter rule "{rule}" is declared.', ['rule' => $handle]));
        }

        $field = $rule->counterField;

        try {
            $result = $this->client()->collections[$payload['collection']]->documents->search([
                'q' => '*',
                'filter_by' => $field . ':>0',
                'sort_by' => $field . ':desc',
                'per_page' => min(250, max(1, $limit)),
            ]);
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not read the counter "{field}" of "{name}"', ['field' => $field, 'name' => $payload['collection']]), $e);
        }

        $rows = [];

        foreach ((array)($result['hits'] ?? []) as $hit) {
            $document = (array)($hit['document'] ?? []);
            $rows[] = ['id' => (string)($document['id'] ?? ''), 'count' => (int)($document[$field] ?? 0), 'document' => $document];
        }

        return $rows;
    }

    /**
     * Current counts of one counter rule for a set of document ids. A document not in the
     * collection is absent from the result.
     *
     * @param array<int|string> $ids
     * @return array<string, int>|null Null when the rule is not a declared counter or the counts
     *         cannot be read — show that as unknown, never as zero.
     */
    public function viewCounts(string $handle, array $ids): ?array
    {
        [$rule, $payload] = $this->payloads()[$handle] ?? [null, null];

        if ($rule === null || $payload === null || !$rule->isCounter()) {
            return null;
        }

        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            return [];
        }

        try {
            $counters = TypesenseSync::getInstance()->sync->fetchCounters($payload['collection'], $ids, [$rule->counterField]);
        } catch (SyncException) {
            return null;
        }

        return array_map(fn(array $values) => $values[$rule->counterField] ?? 0, $counters);
    }

    /**
     * What the browser needs to post counter events: the server, the events-only key and each
     * counter rule. Null when there is no counter rule or no events key, or when the events key
     * is the admin key (BR-17).
     *
     * No visitor identifier is included, and none should be added: a persistent id sent to a
     * search host needs consent in much of the world, and counting needs none.
     *
     * @return array{host: string, port: int, protocol: string, apiKey: string, rules: array<string, array{name: string, collection: string, eventType: string}>}|null
     */
    public function clientConfig(): ?array
    {
        $settings = $this->settings();
        $key = $settings->getAnalyticsEventsKey();

        if (!$settings->isConfigured() || $key === '' || $key === $settings->getApiKey()) {
            return null;
        }

        $rules = [];

        foreach ($this->payloads() as $handle => [$rule, $payload]) {
            if ($rule->isCounter()) {
                $rules[$handle] = ['name' => $payload['name'], 'collection' => $payload['collection'], 'eventType' => $payload['event_type']];
            }
        }

        if ($rules === []) {
            return null;
        }

        return [
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'protocol' => $settings->getProtocol(),
            'apiKey' => $key,
            'rules' => $rules,
        ];
    }

    /**
     * Create a key that can post analytics events and nothing else. Typesense shows a key's
     * value only when it is created, so the caller must hand it on at once.
     *
     * @return array{id: int, value: string, actions: string[]}
     * @throws SyncException
     */
    public function createEventsKey(): array
    {
        try {
            $key = $this->client()->keys->create([
                'description' => 'typesense-sync analytics events (browser)',
                'actions' => ['analytics/events:create'],
                'collections' => ['*'],
            ]);
        } catch (Throwable $e) {
            throw $this->failure('Could not create the analytics events key', $e);
        }

        return [
            'id' => (int)($key['id'] ?? 0),
            'value' => (string)($key['value'] ?? ''),
            'actions' => array_map('strval', (array)($key['actions'] ?? [])),
        ];
    }

    /**
     * Every analytics rule on the server, ours or not, by name.
     *
     * @return array<string, array<string, mixed>>
     * @throws SyncException
     */
    public function liveRules(): array
    {
        try {
            $rules = $this->client()->analytics->rules()->retrieve();
        } catch (Throwable $e) {
            throw $this->failure('Could not list the analytics rules', $e);
        }

        // 30.0 answers a bare list; older servers wrapped it in {rules: []}.
        $rules = is_array($rules['rules'] ?? null) ? $rules['rules'] : (array)$rules;
        $byName = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && is_string($rule['name'] ?? null)) {
                $byName[$rule['name']] = $rule;
            }
        }

        return $byName;
    }

    /**
     * Whether a live rule says what is declared. Only the declared keys are compared: the server
     * fills in others (`rule_tag`, `capture_search_requests`, `expand_query`).
     *
     * @param array<string, mixed> $current
     * @param array{name: string, type: string, collection: string, event_type: string, params: array<string, mixed>} $wanted
     */
    public static function matches(array $current, array $wanted): bool
    {
        foreach (['type', 'collection', 'event_type'] as $key) {
            if (($current[$key] ?? null) !== $wanted[$key]) {
                return false;
            }
        }

        foreach ($wanted['params'] as $key => $value) {
            $live = $current['params'][$key] ?? null;

            if (is_int($value) ? (int)$live !== $value : $live !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each rule with the payload Typesense is sent for it. A rule whose collection name cannot be
     * resolved is left out; `Settings::getProblems()` names it.
     *
     * @return array<string, array{0: AnalyticsRule, 1: array{name: string, type: string, collection: string, event_type: string, params: array<string, mixed>}}>
     */
    private function payloads(): array
    {
        $settings = $this->settings();
        $payloads = [];

        foreach ($this->getRules() as $handle => $rule) {
            $collection = $settings->getCollectionConfig($rule->collection);

            if ($collection === null || !$collection->hasName()) {
                continue;
            }

            $payloads[$handle] = [$rule, $rule->getPayload($collection->getName())];
        }

        return $payloads;
    }

    /**
     * Why a rule cannot be applied yet, or null when it can.
     *
     * @throws SyncException
     */
    private function blockedBecause(AnalyticsRule $rule): ?string
    {
        try {
            $schema = TypesenseSync::getInstance()->collections->getLiveSchema($rule->collection);
        } catch (SyncException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SyncException($e->getMessage(), 0, $e);
        }

        if ($schema === null) {
            return Craft::t('typesense-sync', 'Collection "{collection}" does not exist yet; run `craft typesense-sync/collections/apply` first.', ['collection' => $rule->collection]);
        }

        if ($rule->isCounter() && !in_array($rule->counterField, array_column((array)($schema['fields'] ?? []), 'name'), true)) {
            return Craft::t('typesense-sync', 'Collection "{collection}" has no field "{field}" yet; run `craft typesense-sync/collections/apply` first.', [
                'collection' => $rule->collection,
                'field' => $rule->counterField,
            ]);
        }

        return null;
    }

    /**
     * @throws SyncException when the server cannot be asked.
     */
    private function collectionExists(string $name): bool
    {
        try {
            $this->client()->collections[$name]->retrieve();
        } catch (ObjectNotFound) {
            return false;
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not read collection "{name}"', ['name' => $name]), $e);
        }

        return true;
    }

    private function failure(string $what, Throwable $e): SyncException
    {
        $message = sprintf('%s: %s', $what, $e->getMessage());
        Craft::error($message, TypesenseSync::HANDLE);

        return new SyncException($message, 0, $e);
    }

    /**
     * @throws SyncException
     */
    private function client(): TypesenseClient
    {
        return TypesenseSync::getInstance()->client->getClient()
            ?? throw new SyncException(Craft::t('typesense-sync', 'Typesense is not configured: set a host and an admin API key.'));
    }

    private function settings(): Settings
    {
        return TypesenseSync::getInstance()->targets->getSettings();
    }
}
