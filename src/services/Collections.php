<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
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
        $settings = $this->settings();
        foreach ((array)($live['fields'] ?? []) as $field) {
            $name = (string)($field['name'] ?? '');

            // Typesense adds the flattened fields of a nested object itself (`address.city`, `.*`).
            if ($name !== '' && !str_contains($name, '.')) {
                $current[$name] = self::normalise($this->aliasedReference($field, $settings));
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
     * Rebuild a collection as its next version and move search onto it, then do the same for
     * every collection that joins into it (BR-15).
     *
     * Typesense binds a reference to the physical collection behind an alias, while a joined
     * search names the alias, so a join from a collection built against the old version finds
     * nothing once the alias moves. Every collection in the run is therefore built first, in
     * reference order, each dependant referencing the new *physical* versions of what it joins
     * into; only then are the aliases swapped, back to back, so joins answer throughout.
     *
     * Each `<name>_<n+1>` is populated by a reindex while its alias keeps serving `<name>_<n>`.
     * If any build fails, every new version is deleted and no alias has moved. After the swaps,
     * every element updated or trashed since its build began is synced again, because the sync
     * its save queued may have written to the old version; then the old versions are dropped.
     *
     * @param callable(string, int, string): void|null $progress Called during each build with the
     * collection handle, the documents written so far and the target being walked.
     * @return list<array{collection: string, from: ?string, to: string, indexed: int, rejected: int,
     *     resynced: int, dropped: ?string}> One entry per collection rebuilt, in the order rebuilt.
     * @throws SyncException when a build fails; nothing has then changed.
     * @throws InvalidConfigException when the config has problems (BR-4), including a cycle.
     */
    public function recreate(string $collection, ?callable $progress = null): array
    {
        $this->config($collection);
        $problems = $this->settings()->getProblems();

        if ($problems !== []) {
            throw new InvalidConfigException(sprintf(
                'Nothing was recreated: the config has problems. %s',
                implode(' ', $problems),
            ));
        }

        $plan = [$collection, ...$this->getDependants($collection)];
        $mutex = Craft::$app->getMutex();
        $locked = [];

        try {
            foreach ($plan as $handle) {
                if (!$mutex->acquire($this->lockName($handle))) {
                    throw new SyncException(sprintf('Nothing was recreated: "%s" is already being recreated.', $handle));
                }

                $locked[] = $handle;
            }

            return $this->recreatePlan($plan, $progress);
        } finally {
            foreach ($locked as $handle) {
                $mutex->release($this->lockName($handle));
            }
        }
    }

    /**
     * The collections that hold a reference into this one, by handle.
     *
     * @return string[]
     * @throws InvalidConfigException
     */
    public function referencingCollections(string $collection): array
    {
        $handles = [];

        foreach (array_keys($this->settings()->getCollectionConfigs()) as $handle) {
            if ($handle !== $collection && in_array($collection, $this->referencedBy($handle), true)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Every collection that joins into this one, directly or through another, in the order a
     * recreate rebuilds them: each after every collection it references.
     *
     * @return string[]
     * @throws InvalidConfigException when the references form a cycle, which no order satisfies.
     */
    public function getDependants(string $collection): array
    {
        $found = [];
        $pending = [$collection];

        while ($pending !== []) {
            foreach ($this->referencingCollections((string)array_shift($pending)) as $handle) {
                if ($handle !== $collection && !isset($found[$handle])) {
                    $found[$handle] = true;
                    $pending[] = $handle;
                }
            }
        }

        // Rebuild a collection only once everything it references in this run is rebuilt.
        $inRun = [$collection, ...array_keys($found)];
        $order = [];
        $done = [$collection];
        $left = array_keys($found);

        while ($left !== []) {
            $ready = array_values(array_filter(
                $left,
                fn(string $handle) => array_diff(array_intersect($this->referencedBy($handle), $inRun), $done) === [],
            ));

            if ($ready === []) {
                throw new InvalidConfigException(sprintf(
                    'Collections reference each other in a cycle (%s), so no recreate order exists.',
                    implode(', ', $left),
                ));
            }

            array_push($order, ...$ready);
            array_push($done, ...$ready);
            $left = array_values(array_diff($left, $ready));
        }

        return $order;
    }

    /**
     * Build every version in the plan, then swap them all in, re-sync, and drop the old ones.
     *
     * @param string[] $plan The collection first, then its dependants in reference order.
     * @param callable(string, int, string): void|null $progress
     * @return list<array{collection: string, from: ?string, to: string, indexed: int, rejected: int,
     *     resynced: int, dropped: ?string}>
     * @throws SyncException
     * @throws InvalidConfigException
     */
    private function recreatePlan(array $plan, ?callable $progress): array
    {
        $builds = [];
        // Live name => the new physical version, for the references of the collections after it.
        $versions = [];

        try {
            foreach ($plan as $handle) {
                $build = $this->build($handle, $versions, $progress);
                $builds[] = $build;
                $versions[$build['alias']] = $build['to'];
            }
        } catch (SyncException|InvalidConfigException $e) {
            // No alias has moved, so every version this run made can go.
            foreach ($builds as $build) {
                $this->deleteQuietly($build['to']);
            }

            if ($builds !== []) {
                $e = $this->failure(sprintf(
                    'Nothing was recreated; %s built for this run %s deleted',
                    implode(', ', array_column($builds, 'to')),
                    count($builds) === 1 ? 'was' : 'were',
                ), $e);
            }

            throw $e;
        }

        // Back to back, in reference order: until the last swap, a join from a new version
        // through the alias of an old one finds nothing.
        foreach ($builds as $build) {
            try {
                $this->client()->aliases->upsert($build['alias'], ['collection_name' => $build['to']]);
            } catch (Throwable $e) {
                throw $this->failure(sprintf(
                    'Could not point %s at %s; the aliases before it were swapped (%s). Recreate "%s" again',
                    $build['alias'],
                    $build['to'],
                    implode(', ', array_column(array_slice($builds, 0, (int)array_search($build, $builds, true)), 'alias')) ?: 'none',
                    $plan[0],
                ), $e);
            }
        }

        $results = [];

        foreach ($builds as $build) {
            $results[] = [
                'collection' => $build['collection'],
                'from' => $build['from'],
                'to' => $build['to'],
                'indexed' => $build['indexed'],
                'rejected' => $build['rejected'],
                'resynced' => $this->resyncSince($build['collection'], $build['since']),
                'dropped' => null,
            ];
        }

        // Dependants first, so nothing is left referencing a collection that has gone.
        foreach (array_reverse(array_keys($results)) as $index) {
            $results[$index]['dropped'] = $this->dropOldVersions($results[$index]['collection'], $results[$index]['to']);
        }

        return $results;
    }

    /**
     * Create and populate the next version of one collection beside the live one. The alias is
     * not moved.
     *
     * @param array<string, string> $versions Live name => physical version to reference instead,
     * for collections rebuilt earlier in the run.
     * @param callable(string, int, string): void|null $progress
     * @return array{collection: string, alias: string, from: ?string, to: string, indexed: int, rejected: int, since: DateTime}
     * @throws SyncException when the build fails; the new version has then been deleted.
     * @throws InvalidConfigException
     */
    private function build(string $collection, array $versions, ?callable $progress): array
    {
        $alias = $this->config($collection)->getName();

        if ($this->getDesiredFields($collection) === []) {
            throw new SyncException(sprintf('Nothing enabled routes into "%s", so there is nothing to recreate it from.', $collection));
        }

        $previous = $this->getActiveCollectionName($collection);
        $existing = $this->listCollectionNames();

        if ($previous === null && in_array($alias, $existing, true)) {
            throw new SyncException(sprintf('A collection named "%s" already exists and is not an alias, so "%s" cannot be recreated behind it.', $alias, $collection));
        }

        $liveDocuments = $previous !== null ? $this->documentCount($previous) : 0;
        $schema = $this->buildSchema($collection, $this->nextVersion($collection, $existing));
        $schema['fields'] = array_map(static function(array $field) use ($versions): array {
            $reference = (string)($field['reference'] ?? '');
            $joined = strstr($reference, '.', true);

            if ($joined !== false && isset($versions[$joined])) {
                $field['reference'] = $versions[$joined] . substr($reference, strlen($joined));
            }

            return $field;
        }, $schema['fields']);
        $name = (string)$schema['name'];
        // Second precision in the database, so step back one: re-syncing too much is harmless.
        $since = DateTimeHelper::now()->modify('-1 second');

        try {
            $this->client()->collections->create($schema);
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not create "%s"', $name), $e);
        }

        try {
            $run = $this->sync()->reindex($collection, $name, $progress === null ? null : static function(int $indexed, string $target) use ($progress, $collection): void {
                $progress($collection, $indexed, $target);
            });

            if ($run['failed'] > 0) {
                throw new SyncException(sprintf('%d documents could not be written to %s', $run['failed'], $name));
            }

            // The same guard as a prune on an empty run: a broken query must not empty search.
            if ($run['ids'] === [] && $liveDocuments > 0) {
                throw new SyncException(sprintf('the build made no documents, while %s holds %d', $previous, $liveDocuments));
            }
        } catch (Throwable $e) {
            $this->deleteQuietly($name);

            throw $this->failure(sprintf('Could not build "%s"; %s was deleted and %s still serves %s', $collection, $name, $alias, $previous ?? 'nothing'), $e);
        }

        return [
            'collection' => $collection,
            'alias' => $alias,
            'from' => $previous,
            'to' => $name,
            'indexed' => $run['indexed'],
            'rejected' => $run['rejected'],
            'since' => $since,
        ];
    }

    /**
     * Delete a collection this run made, logging rather than throwing if it cannot be.
     */
    private function deleteQuietly(string $name): void
    {
        try {
            $this->client()->collections[$name]->delete();
        } catch (Throwable $e) {
            Craft::warning(sprintf('Could not delete the unfinished %s: %s', $name, $e->getMessage()), TypesenseSync::HANDLE);
        }
    }

    /**
     * Sync again every element of a collection's targets updated or trashed since a moment, now
     * that the alias points at the new version. Their queued syncs may have run during the build
     * and written to the old one.
     *
     * @return int Elements synced.
     */
    private function resyncSince(string $collection, DateTime $since): int
    {
        $sync = $this->sync();
        $synced = 0;

        foreach ($this->settings()->getTargetsForCollection($collection) as $target) {
            $queries = [
                $sync->queryForTarget($target)?->dateUpdated('>= ' . $since->format(DateTime::ATOM)),
                $sync->queryForTarget($target)?->trashed(true)->andWhere(['>=', 'elements.dateDeleted', Db::prepareDateForDb($since)]),
            ];

            foreach ($queries as $query) {
                foreach ($query?->all() ?? [] as $element) {
                    try {
                        $sync->syncElement($element);
                        $synced++;
                    } catch (Throwable $e) {
                        Craft::error(sprintf('Element %s was not re-synced into the recreated "%s": %s', $element->id, $collection, $e->getMessage()), TypesenseSync::HANDLE);
                    }
                }
            }
        }

        return $synced;
    }

    /**
     * Delete every version of a collection except the one given, including any a failed run left
     * behind. A failure is logged, not thrown: search already uses the new version.
     *
     * @return string|null The first version dropped, or null when none was.
     */
    private function dropOldVersions(string $collection, string $keep): ?string
    {
        $config = $this->config($collection);
        $dropped = null;

        try {
            $names = $this->listCollectionNames();
        } catch (SyncException $e) {
            Craft::warning(sprintf('Old versions of "%s" were not dropped: %s', $collection, $e->getMessage()), TypesenseSync::HANDLE);

            return null;
        }

        foreach ($names as $name) {
            if ($name === $keep || $config->getVersionFromName($name) === null) {
                continue;
            }

            try {
                $this->client()->collections[$name]->delete();
                $dropped ??= $name;
            } catch (Throwable $e) {
                Craft::warning(sprintf('Could not drop the old collection %s: %s', $name, $e->getMessage()), TypesenseSync::HANDLE);
            }
        }

        return $dropped;
    }

    /**
     * Handles of the other declared collections this one's desired schema references.
     *
     * @return string[]
     * @throws InvalidConfigException
     */
    private function referencedBy(string $collection): array
    {
        $handlesByName = array_flip($this->settings()->getLiveNames());
        $referenced = [];

        foreach ($this->getDesiredFields($collection) as $field) {
            $reference = (string)($field['reference'] ?? '');
            $target = $handlesByName[strstr($reference, '.', true) ?: $reference] ?? null;

            if ($reference !== '' && $target !== null && $target !== $collection) {
                $referenced[$target] = true;
            }
        }

        return array_keys($referenced);
    }

    /**
     * @throws SyncException
     */
    private function documentCount(string $name): int
    {
        try {
            return (int)($this->client()->collections[$name]->retrieve()['num_documents'] ?? 0);
        } catch (ObjectNotFound) {
            return 0;
        } catch (Throwable $e) {
            throw $this->failure(sprintf('Could not read collection "%s"', $name), $e);
        }
    }

    private function lockName(string $collection): string
    {
        return TypesenseSync::HANDLE . ':recreate:' . $collection;
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
     * A retrieved field whose reference names a version of a declared collection (as a recreate
     * builds its dependants: `people_2.id`), rewritten to name the live alias (`people.id`), which
     * is what the declaration says.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function aliasedReference(array $field, Settings $settings): array
    {
        $reference = (string)($field['reference'] ?? '');
        $joined = strstr($reference, '.', true);

        if ($joined === false) {
            return $field;
        }

        foreach ($settings->getCollectionConfigs() as $config) {
            if ($config->hasName() && $config->getVersionFromName($joined) !== null) {
                $field['reference'] = $config->getName() . substr($reference, strlen($joined));
                break;
            }
        }

        return $field;
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

    private function sync(): Sync
    {
        return TypesenseSync::getInstance()->sync;
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
