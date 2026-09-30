<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use Throwable;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\formatters\SchemaContext;
use webdna\typesensesync\models\CollectionConfig;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\TypesenseSync;
use yii\base\InvalidConfigException;

/**
 * Creates the declared collections and keeps their schemas in step with the formatters (BR-16).
 *
 * The name search uses is always an alias (BR-3), pointing at a versioned physical collection
 * (`content` → `content_1`). Apply creates the first version and its alias, or alters the live
 * version in place. What an alter cannot express — a different `default_sorting_field` or
 * `enable_nested_fields` — needs a recreate, which builds the next version beside the live one.
 *
 * The desired schema is the union of the `schema()` of every formatter routed into the
 * collection, plus the collection's own `schema` extras from the config file, which win.
 *
 * Only "not found" from the server ever reads as absent. Any other failure throws, because
 * mistaking an unreachable alias for a missing one would create an empty version and point
 * search at it.
 *
 * @since 1.0.0
 */
class Collections extends Component
{
    /**
     * Created the first version and its alias.
     */
    public const ACTION_CREATE = 'create';

    /**
     * Altered the live version in place.
     */
    public const ACTION_ALTER = 'alter';

    /**
     * Already matches the desired schema.
     */
    public const ACTION_NONE = 'none';

    /**
     * Nothing enabled routes into the collection, so it has no fields to create.
     */
    public const ACTION_SKIP = 'skip';

    /**
     * The difference is one an alter cannot make; only a recreate can.
     */
    public const ACTION_NEEDS_RECREATE = 'needs-recreate';

    /**
     * The live name is taken by a collection that is not an alias, so no alias can be made.
     */
    public const ACTION_BLOCKED = 'blocked';

    /**
     * Types Typesense makes sortable when a declaration does not say (checked against 30.0,
     * which sorts a geopoint by distance).
     */
    private const SORTABLE_BY_DEFAULT = ['int32', 'int64', 'float', 'bool', 'geopoint'];

    /**
     * The union of every routed formatter's schema, plus the collection's config extras, sorted
     * by field name.
     *
     * @return array<int, array<string, mixed>>
     * @throws InvalidConfigException for an undeclared collection, or two formatters declaring
     * one field differently (also reported by Settings::getProblems(), BR-4).
     */
    public function getDesiredFields(string $collection): array
    {
        $settings = $this->settings();
        $config = $this->config($collection);
        $context = new SchemaContext($collection, $settings->getLiveNames());

        $fields = [];
        $declaredBy = [];

        foreach ($settings->getTargetsForCollection($collection) as $target) {
            $formatter = $this->targets()->formatterFor($target);

            foreach ($formatter->schema($context) as $field) {
                $name = $field['name'] ?? null;

                if (!is_string($name) || $name === '') {
                    continue;
                }

                if (isset($fields[$name]) && Settings::comparableField($fields[$name]) !== Settings::comparableField($field)) {
                    throw new InvalidConfigException(sprintf(
                        'Collection "%s": formatters %s and %s declare field "%s" differently.',
                        $collection,
                        $declaredBy[$name],
                        $formatter::class,
                        $name,
                    ));
                }

                $fields[$name] ??= $field;
                $declaredBy[$name] ??= $formatter::class;
            }
        }

        // Only while something routes here: a collection whose sources are all off has no schema.
        if ($fields !== []) {
            foreach ($config->schema as $field) {
                if (is_string($field['name'] ?? null) && $field['name'] !== '') {
                    $fields[$field['name']] = $field;
                }
            }
        }

        ksort($fields);

        return array_values($fields);
    }

    /**
     * The create payload for one version of a collection.
     *
     * @return array<string, mixed>
     * @throws InvalidConfigException
     */
    public function buildSchema(string $collection, int $version): array
    {
        $config = $this->config($collection);

        $schema = [
            'name' => $config->getVersionedName($version),
            'fields' => $this->getDesiredFields($collection),
            'enable_nested_fields' => $config->enableNestedFields,
        ];

        if ($config->defaultSortingField !== null && $config->defaultSortingField !== '') {
            $schema['default_sorting_field'] = $config->defaultSortingField;
        }

        return $schema;
    }

