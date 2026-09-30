<?php

namespace webdna\typesensesync\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use Throwable;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\formatters\SchemaContext;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\TypesenseSync;

/**
 * The plugin's settings: the connection, editable in the control panel, and the declarations —
 * collections, sources and analytics — which live only in `config/typesense-sync.php` (BR-2).
 *
 * Nothing here reaches Typesense or loads an element, so the whole configuration can be
 * inspected, and its problems listed, before any connection exists.
 *
 * @since 1.0.0
 */
class Settings extends Model
{
    /**
     * Settable only in `config/typesense-sync.php`: never shown in the CP, never written to
     * project config.
     */
    public const FILE_ONLY = ['collections', 'sources', 'analytics'];

    /**
     * Keys the `analytics` array may carry.
     */
    public const ANALYTICS_KEYS = ['enabled', 'eventsKey', 'ignoreQueries', 'rules'];

    /**
     * Connection settings that accept `$ENV_VAR` references and are stored unresolved.
     */
    private const ENV_ATTRIBUTES = ['host', 'port', 'protocol', 'apiKey', 'searchApiKey', 'collectionPrefix'];

    /**
     * What a connection test reads; the settings form may change these before a save.
     */
    public const CONNECTION = ['host', 'port', 'protocol', 'apiKey', 'searchApiKey', 'connectTimeout', 'timeout'];

    /**
     * Typesense's own values for field options; a formatter that states one and a formatter that
     * leaves it out declare the same field.
     */
    private const FIELD_DEFAULTS = [
        'facet' => false,
        'optional' => false,
        'index' => true,
        'infix' => false,
        'store' => true,
        'stem' => false,
    ];

    public string $host = '';

    /**
     * A string so it can hold an `$ENV_VAR` reference; read it with getPort().
     */
    public string $port = '443';

    public string $protocol = 'https';

    /**
     * The admin key. It never leaves the server (BR-17).
     */
    public string $apiKey = '';

    /**
     * The search-only key, the only key scoped keys are signed with (BR-17).
     */
    public string $searchApiKey = '';

    /**
     * Seconds to wait for a connection.
     */
    public int $connectTimeout = 2;

    /**
     * Seconds to wait for a response.
     */
    public int $timeout = 5;

    public int $numRetries = 3;

    /**
     * Queue priority for sync jobs. Craft's default is 1024; lower runs sooner.
     */
    public int $queuePriority = 1024;

    /**
     * Documents per import request, and elements per query batch, during a reindex.
     */
    public int $batchSize = 100;

    /**
     * Prepended to a collection's handle to make its live name, unless it names itself.
     */
    public string $collectionPrefix = '';

    /**
     * Collections by handle. File only. Typed loosely because the file is user input; a
     * malformed entry is reported by getProblems().
     *
     * @var array<int|string, mixed>
     */
    public array $collections = [];

    /**
     * Source declarations, in order. File only.
     *
     * @var array<int|string, mixed>
     */
    public array $sources = [];

    /**
     * Analytics declarations — `enabled`, `eventsKey`, `ignoreQueries`, `rules` — read through
     * getAnalyticsRules(). File only.
     *
     * @var array<string, mixed>
     */
    public array $analytics = [];

