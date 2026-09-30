<?php

namespace webdna\typesensesync\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * One analytics rule, as declared under `analytics.rules` in `config/typesense-sync.php`.
 *
 * Three kinds, all Typesense's own (30.0 rule format):
 *
 * - `popular_queries` — the searches run against a collection, aggregated into a destination
 *   collection of `{q, count}` documents.
 * - `nohits_queries` — the same, for searches that found nothing.
 * - `counter` — a field on the collection's own documents that Typesense increments for each
 *   event sent from the browser with the events-only key. The field is a counter in the BR-14
 *   sense: a reindex carries its live value through.
 *
 * The rule's name on the server is the collection's live name plus the handle
 * (`craft5_content_popular`), so environments sharing a cluster do not collide and `remove`
 * touches only what this site declared (BR-21).
 *
 * @since 1.0.0
 */
class AnalyticsRule extends Model
{
    public const TYPE_POPULAR = 'popular_queries';

    public const TYPE_NO_HITS = 'nohits_queries';

    public const TYPE_COUNTER = 'counter';

    public const TYPES = [self::TYPE_POPULAR, self::TYPE_NO_HITS, self::TYPE_COUNTER];

    /**
     * Keys a rule may carry in `config/typesense-sync.php`.
     */
    public const KEYS = ['enabled', 'type', 'collection', 'destination', 'limit', 'eventType', 'counterField', 'weight'];

    /**
     * The config handle, which is also the suffix of the rule's name on the server.
     */
    public string $handle = '';

    public string $type = self::TYPE_POPULAR;

    /**
     * The handle of the declared collection whose searches, or whose documents, the rule reads.
     */
    public string $collection = '';

    /**
     * Where a query rule aggregates, or an `$ENV_VAR` reference to it. Null derives it: the
     * collection's live name plus the handle. Never used by a counter, whose destination is its
     * own collection.
     */
    public ?string $destination = null;

    /**
     * How many distinct queries a query rule keeps.
     */
    public int $limit = 1000;

    /**
     * The event type a counter counts; the browser's event must name the same one, or Typesense
     * accepts it and counts nothing.
     */
    public string $eventType = 'click';

    public string $counterField = 'popularity';

    /**
     * How much one event adds to a counter.
     */
    public int $weight = 1;

    public bool $enabled = true;

    /**
     * @param array<string, mixed> $config One rule as declared.
     */
    public static function fromConfig(string $handle, array $config): self
    {
        $rule = new self(['handle' => $handle]);
        $rule->type = is_string($config['type'] ?? null) ? $config['type'] : self::TYPE_POPULAR;
        $rule->collection = is_string($config['collection'] ?? null) ? $config['collection'] : '';
        $rule->destination = isset($config['destination']) && is_scalar($config['destination']) ? (string)$config['destination'] : null;
        $rule->limit = (int)($config['limit'] ?? 1000);
        $rule->eventType = (string)($config['eventType'] ?? 'click');
        $rule->counterField = (string)($config['counterField'] ?? 'popularity');
        $rule->weight = (int)($config['weight'] ?? 1);
        $rule->enabled = (bool)($config['enabled'] ?? true);

        return $rule;
    }

    public function isCounter(): bool
    {
        return $this->type === self::TYPE_COUNTER;
    }

    /**
     * The rule's name on the server, given its collection's live name.
     */
    public function getName(string $collectionName): string
    {
        return $collectionName . '_' . $this->handle;
    }

    /**
     * The collection the rule writes into: the collection itself for a counter, else the
     * declared or derived destination. Empty when a declared destination resolves to nothing.
     */
    public function getDestination(string $collectionName): string
    {
        if ($this->isCounter()) {
            return $collectionName;
        }

        if ($this->destination === null) {
            return $collectionName . '_' . $this->handle;
        }

        return trim((string)App::parseEnv($this->destination));
    }

    /**
     * What Typesense is sent to create the rule.
     *
     * @return array{name: string, type: string, collection: string, event_type: string, params: array<string, mixed>}
     */
    public function getPayload(string $collectionName): array
    {
        $params = $this->isCounter()
            ? ['destination_collection' => $collectionName, 'counter_field' => $this->counterField, 'weight' => $this->weight]
            : ['destination_collection' => $this->getDestination($collectionName), 'limit' => $this->limit];

        return [
            'name' => $this->getName($collectionName),
            'type' => $this->type,
            'collection' => $collectionName,
            'event_type' => $this->isCounter() ? $this->eventType : 'search',
            'params' => $params,
        ];
    }
}