    /**
     * The physical collection the alias points at, or null when there is no alias.
     *
     * @throws SyncException when the server cannot be asked.
     * @throws InvalidConfigException
     */
    public function getActiveCollectionName(string $collection): ?string
    {
        $alias = $this->config($collection)->getName();

        try {
            $name = $this->client()->aliases[$alias]->retrieve()['collection_name'] ?? null;
        } catch (ObjectNotFound) {
            return null;
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not read alias "%s"', $alias), $e);
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The schema of the version the alias points at, or null when nothing is live: no alias, or
     * an alias whose collection has gone.
     *
     * @return array<string, mixed>|null
     * @throws SyncException
     * @throws InvalidConfigException
     */
    public function getLiveSchema(string $collection): ?array
    {
        $name = $this->getActiveCollectionName($collection);

        if ($name === null) {
            return null;
        }

        try {
            return $this->client()->collections[$name]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not read collection "%s"', $name), $e);
        }
    }

    /**
     * The difference between the desired schema and the live one.
     *
     * `needsRecreate` is set, with its reasons, when the difference is one an alter cannot make.
     * `upToDate` is true only for a live collection with no difference at all.
     *
     * @return array{exists: bool, collection: ?string, added: list<string>, dropped: list<string>,
     *     changed: array<string, array{from: array<string, mixed>, to: array<string, mixed>}>,
     *     needsRecreate: bool, recreateReasons: list<string>, upToDate: bool, documents: int}
     * @throws SyncException
     * @throws InvalidConfigException
     */
    public function diff(string $collection): array
    {
        $config = $this->config($collection);

        $desired = [];
        foreach ($this->getDesiredFields($collection) as $field) {
            $desired[(string)$field['name']] = self::normalise($field);
        }

        $live = $this->getLiveSchema($collection);

        if ($live === null) {
            return [
                'exists' => false,
                'collection' => null,
                'added' => array_keys($desired),
                'dropped' => [],
                'changed' => [],
                'needsRecreate' => false,
                'recreateReasons' => [],
                'upToDate' => false,
                'documents' => 0,
            ];
        }

        $current = [];
        foreach ((array)($live['fields'] ?? []) as $field) {
            $name = (string)($field['name'] ?? '');

            // Typesense adds the flattened fields of a nested object itself (`address.city`, `.*`).
            if ($name !== '' && !str_contains($name, '.')) {
                $current[$name] = self::normalise($field);
            }
        }

        $added = array_values(array_diff(array_keys($desired), array_keys($current)));
        $dropped = array_values(array_diff(array_keys($current), array_keys($desired)));

        $changed = [];
        foreach (array_intersect(array_keys($desired), array_keys($current)) as $name) {
            if ($desired[$name] !== $current[$name]) {
                $changed[$name] = ['from' => $current[$name], 'to' => $desired[$name]];
            }
        }

        // Typesense reports "no default sorting field" as ''.
        $liveSorting = (string)($live['default_sorting_field'] ?? '');
        $desiredSorting = (string)$config->defaultSortingField;
        $liveNested = (bool)($live['enable_nested_fields'] ?? false);

        $reasons = [];

        if ($liveSorting !== $desiredSorting) {
            $reasons[] = sprintf(
                'default_sorting_field is "%s" and should be "%s"',
                $liveSorting === '' ? '(none)' : $liveSorting,
                $desiredSorting === '' ? '(none)' : $desiredSorting,
            );
        }

        if ($liveNested !== $config->enableNestedFields) {
            $reasons[] = sprintf(
                'enable_nested_fields is %s and should be %s',
                $liveNested ? 'on' : 'off',
                $config->enableNestedFields ? 'on' : 'off',
            );
        }

        return [
            'exists' => true,
            'collection' => (string)$live['name'],
            'added' => $added,
            'dropped' => $dropped,
            'changed' => $changed,
            'needsRecreate' => $reasons !== [],
            'recreateReasons' => $reasons,
            'upToDate' => $added === [] && $dropped === [] && $changed === [] && $reasons === [],
            'documents' => (int)($live['num_documents'] ?? 0),
        ];
    }

    /**
     * Create the collection and its alias if nothing is live, else alter the live version to
     * match the desired schema (BR-16). Never reindexes: `reindex` says whether one is needed.
     * A dry run makes the same decision and changes nothing.
     *
     * @return array{action: string, collection: ?string, payload: ?array<string, mixed>, message: string, reindex: bool}
     * @throws SyncException when the server cannot be asked, or refuses the change.
     * @throws InvalidConfigException
     */
    public function apply(string $collection, bool $dryRun = false): array
    {
        $config = $this->config($collection);
        $alias = $config->getName();

        if ($this->getDesiredFields($collection) === []) {
            return $this->result(self::ACTION_SKIP, null, null, false, sprintf(
                'Nothing enabled routes into "%s", so it has no fields to create. Declare an enabled source for it first.',
                $collection,
            ));
        }

        $diff = $this->diff($collection);

        if (!$diff['exists']) {
            $existing = $this->listCollectionNames();

            if (in_array($alias, $existing, true)) {
                return $this->result(self::ACTION_BLOCKED, $alias, null, false, sprintf(
                    'A collection named "%s" already exists and is not an alias, so "%s" cannot be created. '
                    . 'Delete that collection, or give "%s" another name.',
                    $alias,
                    $alias,
                    $collection,
                ));
            }

            $schema = $this->buildSchema($collection, $this->nextVersion($collection, $existing));
            $name = (string)$schema['name'];
            $count = count($schema['fields']);

            if ($dryRun) {
                return $this->result(self::ACTION_CREATE, $name, $schema, true, sprintf(
                    'Would create %s (%d fields) and alias %s to it.',
                    $name,
                    $count,
                    $alias,
                ));
            }

            try {
                $this->client()->collections->create($schema);
                $this->client()->aliases->upsert($alias, ['collection_name' => $name]);
            } catch (Throwable $e) {
                throw $this->failure(sprintf('Could not create "%s"', $name), $e);
            }

            return $this->result(self::ACTION_CREATE, $name, $schema, true, sprintf(
                'Created %s (%d fields), aliased as %s. It is empty until a sync fills it.',
                $name,
                $count,
                $alias,
            ));
        }

        $name = (string)$diff['collection'];

        if ($diff['needsRecreate']) {
            return $this->result(self::ACTION_NEEDS_RECREATE, $name, null, false, sprintf(
                '%s cannot be altered to match: %s. Recreate the collection instead.',
                $name,
                implode('; ', $diff['recreateReasons']),
            ));
        }

        if ($diff['upToDate']) {
            return $this->result(self::ACTION_NONE, $name, null, false, sprintf('%s is up to date.', $name));
        }

        $desired = [];
        foreach ($this->getDesiredFields($collection) as $field) {
            $desired[(string)$field['name']] = $field;
        }

        // Typesense cannot retype a field in place: a changed field is dropped and added again
        // in the same request.
        $changed = array_keys($diff['changed']);
        $fields = [];

        foreach ([...$diff['dropped'], ...$changed] as $field) {
            $fields[] = ['name' => $field, 'drop' => true];
        }

        foreach ([...$diff['added'], ...$changed] as $field) {
            $fields[] = $desired[$field];
        }

        $payload = ['fields' => $fields];
        $reindex = $diff['added'] !== [] || $changed !== [];
        $summary = sprintf('+%d, -%d, ~%d', count($diff['added']), count($diff['dropped']), count($changed));
        $advice = $reindex ? ' Reindex it so existing documents carry the new fields.' : '';

        if ($dryRun) {
            return $this->result(self::ACTION_ALTER, $name, $payload, $reindex, sprintf('Would alter %s: %s.%s', $name, $summary, $advice));
        }

        try {
            $this->client()->collections[$name]->update($payload);
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not alter "%s"', $name), $e);
        }

        return $this->result(self::ACTION_ALTER, $name, $payload, $reindex, sprintf('Altered %s: %s.%s', $name, $summary, $advice));
    }

    /**
     * The next unused version number: one past the highest on the server, alias or not, so a
     * version left behind by a failed run is never reused.
     *
     * @param string[]|null $existing Physical collection names, if already listed.
     * @throws SyncException
     * @throws InvalidConfigException
     */
    public function nextVersion(string $collection, ?array $existing = null): int
    {
        $config = $this->config($collection);
        $highest = 0;

        foreach ($existing ?? $this->listCollectionNames() as $name) {
            $highest = max($highest, $config->getVersionFromName($name) ?? 0);
        }

        return $highest + 1;
    }

    /**
     * A field definition reduced to the attributes compared, with Typesense's defaults filled
     * in, so a sparse declaration and a retrieved field compare equal.
     *
     * The reference attributes are compared only on a reference field. `cascade_delete`
     * defaults to on, which deletes the referencing documents, so a live field that has it must
     * show as changed rather than pass as up to date.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function normalise(array $field): array
    {
        $type = (string)($field['type'] ?? '');

        $normalised = [
            'type' => $type,
            'facet' => (bool)($field['facet'] ?? false),
            'optional' => (bool)($field['optional'] ?? false),
            'index' => (bool)($field['index'] ?? true),
            'sort' => (bool)($field['sort'] ?? in_array($type, self::SORTABLE_BY_DEFAULT, true)),
            'infix' => (bool)($field['infix'] ?? false),
            'stem' => (bool)($field['stem'] ?? false),
            'store' => (bool)($field['store'] ?? true),
            'locale' => (string)($field['locale'] ?? ''),
        ];

        if ((string)($field['reference'] ?? '') !== '') {
            $normalised['reference'] = (string)$field['reference'];
            $normalised['async_reference'] = (bool)($field['async_reference'] ?? false);
            $normalised['cascade_delete'] = (bool)($field['cascade_delete'] ?? true);
        }

        return $normalised;
    }

    /**
     * Every physical collection on the server, by name.
     *
     * @return string[]
     * @throws SyncException
     */
    private function listCollectionNames(): array
    {
        try {
            $collections = $this->client()->collections->retrieve();
        } catch (Throwable $e) {
            throw $this->failure('Could not list collections', $e);
        }

        return array_values(array_filter(
            array_map(static fn($c) => is_array($c) ? (string)($c['name'] ?? '') : '', (array)$collections),
            static fn(string $name) => $name !== '',
        ));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{action: string, collection: ?string, payload: ?array<string, mixed>, message: string, reindex: bool}
     */
    private function result(string $action, ?string $collection, ?array $payload, bool $reindex, string $message): array
    {
        return [
            'action' => $action,
            'collection' => $collection,
            'payload' => $payload,
            'message' => $message,
            'reindex' => $reindex,
        ];
    }

    /**
     * @throws InvalidConfigException for an undeclared collection.
     */
    private function config(string $collection): CollectionConfig
    {
        return $this->settings()->getCollectionConfig($collection)
            ?? throw new InvalidConfigException(sprintf('No collection "%s" is declared in config/typesense-sync.php.', $collection));
    }

    /**
     * @throws SyncException when the plugin has no connection.
     */
    private function client(): TypesenseClient
    {
        return TypesenseSync::getInstance()->client->getClient()
            ?? throw new SyncException('Typesense is not configured: set a host and an admin API key.');
    }

    private function failure(string $what, Throwable $e): SyncException
    {
        $message = sprintf('%s: %s', $what, $e->getMessage());
        Craft::error($message, TypesenseSync::HANDLE);

        return new SyncException($message, 0, $e);
    }

    private function targets(): Targets
    {
        return TypesenseSync::getInstance()->targets;
    }

    private function settings(): Settings
    {
        return $this->targets()->getSettings();
    }
}