    /**
     * @return array<string, mixed>
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => self::ENV_ATTRIBUTES,
            ],
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['protocol'], 'in', 'range' => ['http', 'https']],
            [['port'], 'integer', 'min' => 1, 'max' => 65535],
            [['connectTimeout', 'timeout', 'batchSize'], 'integer', 'min' => 1],
            [['numRetries', 'queuePriority'], 'integer', 'min' => 0],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'host' => Craft::t('typesense-sync', 'Host'),
            'port' => Craft::t('typesense-sync', 'Port'),
            'protocol' => Craft::t('typesense-sync', 'Protocol'),
            'apiKey' => Craft::t('typesense-sync', 'Admin API key'),
            'searchApiKey' => Craft::t('typesense-sync', 'Search-only API key'),
            'connectTimeout' => Craft::t('typesense-sync', 'Connect timeout'),
            'timeout' => Craft::t('typesense-sync', 'Request timeout'),
            'numRetries' => Craft::t('typesense-sync', 'Retries'),
            'queuePriority' => Craft::t('typesense-sync', 'Queue priority'),
            'batchSize' => Craft::t('typesense-sync', 'Batch size'),
            'collectionPrefix' => Craft::t('typesense-sync', 'Collection prefix'),
        ];
    }

    /**
     * Leaves the file-only declarations out of every serialisation, which is what keeps them out
     * of project config when the CP saves the settings.
     *
     * @return array<int|string, string|callable>
     */
    public function fields(): array
    {
        return array_diff_key(parent::fields(), array_flip(self::FILE_ONLY));
    }

    public function getHost(): string
    {
        return $this->env($this->host);
    }

    public function getPort(): int
    {
        return (int)$this->env($this->port);
    }

    public function getProtocol(): string
    {
        return $this->env($this->protocol);
    }

    /**
     * The resolved admin key. Server-side use only (BR-17).
     */
    public function getApiKey(): string
    {
        return $this->env($this->apiKey);
    }

    public function getSearchApiKey(): string
    {
        return $this->env($this->searchApiKey);
    }

    public function getCollectionPrefix(): string
    {
        return $this->env($this->collectionPrefix);
    }

    /**
     * The admin key as the CP may show it: its last four characters (BR-17).
     */
    public function getMaskedApiKey(): string
    {
        $key = $this->getApiKey();

        return $key === '' ? '' : '••••' . substr($key, -4);
    }

    /**
     * A copy with connection values from a settings form applied: what *Test connection* tests,
     * before anything is saved.
     *
     * A blank admin key keeps the saved one, as a save does; a key named in `$locked` (set in
     * `config/typesense-sync.php`, which wins over the form) keeps its value; anything that is not
     * a connection setting is ignored.
     *
     * @param array<string, mixed> $posted
     * @param list<string> $locked
     */
    public function withConnection(array $posted, array $locked = []): self
    {
        $settings = clone $this;

        foreach (self::CONNECTION as $key) {
            if (in_array($key, $locked, true) || !is_scalar($posted[$key] ?? null)) {
                continue;
            }

            $value = trim((string)$posted[$key]);

            if (in_array($key, ['connectTimeout', 'timeout'], true)) {
                $settings->$key = ctype_digit($value) ? (int)$value : 0;
            } elseif ($key !== 'apiKey' || $value !== '') {
                $settings->$key = $value;
            }
        }

        return $settings;
    }

    /**
     * Whether the settings name a server and a key, which is the least a connection needs.
     */
    public function isConfigured(): bool
    {
        return $this->getHost() !== '' && $this->getApiKey() !== '';
    }

    /**
     * Declared collections by handle, with the resolved prefix applied.
     *
     * @return array<string, CollectionConfig>
     */
    public function getCollectionConfigs(): array
    {
        $prefix = $this->getCollectionPrefix();
        $collections = [];

        foreach ($this->collections as $handle => $config) {
            if (!is_string($handle) || $handle === '' || !is_array($config)) {
                continue;
            }

            $search = is_array($config['search'] ?? null) ? $config['search'] : [];

            $collections[$handle] = new CollectionConfig([
                'handle' => $handle,
                'name' => isset($config['name']) ? (string)$config['name'] : null,
                'prefix' => $prefix,
                'schema' => array_values(array_filter((array)($config['schema'] ?? []), 'is_array')),
                'defaultSortingField' => isset($config['defaultSortingField']) ? (string)$config['defaultSortingField'] : null,
                'enableNestedFields' => (bool)($config['enableNestedFields'] ?? true),
                'counters' => array_map('strval', (array)($config['counters'] ?? [])),
                'publicationWindow' => (bool)($search['publicationWindow'] ?? true),
                'excludeFields' => array_map('strval', (array)($search['excludeFields'] ?? [])),
            ]);
        }

        return $collections;
    }

