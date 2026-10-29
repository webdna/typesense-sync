<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use Throwable;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\jobs\PushRules;
use webdna\typesensesync\models\CollectionConfig;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\SourceConfig;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\TypesenseSync;
use yii\base\InvalidConfigException;

/**
 * Curation rules and synonym groups: the rows editors keep in Craft, and the one curation set and
 * one synonym set per collection that Typesense holds as a copy of them (BR-1).
 *
 * Craft is the source of truth (BR-14). A push PUTs a collection's whole set, built from the rows
 * every time, so a push can be repeated and the later of two pushes always equals the stored rows
 * (TN-13). Typesense evaluates a set's items in lexical id order, so the ids (`rule-0001`…) are the
 * ordering and are derived on every push, never stored (BR-5). Pins and hides are element ids; each
 * becomes a document id through the collection's formatter at push time (BR-8).
 *
 * Row writes here do not push: the caller saves, then calls pushOrQueue(), so a failed push never
 * undoes an editor's save (BR-4). Only "not found" reads as absent; any other failure throws, as in
 * Collections and Analytics.
 *
 * @since 1.1.0
 */
class Rules extends Component
{
    /**
     * The curation set: pins and hides.
     */
    public const KIND_RULES = 'rules';

    /**
     * The synonym set.
     */
    public const KIND_SYNONYMS = 'synonyms';

    public const KINDS = [self::KIND_RULES, self::KIND_SYNONYMS];

    /**
     * Typesense's set equals the desired one and the live collection links it.
     */
    public const STATE_IN_STEP = 'in-step';

    /**
     * The set or the link is missing, or a push failed and is waiting to be retried.
     */
    public const STATE_NOT_LIVE = 'not-live';

    /**
     * Typesense's set differs from the desired one: someone changed it outside Craft.
     */
    public const STATE_OUT_OF_STEP = 'out-of-step';

    /**
     * Typesense could not be asked.
     */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * How long a failed push marks a collection as waiting: the retry job's whole run, with slack.
     */
    private const PENDING_TTL = PushRules::ATTEMPTS * PushRules::TTR + 60;

    /**
     * The collection-schema key that lists each kind's linked sets.
     */
    public const LINK_KEYS = [
        self::KIND_RULES => 'curation_sets',
        self::KIND_SYNONYMS => 'synonym_sets',
    ];

    // Rows ---------------------------------------------------------------------------------------