    public function getCollectionConfig(string $handle): ?CollectionConfig
    {
        return $this->getCollectionConfigs()[$handle] ?? null;
    }

    /**
     * Whether analytics is on: declared, not switched off by `analytics.enabled`, and with at
     * least one enabled rule (BR-21).
     */
    public function isAnalyticsEnabled(): bool
    {
        return $this->getAnalyticsRules() !== [];
    }

    /**
     * The enabled analytics rules whose collection is declared, by handle. Empty when analytics
     * is switched off, so callers need only one check.
     *
     * @return array<string, AnalyticsRule>
     */
    public function getAnalyticsRules(): array
    {
        if (!(bool)($this->analytics['enabled'] ?? true) || !is_array($this->analytics['rules'] ?? null)) {
            return [];
        }

        $collections = $this->getCollectionConfigs();
        $rules = [];

        foreach ($this->analytics['rules'] as $handle => $config) {
            if (!is_string($handle) || !is_array($config)) {
                continue;
            }

            $rule = AnalyticsRule::fromConfig($handle, $config);

            if ($rule->enabled && in_array($rule->type, AnalyticsRule::TYPES, true) && isset($collections[$rule->collection])) {
                $rules[$handle] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The resolved events-only key the browser posts counter events with. Empty when unset.
     */
    public function getAnalyticsEventsKey(): string
    {
        $key = $this->analytics['eventsKey'] ?? '';

        return is_scalar($key) ? trim($this->env((string)$key)) : '';
    }

    /**
     * Queries left out of every report. By default the empty query and `*`, which a search page
     * sends before anyone types.
     *
     * @return string[]
     */
    public function getIgnoredQueries(): array
    {
        return array_map('strval', (array)($this->analytics['ignoreQueries'] ?? ['*', '']));
    }

    /**
     * Fields in a collection that Typesense maintains itself, and a write must carry through
     * (BR-14): the collection's `counters`, plus the field of every enabled counter rule on it.
     *
     * @return string[]
     */
    public function getCounterFields(string $collection): array
    {
        $fields = $this->getCollectionConfig($collection)->counters ?? [];

        foreach ($this->getAnalyticsRules() as $rule) {
            if ($rule->isCounter() && $rule->collection === $collection) {
                $fields[] = $rule->counterField;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Declared sources that can index on this install: well-formed, of a known kind, and — for
     * product types — only while Commerce is installed (BR-5).
     *
     * @return SourceConfig[]
     */
    public function getSourceConfigs(): array
    {
        $sources = [];
        $seen = [];

        foreach ($this->sources as $config) {
            $source = $this->makeSource($config);

            if ($source === null) {
                continue;
            }

            if ($source->kind === SourceConfig::KIND_PRODUCT_TYPE && !$this->isCommerceInstalled()) {
                continue;
            }

            // A duplicate is reported by getProblems(); the first declaration wins.
            $key = $source->getDescription();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * The target for one kind of element, or null when nothing declares it. A declared target may
     * still not be indexable; ask ResolvedTarget::isIndexable().
     *
     * @param string|null $entryType Entry type handle, for sections only.
     */
    public function resolveTarget(string $kind, string $handle = '', ?string $entryType = null): ?ResolvedTarget
    {
        foreach ($this->getSourceConfigs() as $source) {
            if ($source->kind === $kind && ($kind === SourceConfig::KIND_USERS || $source->handle === $handle)) {
                return $this->markValidity($source->resolveFor($entryType));
            }
        }

        return null;
    }

    /**
     * Every indexable target, across every source. Drives the schema union and a reindex.
     *
     * @return ResolvedTarget[]
     */
    public function getIndexableTargets(): array
    {
        $targets = [];

        foreach ($this->getSourceConfigs() as $source) {
            foreach ($source->resolveAll() as $target) {
                if ($this->markValidity($target)->isIndexable()) {
                    $targets[] = $target;
                }
            }
        }

        return $targets;
    }

    /**
     * Indexable targets routing into one collection.
     *
     * @return ResolvedTarget[]
     */
    public function getTargetsForCollection(string $collection): array
    {
        return array_values(array_filter(
            $this->getIndexableTargets(),
            static fn(ResolvedTarget $target) => $target->collection === $collection,
        ));
    }

    /**
     * The live name of every declared collection whose name resolves, by handle: what a
     * formatter's SchemaContext resolves a reference against.
     *
     * @return array<string, string>
     */
    public function getLiveNames(): array
    {
        return self::liveNamesOf($this->getCollectionConfigs());
    }

    /**
     * Configuration errors: anything that stops the declared config working as written. The
     * utility lists them and `setup` exits non-zero on any (BR-4).
     *
     * @param array<string, mixed>|null $fileConfig The raw `config/typesense-sync.php` array;
     * null reads the file. Only its keys are checked.
     * @return string[]
     */
    public function getProblems(?array $fileConfig = null): array
    {
        return $this->inspect($fileConfig)['errors'];
    }

    /**
     * Configuration warnings: declarations that are ignored on this install but break nothing,
     * such as a products source without Commerce (BR-5).
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->inspect([])['warnings'];
    }

    /**
     * Whether Commerce is installed and enabled. Checked by handle, so the plugin carries no
     * dependency on it (BR-5).
     */
    protected function isCommerceInstalled(): bool
    {
        return Commerce::isInstalled();
    }

    /**
     * The one validation routine (BR-4).
     *
     * @param array<string, mixed>|null $fileConfig
     * @return array{errors: string[], warnings: string[]}
     */
    private function inspect(?array $fileConfig): array
    {
        $errors = [];
        $warnings = [];

        $fileConfig ??= Craft::$app->getConfig()->getConfigFromFile(TypesenseSync::HANDLE);
        $known = array_flip($this->attributes());

        // Craft drops an unknown key in the config file without a word (TN-14).
        foreach (array_keys($fileConfig) as $key) {
            if (!isset($known[$key])) {
                $errors[] = Craft::t('typesense-sync', '`config/typesense-sync.php` sets an unknown key "{key}".', ['key' => $key]);
            }
        }

        foreach (self::FILE_ONLY as $key) {
            if (array_key_exists($key, $fileConfig) && !is_array($fileConfig[$key])) {
                $errors[] = Craft::t('typesense-sync', '`config/typesense-sync.php` sets "{key}" to something other than an array.', ['key' => $key]);
            }
        }

        $collections = $this->getCollectionConfigs();
        array_push($errors, ...$this->collectionProblems($collections));
        array_push($errors, ...$this->analyticsProblems($collections));

        // Sources are validated as declared, not as getSourceConfigs() filters them, so every
        // malformed or duplicate declaration is named.
        $seen = [];

        foreach ($this->sources as $index => $config) {
            $where = Craft::t('typesense-sync', 'Source {index}', ['index' => is_int($index) ? $index + 1 : $index]);

            if (!is_array($config)) {
                $errors[] = Craft::t('typesense-sync', '{where} is not an array.', ['where' => $where]);
                continue;
            }

            $kind = $config['kind'] ?? SourceConfig::KIND_SECTION;

            if (!in_array($kind, SourceConfig::KINDS, true)) {
                $errors[] = Craft::t('typesense-sync', '{where} has unknown kind "{kind}"; expected one of {kinds}.', [
                    'where' => $where,
                    'kind' => is_scalar($kind) ? (string)$kind : gettype($kind),
                    'kinds' => implode(', ', SourceConfig::KINDS),
                ]);
                continue;
            }

            if ($kind !== SourceConfig::KIND_USERS && (!is_string($config['handle'] ?? null) || $config['handle'] === '')) {
                $errors[] = Craft::t('typesense-sync', '{where} ({kind}) has no handle.', ['where' => $where, 'kind' => $kind]);
                continue;
            }

            foreach (array_diff(array_keys($config), SourceConfig::KEYS) as $key) {
                $errors[] = Craft::t('typesense-sync', '{where} sets an unknown key "{key}".', ['where' => $where, 'key' => $key]);
            }

            $source = $this->makeSource($config);
            if ($source === null) {
                continue;
            }

            $description = $source->getDescription();

            if (isset($seen[$description])) {
                $errors[] = Craft::t('typesense-sync', '{source} is declared more than once.', ['source' => $description]);
                continue;
            }
            $seen[$description] = true;

            if ($kind === SourceConfig::KIND_PRODUCT_TYPE && !$this->isCommerceInstalled()) {
                $warnings[] = Craft::t('typesense-sync', '{source} is ignored because Commerce is not installed.', ['source' => $description]);
                continue;
            }

            if ($kind !== SourceConfig::KIND_SECTION && $source->entryTypes !== []) {
                $errors[] = Craft::t('typesense-sync', '{source} sets entryTypes, which only a section has.', ['source' => $description]);
            }

            foreach ($source->entryTypes as $entryType => $override) {
                foreach (array_diff(array_keys($override), SourceConfig::OVERRIDE_KEYS) as $key) {
                    $errors[] = Craft::t('typesense-sync', '{source}/{entryType} sets an unknown key "{key}".', [
                        'source' => $description,
                        'entryType' => $entryType,
                        'key' => $key,
                    ]);
                }
            }

            if ($source->site !== null && Craft::$app->getSites()->getSiteByHandle($source->site, true) === null) {
                $errors[] = Craft::t('typesense-sync', '{source} names site "{site}", which does not exist.', [
                    'source' => $description,
                    'site' => $source->site,
                ]);
            }

            foreach ($source->resolveAll() as $target) {
                if ($target->enabled) {
                    array_push($errors, ...$this->targetProblems($target, $collections));
                }
            }
        }

        array_push($errors, ...$this->schemaProblems($collections));

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Collection-level problems: unknown keys, unresolvable names, and live-name collisions.
     *
     * @param array<string, CollectionConfig> $collections
     * @return string[]
     */
    private function collectionProblems(array $collections): array
    {
        $errors = [];
        $owners = [];

        foreach ($this->collections as $handle => $config) {
            if (!is_string($handle) || $handle === '' || !is_array($config)) {
                $errors[] = Craft::t('typesense-sync', 'Collection "{handle}" must be an array keyed by a handle.', ['handle' => (string)$handle]);
                continue;
            }

            foreach (array_diff(array_keys($config), CollectionConfig::KEYS) as $key) {
                $errors[] = Craft::t('typesense-sync', 'Collection "{handle}" sets an unknown key "{key}".', ['handle' => $handle, 'key' => $key]);
            }

            foreach (array_diff(array_keys((array)($config['search'] ?? [])), CollectionConfig::SEARCH_KEYS) as $key) {
                $errors[] = Craft::t('typesense-sync', 'Collection "{handle}" sets an unknown search key "{key}".', ['handle' => $handle, 'key' => $key]);
            }
        }

        foreach ($collections as $handle => $collection) {
            if (!$collection->hasName()) {
                $errors[] = Craft::t('typesense-sync', 'Collection "{handle}" is named "{name}", which resolves to nothing.', [
                    'handle' => $handle,
                    'name' => (string)$collection->name,
                ]);
                continue;
            }

            $owners[$collection->getName()][] = $handle;
        }

        foreach ($owners as $name => $handles) {
            if (count($handles) > 1) {
                $errors[] = Craft::t('typesense-sync', 'Collections {handles} all resolve to the live name "{name}".', [
                    'handles' => '"' . implode('", "', $handles) . '"',
                    'name' => $name,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Analytics problems: unknown keys, rules of an unknown type or naming an undeclared
     * collection, and a destination that is empty, shared, or one of the declared collections —
     * which the rule would fill with `{q, count}` documents.
     *
     * @param array<string, CollectionConfig> $collections
     * @return string[]
     */
    private function analyticsProblems(array $collections): array
    {
        $errors = [];

        foreach (array_diff(array_keys($this->analytics), self::ANALYTICS_KEYS) as $key) {
            $errors[] = Craft::t('typesense-sync', 'Analytics sets an unknown key "{key}".', ['key' => $key]);
        }

        if (!array_key_exists('rules', $this->analytics)) {
            return $errors;
        }

        if (!is_array($this->analytics['rules'])) {
            $errors[] = Craft::t('typesense-sync', 'Analytics "rules" must be an array keyed by a handle.');

            return $errors;
        }

        $eventsKey = $this->getAnalyticsEventsKey();

        if ($eventsKey !== '' && $eventsKey === $this->getApiKey()) {
            $errors[] = Craft::t('typesense-sync', 'The analytics events key is the admin API key. It is sent to browsers; create an events-only key with `craft typesense-sync/analytics/create-events-key`.');
        }

        $liveNames = self::liveNamesOf($collections);
        $destinations = [];

        foreach ($this->analytics['rules'] as $handle => $config) {
            if (!is_string($handle) || !is_array($config)) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" must be an array keyed by a handle.', ['handle' => (string)$handle]);
                continue;
            }

            // A digit-led handle would make a destination that reads as a collection version.
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $handle)) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" must start with a letter and hold only letters, digits, "_" and "-".', ['handle' => $handle]);
            }

            foreach (array_diff(array_keys($config), AnalyticsRule::KEYS) as $key) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" sets an unknown key "{key}".', ['handle' => $handle, 'key' => $key]);
            }

            $rule = AnalyticsRule::fromConfig($handle, $config);

            if (!$rule->enabled) {
                continue;
            }

            if (!in_array($rule->type, AnalyticsRule::TYPES, true)) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" has unknown type "{type}"; expected one of {types}.', [
                    'handle' => $handle,
                    'type' => $rule->type,
                    'types' => implode(', ', AnalyticsRule::TYPES),
                ]);
                continue;
            }

            if (!isset($liveNames[$rule->collection])) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" names collection "{collection}", which is not declared.', [
                    'handle' => $handle,
                    'collection' => $rule->collection,
                ]);
                continue;
            }

            if ($rule->isCounter()) {
                if ($rule->counterField === '' || $rule->eventType === '') {
                    $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" needs a counterField and an eventType.', ['handle' => $handle]);
                }

                continue;
            }

            if ($rule->limit < 1) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" has a limit below 1.', ['handle' => $handle]);
            }

            $destination = $rule->getDestination($liveNames[$rule->collection]);

            if ($destination === '') {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" has a destination that resolves to nothing.', ['handle' => $handle]);
                continue;
            }

            if (in_array($destination, $liveNames, true)) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rule "{handle}" aggregates into "{destination}", which is a declared collection.', [
                    'handle' => $handle,
                    'destination' => $destination,
                ]);
            }

            if (isset($destinations[$destination])) {
                $errors[] = Craft::t('typesense-sync', 'Analytics rules "{first}" and "{handle}" both aggregate into "{destination}".', [
                    'first' => $destinations[$destination],
                    'handle' => $handle,
                    'destination' => $destination,
                ]);
            }

            $destinations[$destination] = $handle;
        }

        return $errors;
    }

    /**
     * Problems with one enabled target: where it routes and what builds its documents.
     *
     * @param array<string, CollectionConfig> $collections
     * @return string[]
     */
    private function targetProblems(ResolvedTarget $target, array $collections): array
    {
        $errors = [];
        $where = $target->getDescription();

        if ($target->collection === null) {
            $errors[] = Craft::t('typesense-sync', '{target} routes to no collection.', ['target' => $where]);
        } elseif (!isset($collections[$target->collection])) {
            $errors[] = Craft::t('typesense-sync', '{target} routes to undeclared collection "{collection}".', [
                'target' => $where,
                'collection' => $target->collection,
            ]);
        }

        if ($target->formatter === null) {
            $errors[] = Craft::t('typesense-sync', '{target} declares no formatter.', ['target' => $where]);
        } elseif (!class_exists($target->formatter)) {
            $errors[] = Craft::t('typesense-sync', '{target} declares formatter {class}, which does not exist.', [
                'target' => $where,
                'class' => $target->formatter,
            ]);
        } elseif (!is_subclass_of($target->formatter, FormatterInterface::class)) {
            $errors[] = Craft::t('typesense-sync', '{target} declares formatter {class}, which does not implement {interface}.', [
                'target' => $where,
                'class' => $target->formatter,
                'interface' => FormatterInterface::class,
            ]);
        }

        return $errors;
    }

    /**
     * Problems only the formatters' schemas can show: one field declared two ways in a
     * collection, a reference into an undeclared collection, and reference cycles.
     *
     * @param array<string, CollectionConfig> $collections
     * @return string[]
     */
    private function schemaProblems(array $collections): array
    {
        $errors = [];
        $liveNames = self::liveNamesOf($collections);
        $handlesByName = array_flip($liveNames);
        $references = [];

        foreach ($collections as $handle => $collection) {
            $context = new SchemaContext($handle, $liveNames);
            $fields = [];

            foreach ($this->formattersFor($handle) as $class) {
                try {
                    $formatter = Craft::createObject($class);
                    $schema = $formatter->schema($context);
                } catch (Throwable $e) {
                    $errors[] = Craft::t('typesense-sync', 'Formatter {class} could not give its schema: {message}', [
                        'class' => $class,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                foreach ($schema as $field) {
                    $name = $field['name'] ?? null;

                    if (!is_string($name)) {
                        continue;
                    }

                    $definition = self::comparableField($field);

                    if (isset($fields[$name]) && $fields[$name]['definition'] !== $definition) {
                        $errors[] = Craft::t('typesense-sync', 'Collection "{collection}": formatters {first} and {second} declare field "{field}" differently.', [
                            'collection' => $handle,
                            'first' => $fields[$name]['class'],
                            'second' => $class,
                            'field' => $name,
                        ]);
                        continue;
                    }

                    $fields[$name] ??= ['class' => $class, 'definition' => $definition];
                }
            }

            foreach (array_merge(array_values(array_map(static fn($f) => $f['definition'], $fields)), $collection->schema) as $field) {
                $reference = $field['reference'] ?? null;

                if (!is_string($reference) || $reference === '') {
                    continue;
                }

                $target = strstr($reference, '.', true) ?: $reference;

                if (!isset($handlesByName[$target])) {
                    $errors[] = Craft::t('typesense-sync', 'Collection "{collection}" field "{field}" references "{reference}", which is not a declared collection.', [
                        'collection' => $handle,
                        'field' => (string)($field['name'] ?? '?'),
                        'reference' => $reference,
                    ]);
                    continue;
                }

                $references[$handle][$handlesByName[$target]] = true;
            }
        }

        foreach ($this->referenceCycles($references) as $cycle) {
            $errors[] = Craft::t('typesense-sync', 'Collections reference each other in a cycle: {cycle}. A rebuild could never order them.', [
                'cycle' => implode(' → ', $cycle),
            ]);
        }

        return $errors;
    }

    /**
     * Distinct, usable formatter classes of the enabled targets routing into a collection.
     *
     * @return array<int, class-string<FormatterInterface>>
     */
    private function formattersFor(string $collection): array
    {
        $classes = [];

        foreach ($this->getSourceConfigs() as $source) {
            foreach ($source->resolveAll() as $target) {
                if ($target->enabled
                    && $target->collection === $collection
                    && $target->formatter !== null
                    && is_subclass_of($target->formatter, FormatterInterface::class)
                ) {
                    $classes[$target->formatter] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * A field definition with Typesense's defaults removed and its keys in order, so two ways of
     * writing the same field compare equal.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function comparableField(array $field): array
    {
        foreach (self::FIELD_DEFAULTS as $key => $default) {
            if (array_key_exists($key, $field) && $field[$key] === $default) {
                unset($field[$key]);
            }
        }

        ksort($field);

        return $field;
    }

    /**
     * @param array<string, CollectionConfig> $collections
     * @return array<string, string>
     */
    private static function liveNamesOf(array $collections): array
    {
        $names = [];

        foreach ($collections as $handle => $collection) {
            if ($collection->hasName()) {
                $names[$handle] = $collection->getName();
            }
        }

        return $names;
    }

    /**
     * Each distinct cycle in the reference graph, as the handles along it with the first
     * repeated at the end. A collection referencing itself is a cycle of one: a rebuild of it
     * would bind the new version's references to the version about to be dropped.
     *
     * @param array<string, array<string, true>> $references
     * @return array<int, string[]>
     */
    private function referenceCycles(array $references): array
    {
        $cycles = [];
        $reported = [];
        $done = [];

        $visit = function(string $handle, array $path) use (&$visit, &$cycles, &$reported, &$done, $references): void {
            $position = array_search($handle, $path, true);

            if ($position !== false) {
                $cycle = array_slice($path, (int)$position);
                $key = implode(',', (static function(array $c) {
                    sort($c);
                    return $c;
                })($cycle));

                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    $cycles[] = [...$cycle, $handle];
                }

                return;
            }

            if (isset($done[$handle])) {
                return;
            }

            foreach (array_keys($references[$handle] ?? []) as $next) {
                $visit($next, [...$path, $handle]);
            }

            $done[$handle] = true;
        };

        foreach (array_keys($references) as $handle) {
            $visit($handle, []);
        }

        return $cycles;
    }

    /**
     * A SourceConfig from one raw declaration, or null when it is too malformed to use.
     */
    private function makeSource(mixed $config): ?SourceConfig
    {
        if (!is_array($config)) {
            return null;
        }

        $kind = $config['kind'] ?? SourceConfig::KIND_SECTION;

        if (!in_array($kind, SourceConfig::KINDS, true)) {
            return null;
        }

        $handle = $kind === SourceConfig::KIND_USERS ? '' : ($config['handle'] ?? null);

        if (!is_string($handle) || ($kind !== SourceConfig::KIND_USERS && $handle === '')) {
            return null;
        }

        $entryTypes = [];
        foreach ((array)($config['entryTypes'] ?? []) as $entryType => $override) {
            $entryTypes[(string)$entryType] = (array)$override;
        }

        return new SourceConfig([
            'kind' => $kind,
            'handle' => $handle,
            'enabled' => (bool)($config['enabled'] ?? true),
            'collection' => isset($config['collection']) ? (string)$config['collection'] : null,
            'formatter' => isset($config['formatter']) ? (string)$config['formatter'] : null,
            'priority' => (int)($config['priority'] ?? 100),
            'site' => isset($config['site']) ? (string)$config['site'] : null,
            'entryTypes' => $entryTypes,
        ]);
    }

    /**
     * Marks a target unusable when its collection is undeclared or its formatter is not a
     * formatter, so it queues nothing (TN-3).
     */
    public function markValidity(ResolvedTarget $target): ResolvedTarget
    {
        $target->valid = $target->collection !== null
            && $this->getCollectionConfig($target->collection) !== null
            && $target->formatter !== null
            && is_subclass_of($target->formatter, FormatterInterface::class);

        return $target;
    }

    private function env(string $value): string
    {
        return (string)App::parseEnv($value);
    }
}