    /**
     * A collection's rules in the editor's order.
     *
     * @return Rule[]
     */
    public function getRules(string $collection): array
    {
        /** @var RuleRecord[] $records */
        $records = RuleRecord::find()->where(['collection' => $collection])->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])->all();

        return array_map(fn(RuleRecord $record) => Rule::fromRecord($record), $records);
    }

    public function getRuleById(int $id): ?Rule
    {
        $record = RuleRecord::findOne($id);

        return $record !== null ? Rule::fromRecord($record) : null;
    }

    /**
     * A collection's synonym groups in display order.
     *
     * @return SynonymGroup[]
     */
    public function getSynonymGroups(string $collection): array
    {
        /** @var SynonymRecord[] $records */
        $records = SynonymRecord::find()->where(['collection' => $collection])->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])->all();

        return array_map(fn(SynonymRecord $record) => SynonymGroup::fromRecord($record), $records);
    }

    public function getSynonymGroupById(int $id): ?SynonymGroup
    {
        $record = SynonymRecord::findOne($id);

        return $record !== null ? SynonymGroup::fromRecord($record) : null;
    }

    /**
     * Validate and store a rule; a new one goes to the end of its collection's list. Refused
     * when any pinned or hidden element does not feed the collection (BR-8). Does not push.
     */
    public function saveRule(Rule $rule): bool
    {
        if (!$rule->validate()) {
            return false;
        }

        $outside = $this->elementsOutside($rule->collection, array_merge($rule->pins, $rule->hides));

        if ($outside !== []) {
            $rule->addError(in_array($outside[0], $rule->pins, true) ? 'pins' : 'hides', Craft::t('typesense-sync', 'Only entries that feed this collection can be pinned or hidden.'));

            return false;
        }

        $record = $rule->id !== null ? RuleRecord::findOne($rule->id) : null;

        if ($record === null) {
            $record = new RuleRecord();
            $rule->sortOrder = (int)RuleRecord::find()->where(['collection' => $rule->collection])->max('[[sortOrder]]') + 1;
        }

        $rule->populateRecord($record);

        if (!$record->save(false)) {
            return false;
        }

        $rule->id = (int)$record->id;
        $rule->uid = $record->uid;

        return true;
    }

    /**
     * Validate and store a synonym group; a new one goes to the end. Does not push.
     */
    public function saveSynonymGroup(SynonymGroup $group): bool
    {
        if (!$group->validate()) {
            return false;
        }

        $record = $group->id !== null ? SynonymRecord::findOne($group->id) : null;

        if ($record === null) {
            $record = new SynonymRecord();
            $group->sortOrder = (int)SynonymRecord::find()->where(['collection' => $group->collection])->max('[[sortOrder]]') + 1;
        }

        $group->populateRecord($record);

        if (!$record->save(false)) {
            return false;
        }

        $group->id = (int)$record->id;
        $group->uid = $record->uid;

        return true;
    }

    /**
     * Delete one row of either kind. Does not push.
     */
    public function deleteRow(string $kind, int $id): bool
    {
        $record = $kind === self::KIND_RULES ? RuleRecord::findOne($id) : SynonymRecord::findOne($id);

        return $record !== null && $record->delete() !== false;
    }

    /**
     * Number a collection's rows 1… in the order given. Ids not in the collection are ignored;
     * rows not named keep their order after the named ones. Does not push.
     *
     * @param int[] $ids
     */
    public function reorder(string $kind, string $collection, array $ids): void
    {
        $class = $kind === self::KIND_RULES ? RuleRecord::class : SynonymRecord::class;
        $current = $class::find()->select(['id'])->where(['collection' => $collection])->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])->column();
        $current = array_map('intval', $current);
        $ids = array_values(array_intersect(array_map('intval', $ids), $current));
        $order = array_merge($ids, array_values(array_diff($current, $ids)));

        foreach ($order as $index => $id) {
            $class::updateAll(['sortOrder' => $index + 1], ['id' => $id]);
        }
    }

    /**
     * Rows kept for collections the config no longer declares (BR-21): neither shown in the section
     * nor pushed, and deleted only by `rules/prune`.
     *
     * @return array<string, array{rules: int, synonyms: int}> By collection handle, sorted.
     */
    public function orphans(): array
    {
        $declared = array_keys(TypesenseSync::getInstance()->targets->getSettings()->getCollectionConfigs());
        $orphans = [];

        foreach ([self::KIND_RULES => RuleRecord::class, self::KIND_SYNONYMS => SynonymRecord::class] as $kind => $class) {
            $counts = $class::find()
                ->select(['collection', 'count' => 'COUNT(*)'])
                ->where(['not in', 'collection', $declared])
                ->groupBy(['collection'])
                ->asArray()
                ->all();

            foreach ($counts as $count) {
                $handle = (string)$count['collection'];
                $orphans[$handle] ??= [self::KIND_RULES => 0, self::KIND_SYNONYMS => 0];
                $orphans[$handle][$kind] = (int)$count['count'];
            }
        }

        ksort($orphans);

        return $orphans;
    }

    /**
     * What the Pin and Hide pickers offer for a collection (BR-8): one picker per element type and
     * site, limited to the sources whose targets route into the collection, entry-type overrides
     * included. A picker cannot tell entry types apart, so an entry type routed elsewhere is still
     * offered; saveRule() refuses it.
     *
     * @return list<array{elementType: class-string<ElementInterface>, label: string, siteId: int, sources: list<string>}>
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function pickers(string $collection): array
    {
        $this->config($collection);
        $targets = TypesenseSync::getInstance()->targets;
        $pickers = [];

        foreach ($targets->getSettings()->getTargetsForCollection($collection) as $target) {
            $site = $targets->siteFor($target);
            $source = $target->source !== null ? self::pickerSource($target->source) : null;

            if ($site === null || $source === null) {
                continue;
            }

            [$elementType, $key] = $source;
            $id = $elementType . ':' . $site->id;
            $pickers[$id] ??= ['elementType' => $elementType, 'label' => $elementType::pluralDisplayName(), 'siteId' => (int)$site->id, 'sources' => []];

            if (!in_array($key, $pickers[$id]['sources'], true)) {
                $pickers[$id]['sources'][] = $key;
            }
        }

        return array_values($pickers);
    }

    /**
     * The element, in the site its collection indexes, when it feeds the collection — the BR-8 test
     * a save applies, for callers that resolve elements themselves (export, import).
     */
    public function elementFor(string $collection, int $elementId): ?ElementInterface
    {
        return $this->elementFeeding($collection, $elementId)[0] ?? null;
    }

    // What Typesense is sent ---------------------------------------------------------------------

    /**
     * The set a collection owns for a kind: its live alias plus `_rules` or `_synonyms`, so the
     * collection prefix keeps environments sharing a cluster apart (BR-1).
     *
     * @throws InvalidConfigException when the collection is undeclared or its name resolves to nothing.
     */
    public function setName(string $collection, string $kind): string
    {
        return $this->config($collection)->getName() . '_' . $kind;
    }

    /**
     * Exactly what a push sends for one kind: every enabled row in order, as Typesense items
     * (BR-5, BR-9, BR-11). An element that no longer resolves is skipped and logged (BR-8), and a
     * rule left with nothing to pin or hide is skipped with it.
     *
     * @return array{items: list<array<string, mixed>>}
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function desiredSet(string $collection, string $kind): array
    {
        return ['items' => $kind === self::KIND_RULES ? $this->ruleItems($collection) : $this->synonymItems($collection)];
    }

    /**
     * PUT both of a collection's sets, then link them to the live version if it does not already
     * link them (other links are kept). Before the collection exists only the sets are written;
     * a version links them when it is built.
     *
     * @throws SyncException when Typesense cannot be reached or refuses a write.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function push(string $collection): void
    {
        $client = $this->client();

        foreach (self::KINDS as $kind) {
            $name = $this->setName($collection, $kind);
            $payload = $this->desiredSet($collection, $kind);

            try {
                $kind === self::KIND_RULES
                    ? $client->curationSets->upsert($name, $payload)
                    : $client->synonymSets->upsert($name, $payload);
            } catch (Throwable $e) {
                throw $this->failure(Craft::t('typesense-sync', 'Could not write set "{name}"', ['name' => $name]), $e);
            }
        }

        $this->link($collection);
        Craft::$app->getCache()->delete($this->pendingKey($collection));
    }

    /**
     * Push now; when that fails, keep the edit and queue a retry (BR-4).
     *
     * @return string|null Null when the sets are live, else why they are not yet.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function pushOrQueue(string $collection): ?string
    {
        try {
            $this->push($collection);

            return null;
        } catch (SyncException $e) {
            Craft::$app->getCache()->set($this->pendingKey($collection), true, self::PENDING_TTL);
            Craft::$app->getQueue()->push(new PushRules(['collection' => $collection]));

            return $e->getMessage();
        }
    }

    /**
     * Bring Typesense into line with Craft for one collection (BR-14, BR-15): when either set is
     * missing, unlinked or changed outside Craft, push both. Typesense's version is never kept.
     * A dry run makes the same decision and writes nothing.
     *
     * @return array{pushed: bool, sets: array<string, array{state: string, name: string, exists: bool, linked: bool, differs: int, message: ?string}>, message: string}
     * `pushed` is whether a push was (or, in a dry run, would be) sent; `sets` is each kind's
     * state before it.
     * @throws SyncException when Typesense cannot be asked or refuses the push.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function apply(string $collection, bool $dryRun = false): array
    {
        $states = $this->state($collection);

        foreach ($states as $row) {
            if ($row['state'] === self::STATE_UNKNOWN) {
                throw new SyncException((string)$row['message']);
            }
        }

        $behind = array_filter($states, static fn(array $row) => $row['state'] !== self::STATE_IN_STEP);

        if ($behind === []) {
            return ['pushed' => false, 'sets' => $states, 'message' => Craft::t('typesense-sync', 'Rules and synonyms in step.')];
        }

        $sets = implode(', ', array_map(static fn(array $row) => $row['name'] . ' (' . self::describeState($row) . ')', $behind));

        if (!$dryRun) {
            $this->push($collection);
        }

        return [
            'pushed' => true,
            'sets' => $states,
            'message' => $dryRun
                ? Craft::t('typesense-sync', 'Would push {sets}.', ['sets' => $sets])
                : Craft::t('typesense-sync', 'Pushed {sets}.', ['sets' => $sets]),
        ];
    }

    /**
     * Why a set is not in step, in a few words: missing, not linked, or how many items differ.
     *
     * @param array{state: string, exists: bool, linked: bool, differs: int, message: ?string} $row One kind from state().
     */
    public static function describeState(array $row): string
    {
        return match (true) {
            $row['state'] === self::STATE_UNKNOWN => (string)$row['message'],
            $row['state'] === self::STATE_IN_STEP => Craft::t('typesense-sync', 'in step'),
            !$row['exists'] => Craft::t('typesense-sync', 'missing'),
            !$row['linked'] => Craft::t('typesense-sync', 'not linked'),
            default => Craft::t('typesense-sync', '{count, plural, =1{1 item differs} other{# items differ}}', ['count' => $row['differs']]),
        };
    }

    /**
     * Whether a failed push for the collection is waiting to be retried.
     */
    public function isPending(string $collection): bool
    {
        return Craft::$app->getCache()->exists($this->pendingKey($collection));
    }

    /**
     * Add the collection's owned sets to the live version's links, keeping every other link.
     * Nothing is sent when both are linked already or nothing is live.
     *
     * @return bool Whether a PATCH was sent.
     * @throws SyncException
     * @throws InvalidConfigException
     */
    public function link(string $collection): bool
    {
        $schema = TypesenseSync::getInstance()->collections->getLiveSchema($collection);

        if ($schema === null) {
            return false;
        }

        $patch = $this->linkPatch($collection, $schema);

        if ($patch === []) {
            return false;
        }

        $physical = (string)$schema['name'];

        try {
            $this->client()->collections[$physical]->update($patch);
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not link sets to collection "{name}"', ['name' => $physical]), $e);
        }

        return true;
    }

    /**
     * Every set a version of the collection links, by schema key: what the given schema links,
     * in its order, plus the collection's owned sets where missing (BR-2). With no schema, the
     * owned sets alone.
     *
     * @param array<string, mixed>|null $schema A collection schema, or anything with its set lists.
     * @return array<string, list<string>>
     * @throws InvalidConfigException
     */
    public function linkedSets(string $collection, ?array $schema = null): array
    {
        $sets = [];

        foreach (self::LINK_KEYS as $kind => $key) {
            $sets[$key] = Collections::normaliseSets([...Collections::normaliseSets($schema[$key] ?? []), $this->setName($collection, $kind)]);
        }

        return $sets;
    }

    /**
     * The PATCH that links the owned sets the schema is missing, only for the lists that change;
     * empty when both are linked.
     *
     * @param array<string, mixed> $schema
     * @return array<string, list<string>>
     * @throws InvalidConfigException
     */
    public function linkPatch(string $collection, array $schema): array
    {
        return array_filter(
            $this->linkedSets($collection, $schema),
            static fn(array $sets, string $key) => $sets !== Collections::normaliseSets($schema[$key] ?? []),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Write each owned set that does not exist yet, as Craft holds it. A version cannot be
     * created linking a set that does not exist (30.0 refuses it), so every build calls this
     * first. A set that exists is left alone.
     *
     * @return string[] The sets written.
     * @throws SyncException
     * @throws InvalidConfigException
     */
    public function ensureSets(string $collection): array
    {
        $client = $this->client();
        $written = [];

        foreach (self::KINDS as $kind) {
            $name = $this->setName($collection, $kind);

            if ($this->liveSet($kind, $name) !== null) {
                continue;
            }

            try {
                $kind === self::KIND_RULES
                    ? $client->curationSets->upsert($name, $this->desiredSet($collection, $kind))
                    : $client->synonymSets->upsert($name, $this->desiredSet($collection, $kind));
            } catch (Throwable $e) {
                throw $this->failure(Craft::t('typesense-sync', 'Could not write set "{name}"', ['name' => $name]), $e);
            }

            $written[] = $name;
        }

        return $written;
    }

    // State --------------------------------------------------------------------------------------

    /**
     * Each kind's state against Typesense (§4 States): whether the set exists, whether the live
     * collection links it, and how many items differ from the desired set.
     *
     * @return array<string, array{state: string, name: string, exists: bool, linked: bool, differs: int, message: ?string}>
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function state(string $collection): array
    {
        $states = [];

        try {
            $schema = TypesenseSync::getInstance()->collections->getLiveSchema($collection);
        } catch (SyncException $e) {
            $schema = $e;
        }

        foreach (self::KINDS as $kind) {
            $name = $this->setName($collection, $kind);
            $row = ['state' => self::STATE_UNKNOWN, 'name' => $name, 'exists' => false, 'linked' => false, 'differs' => 0, 'message' => null];

            try {
                if ($schema instanceof SyncException) {
                    throw $schema;
                }

                $live = $this->liveSet($kind, $name);
            } catch (SyncException $e) {
                $row['message'] = $e->getMessage();
                $states[$kind] = $row;
                continue;
            }

            $row['exists'] = $live !== null;
            $row['linked'] = $schema !== null && in_array($name, array_map('strval', (array)($schema[self::LINK_KEYS[$kind]] ?? [])), true);
            $row['differs'] = self::countDiffering($this->desiredSet($collection, $kind)['items'], (array)($live['items'] ?? []));
            $row['state'] = match (true) {
                !$row['exists'] || !$row['linked'] => self::STATE_NOT_LIVE,
                $row['differs'] === 0 => self::STATE_IN_STEP,
                $this->isPending($collection) => self::STATE_NOT_LIVE,
                default => self::STATE_OUT_OF_STEP,
            };
            $states[$kind] = $row;
        }

        return $states;
    }

    /**
     * A set as Typesense holds it, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     * @throws SyncException when Typesense cannot be asked.
     */
    public function liveSet(string $kind, string $name): ?array
    {
        $client = $this->client();

        try {
            return $kind === self::KIND_RULES
                ? $client->curationSets[$name]->retrieve()
                : $client->synonymSets[$name]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not read set "{name}"', ['name' => $name]), $e);
        }
    }

    /**
     * How many items differ between two item lists, matched by id: changed, missing or extra.
     *
     * @param array<int, mixed> $wanted
     * @param array<int, mixed> $live
     */
    public static function countDiffering(array $wanted, array $live): int
    {
        $byId = static function(array $items): array {
            $keyed = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $keyed[(string)($item['id'] ?? '')] = self::normaliseItem($item);
                }
            }

            return $keyed;
        };
        $wanted = $byId($wanted);
        $live = $byId($live);
        $differs = 0;

        foreach (array_unique(array_merge(array_keys($wanted), array_keys($live))) as $id) {
            if (($wanted[$id] ?? null) !== ($live[$id] ?? null)) {
                $differs++;
            }
        }

        return $differs;
    }

    /**
     * An item with the values Typesense fills in on its own taken out (false, '', null, [] —
     * `remove_matched_tokens: false`, a synonym's `root: ""`) and its keys sorted, so a retrieved
     * item compares equal to the one sent. Nothing this service sends is false or empty.
     *
     * @param array<array-key, mixed> $item
     * @return array<array-key, mixed>
     */
    public static function normaliseItem(array $item): array
    {
        $kept = [];

        foreach ($item as $key => $value) {
            if (is_array($value)) {
                $value = self::normaliseItem($value);
            }

            if ($value !== false && $value !== '' && $value !== null && $value !== []) {
                $kept[$key] = $value;
            }
        }

        if (!array_is_list($kept)) {
            ksort($kept);
        }

        return $kept;
    }

    // Deleting a set -----------------------------------------------------------------------------

    /**
     * Delete a set, unlinking it from every collection first (BR-12): a synonym set deleted while
     * linked makes every search on the collection fail with 404 until one of that name exists
     * again. A set already gone is not an error.
     *
     * @return string[] The physical collections it was unlinked from.
     * @throws SyncException
     */
    public function deleteSet(string $kind, string $name): array
    {
        $client = $this->client();
        $key = self::LINK_KEYS[$kind];
        $unlinked = [];

        try {
            $collections = (array)$client->collections->retrieve();
        } catch (Throwable $e) {
            throw $this->failure('Could not list the collections', $e);
        }

        foreach ($collections as $schema) {
            $linked = array_map('strval', (array)($schema[$key] ?? []));

            if (!in_array($name, $linked, true)) {
                continue;
            }

            $physical = (string)$schema['name'];

            try {
                $client->collections[$physical]->update([$key => array_values(array_diff($linked, [$name]))]);
            } catch (Throwable $e) {
                throw $this->failure(Craft::t('typesense-sync', 'Could not unlink set "{name}" from collection "{collection}"', ['name' => $name, 'collection' => $physical]), $e);
            }

            $unlinked[] = $physical;
        }

        try {
            $kind === self::KIND_RULES ? $client->curationSets[$name]->delete() : $client->synonymSets[$name]->delete();
        } catch (ObjectNotFound) {
            // Already gone: the outcome wanted.
        } catch (Throwable $e) {
            throw $this->failure(Craft::t('typesense-sync', 'Could not delete set "{name}"', ['name' => $name]), $e);
        }

        return $unlinked;
    }

    // Internals ----------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function ruleItems(string $collection): array
    {
        $this->config($collection);
        $items = [];
        $resolved = [];
        // '' marks an element that did not resolve, so it is looked up (and logged) once.
        $resolve = function(int $elementId) use ($collection, &$resolved): string {
            return $resolved[$elementId] ??= $this->documentIdFor($collection, $elementId) ?? '';
        };

        foreach ($this->getRules($collection) as $rule) {
            if (!$rule->enabled) {
                continue;
            }

            $includes = [];
            $excludes = [];

            foreach ($rule->pins as $elementId) {
                $id = $resolve($elementId);
                if ($id !== '') {
                    $includes[] = ['id' => $id, 'position' => count($includes) + 1];
                }
            }

            foreach ($rule->hides as $elementId) {
                $id = $resolve($elementId);
                if ($id !== '') {
                    $excludes[] = ['id' => $id];
                }
            }

            if ($includes === [] && $excludes === []) {
                Craft::warning(sprintf('Rule %s ("%s") on %s was not pushed: none of its entries resolve any more.', $rule->id, $rule->query, $collection), TypesenseSync::HANDLE);
                continue;
            }

            $item = [
                'id' => sprintf('rule-%04d', count($items) + 1),
                'rule' => ['query' => $rule->query, 'match' => $rule->matchType],
            ];

            if ($includes !== []) {
                $item['includes'] = $includes;
            }

            if ($excludes !== []) {
                $item['excludes'] = $excludes;
            }

            // Without it a pin shows even when the search's filter excludes the document, a
            // scoped key's publication window and isolation filter included (BR-9).
            $item['filter_curated_hits'] = true;
            $item['stop_processing'] = true;

            if ($rule->effectiveFrom !== null) {
                $item['effective_from_ts'] = $rule->effectiveFrom->getTimestamp();
            }

            if ($rule->effectiveTo !== null) {
                $item['effective_to_ts'] = $rule->effectiveTo->getTimestamp();
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function synonymItems(string $collection): array
    {
        $this->config($collection);
        $items = [];

        foreach ($this->getSynonymGroups($collection) as $group) {
            if (!$group->enabled) {
                continue;
            }

            $item = ['id' => sprintf('synonym-%04d', count($items) + 1)];

            if ($group->isOneWay()) {
                $item['root'] = $group->root;
            }

            $item['synonyms'] = $group->words;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The document id an element has in a collection, or null (logged) when it is gone or no
     * longer feeds the collection (BR-8).
     */
    private function documentIdFor(string $collection, int $elementId): ?string
    {
        $element = $this->elementFeeding($collection, $elementId);

        if ($element === null) {
            Craft::warning(sprintf('Element %d is pinned or hidden on %s but no longer feeds it; it was left out of the push.', $elementId, $collection), TypesenseSync::HANDLE);

            return null;
        }

        [$element, $formatter] = $element;

        return $formatter instanceof BaseFormatter ? $formatter->documentId($element) : (string)$element->id;
    }

    /**
     * The ids, of those given, that are not elements feeding the collection.
     *
     * @param int[] $elementIds
     * @return int[]
     */
    private function elementsOutside(string $collection, array $elementIds): array
    {
        return array_values(array_filter($elementIds, fn(int $id) => $this->elementFeeding($collection, $id) === null));
    }

    /**
     * The element, in the site its target indexes, with its formatter — or null when there is no
     * such element or it routes to another collection or none.
     *
     * @return array{0: ElementInterface, 1: FormatterInterface}|null
     */
    private function elementFeeding(string $collection, int $elementId): ?array
    {
        $elements = Craft::$app->getElements();
        $targets = TypesenseSync::getInstance()->targets;
        $element = $elements->getElementById($elementId, null, '*', ['status' => null]);
        $target = $element !== null ? $targets->resolveTargetFor($element) : null;

        if ($element === null || $target === null || $target->collection !== $collection) {
            return null;
        }

        $site = $targets->siteFor($target);

        if ($site === null) {
            return null;
        }

        if ((int)$element->siteId !== (int)$site->id) {
            $element = $elements->getElementById($elementId, $element::class, $site->id, ['status' => null]);
        }

        return $element !== null ? [$element, $targets->formatterFor($target)] : null;
    }

    /**
     * @throws InvalidConfigException
     */
    private function config(string $collection): CollectionConfig
    {
        return TypesenseSync::getInstance()->targets->getSettings()->getCollectionConfig($collection)
            ?? throw new InvalidConfigException(Craft::t('typesense-sync', 'No collection "{collection}" is declared in config/typesense-sync.php.', ['collection' => $collection]));
    }

    /**
     * A declared source as an element type and the element-select source key that lists it, or
     * null when its section, group or product type does not exist.
     *
     * @return array{0: class-string<ElementInterface>, 1: string}|null
     */
    private static function pickerSource(SourceConfig $source): ?array
    {
        $uid = match ($source->kind) {
            SourceConfig::KIND_SECTION => Craft::$app->getEntries()->getSectionByHandle($source->handle)?->uid,
            SourceConfig::KIND_CATEGORY_GROUP => Craft::$app->getCategories()->getGroupByHandle($source->handle)?->uid,
            SourceConfig::KIND_PRODUCT_TYPE => Commerce::productTypeUid($source->handle),
            default => null,
        };

        return match (true) {
            $source->kind === SourceConfig::KIND_USERS => [User::class, '*'],
            $uid === null => null,
            $source->kind === SourceConfig::KIND_SECTION => [Entry::class, 'section:' . $uid],
            $source->kind === SourceConfig::KIND_CATEGORY_GROUP => [Category::class, 'group:' . $uid],
            default => ($product = Commerce::productClass()) !== null ? [$product, 'productType:' . $uid] : null,
        };
    }

    private function pendingKey(string $collection): string
    {
        return sprintf('%s:rules-pending:%s', TypesenseSync::HANDLE, $collection);
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
}
