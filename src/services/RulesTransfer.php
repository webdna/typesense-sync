<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\TypesenseSync;
use yii\base\InvalidConfigException;

/**
 * Moves a collection's rules and synonyms between environments as a file (BR-16, BR-17), and
 * brings a set made by hand in Typesense under the plugin (adopt, BR-18).
 *
 * An element id means nothing on another environment, so each pinned or hidden element travels as
 * a reference — its uid, and its type, site, section (or group, or product type) and slug, or a
 * user's username — and is resolved on import by uid first, then by the rest. A reference that
 * resolves to nothing feeding the collection is left out and listed; a rule left with nothing to
 * pin or hide is left out with it.
 *
 * Import replaces the collection's rows in one transaction: a file refused part-way changes
 * nothing. Like every row write, it does not push; the caller does (BR-4).
 *
 * @since 1.1.0
 */
class RulesTransfer extends Component
{
    /**
     * The format's version: the `version` key every export carries.
     */
    public const VERSION = 1;

    /**
     * A reference's key for the section, group or product type an element belongs to, by element type.
     */
    private const SOURCE_KEYS = [
        Entry::class => 'section',
        Category::class => 'group',
    ];

    /**
     * The keys a simple set item carries once Typesense's own defaults are taken out
     * (Rules::normaliseItem()), by kind: anything else is an advanced option adopt skips (BR-18).
     * `filter_curated_hits` is allowed whatever its value, because a push forces it on (BR-9).
     */
    private const SIMPLE_KEYS = [
        Rules::KIND_RULES => ['id', 'rule', 'includes', 'excludes', 'filter_curated_hits', 'stop_processing', 'effective_from_ts', 'effective_to_ts'],
        Rules::KIND_SYNONYMS => ['id', 'root', 'synonyms'],
    ];

    /**
     * One collection's rules and synonyms, in order, with every element as a reference.
     *
     * @return array{data: array<string, mixed>, leftOut: list<string>} `leftOut` names each element
     * that no longer feeds the collection and so is not in the export.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function export(string $collection): array
    {
        $this->requireDeclared($collection);
        $rules = $this->rulesService();
        $leftOut = [];
        $exported = [];

        foreach ($rules->getRules($collection) as $rule) {
            $refs = [];

            foreach (['pins' => $rule->pins, 'hides' => $rule->hides] as $key => $ids) {
                $refs[$key] = [];

                foreach ($ids as $id) {
                    $element = $rules->elementFor($collection, $id);

                    if ($element === null) {
                        $leftOut[] = Craft::t('typesense-sync', 'Rule “{query}”: element {id} no longer feeds this collection and was left out.', [
                            'query' => $rule->query,
                            'id' => $id,
                        ]);
                        continue;
                    }

                    $refs[$key][] = self::reference($element);
                }
            }

            $exported[] = [
                'query' => $rule->query,
                'matchType' => $rule->matchType,
                'matchSynonyms' => $rule->matchSynonyms,
                'pins' => $refs['pins'],
                'hides' => $refs['hides'],
                'effectiveFrom' => self::formatDate($rule->effectiveFrom),
                'effectiveTo' => self::formatDate($rule->effectiveTo),
                'enabled' => $rule->enabled,
            ];
        }

        $synonyms = array_map(fn(SynonymGroup $group) => [
            'root' => $group->root,
            'words' => $group->words,
            'enabled' => $group->enabled,
        ], $rules->getSynonymGroups($collection));

        return [
            'data' => [
                'version' => self::VERSION,
                'collection' => $collection,
                'rules' => $exported,
                'synonyms' => $synonyms,
            ],
            'leftOut' => $leftOut,
        ];
    }

    /**
     * Read an export file's contents, refusing anything that is not a whole version-1 export, so a
     * bad file is turned away before anything is changed (TN-11).
     *
     * @return array{version: int, collection: string, rules: list<array<string, mixed>>, synonyms: list<array<string, mixed>>}
     * @throws InvalidArgumentException naming what is wrong.
     */
    public function read(string $json): array
    {
        try {
            $data = Json::decode($json);
        } catch (Throwable) {
            throw new InvalidArgumentException(Craft::t('typesense-sync', 'The file is not valid JSON.'));
        }

        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException(Craft::t('typesense-sync', 'The file is not a version {version} rules export.', ['version' => self::VERSION]));
        }

        if (!is_string($data['collection'] ?? null) || $data['collection'] === '' || !self::isList($data['rules'] ?? null) || !self::isList($data['synonyms'] ?? null)) {
            throw new InvalidArgumentException(Craft::t('typesense-sync', 'The file needs a collection, a list of rules and a list of synonyms.'));
        }

        foreach ($data['rules'] as $n => $rule) {
            if (!self::isRule($rule)) {
                throw new InvalidArgumentException(Craft::t('typesense-sync', 'Rule {n} in the file is malformed.', ['n' => $n + 1]));
            }
        }

        foreach ($data['synonyms'] as $n => $group) {
            if (!self::isSynonymGroup($group)) {
                throw new InvalidArgumentException(Craft::t('typesense-sync', 'Synonym group {n} in the file is malformed.', ['n' => $n + 1]));
            }
        }

        /** @var array{version: int, collection: string, rules: list<array<string, mixed>>, synonyms: list<array<string, mixed>>} $data */
        return $data;
    }

    /**
     * Replace a collection's rules and synonyms with a read export's. All or nothing: a row the
     * file holds that would be refused in the CP (BR-6, BR-7, BR-10, BR-11) refuses the import and
     * changes nothing. Does not push.
     *
     * @param array{version: int, collection: string, rules: list<array<string, mixed>>, synonyms: list<array<string, mixed>>} $data From read().
     * @return array{rules: int, synonyms: int, leftOut: list<string>} The rows written, and what was left out.
     * @throws InvalidArgumentException when a row is refused.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function import(array $data): array
    {
        $collection = $data['collection'];
        $this->requireDeclared($collection);
        $rules = $this->rulesService();
        $leftOut = [];
        $models = [];

        // Resolve first: lookups only, so nothing below the transaction reads half-written rows.
        foreach ($data['rules'] as $row) {
            $ids = [];

            foreach (['pins', 'hides'] as $key) {
                $ids[$key] = [];

                foreach ($row[$key] as $ref) {
                    $id = $this->resolve($collection, $ref);

                    if ($id === null) {
                        $leftOut[] = Craft::t('typesense-sync', 'Rule “{query}”: {element} was not found here and was left out.', [
                            'query' => $row['query'],
                            'element' => self::describe($ref),
                        ]);
                        continue;
                    }

                    $ids[$key][] = $id;
                }
            }

            if ($ids['pins'] === [] && $ids['hides'] === []) {
                $leftOut[] = Craft::t('typesense-sync', 'Rule “{query}” was left out: none of its entries were found here.', ['query' => $row['query']]);
                continue;
            }

            $models[] = new Rule([
                'collection' => $collection,
                'query' => $row['query'],
                'matchType' => $row['matchType'],
                'matchSynonyms' => $row['matchSynonyms'] ?? false,
                'pins' => $ids['pins'],
                'hides' => $ids['hides'],
                'effectiveFrom' => self::parseDate($row['effectiveFrom']),
                'effectiveTo' => self::parseDate($row['effectiveTo']),
                'enabled' => $row['enabled'],
            ]);
        }

        foreach ($data['synonyms'] as $row) {
            $models[] = new SynonymGroup([
                'collection' => $collection,
                'root' => $row['root'],
                'words' => $row['words'],
                'enabled' => $row['enabled'],
            ]);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            RuleRecord::deleteAll(['collection' => $collection]);
            SynonymRecord::deleteAll(['collection' => $collection]);

            foreach ($models as $model) {
                $saved = $model instanceof Rule ? $rules->saveRule($model) : $rules->saveSynonymGroup($model);

                if (!$saved) {
                    throw new InvalidArgumentException($model instanceof Rule
                        ? Craft::t('typesense-sync', 'Rule “{query}” in the file was refused: {error}', ['query' => $model->query, 'error' => implode(' ', $model->getFirstErrors())])
                        : Craft::t('typesense-sync', 'Synonym group “{words}” in the file was refused: {error}', ['words' => implode(', ', $model->words), 'error' => implode(' ', $model->getFirstErrors())]));
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $written = count(array_filter($models, fn($model) => $model instanceof Rule));

        return ['rules' => $written, 'synonyms' => count($models) - $written, 'leftOut' => $leftOut];
    }

    /**
     * Bring a set made by hand in Typesense under the plugin (BR-18): copy each item the CP can show
     * after the collection's rows, push, then unlink the source set from the live collection. The
     * source set is never deleted. An item outside the simple rule is skipped and listed; a pin or
     * hide whose document is not an element feeding the collection is dropped and listed; a row
     * the CP would refuse (an existing rule's term, say) is listed and not written, so running it
     * again after a failure adopts nothing twice.
     *
     * @param string $kind Rules::KIND_RULES for a curation set, Rules::KIND_SYNONYMS for a synonym set.
     * @return array{rules: int, synonyms: int, skipped: list<string>, unlinked: bool, problem: ?string, warning: ?string}
     * `unlinked` is whether the live collection linked the source set and no longer does; `problem`
     * says why the run stopped short — the push failed (retry queued) or the unlink did — leaving
     * the source set linked; `warning` is set when the server does not report Also match synonyms.
     * @throws InvalidArgumentException when the set does not exist or is the collection's own.
     * @throws SyncException when Typesense cannot be asked for the set.
     * @throws InvalidConfigException when the collection is undeclared.
     */
    public function adopt(string $collection, string $kind, string $name): array
    {
        $this->requireDeclared($collection);
        $rules = $this->rulesService();

        if ($name === $rules->setName($collection, $kind)) {
            throw new InvalidArgumentException(Craft::t('typesense-sync', '“{name}” is the set the plugin keeps for {collection}; there is nothing to adopt.', ['name' => $name, 'collection' => $collection]));
        }

        $set = $rules->liveSet($kind, $name);

        if ($set === null) {
            throw new InvalidArgumentException($kind === Rules::KIND_RULES
                ? Craft::t('typesense-sync', 'There is no curation set “{name}” in Typesense.', ['name' => $name])
                : Craft::t('typesense-sync', 'There is no synonym set “{name}” in Typesense.', ['name' => $name]));
        }

        // Typesense applies a set's items in lexical id order: keep that order.
        $items = array_values(array_filter((array)($set['items'] ?? []), 'is_array'));
        usort($items, fn(array $a, array $b) => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));
        $skipped = [];
        $models = [];

        foreach ($items as $item) {
            $id = (string)($item['id'] ?? '?');
            $advanced = self::advancedOptions($kind, $item);

            if ($advanced !== []) {
                $skipped[] = Craft::t('typesense-sync', 'Item {id} uses more than a simple rule ({options}) and was skipped.', ['id' => $id, 'options' => implode(', ', $advanced)]);
                continue;
            }

            $model = $kind === Rules::KIND_RULES
                ? $this->adoptedRule($collection, $item, $skipped)
                : $this->adoptedGroup($collection, $item, $skipped);

            if ($model !== null) {
                $models[$id] = $model;
            }
        }

        $written = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($models as $id => $model) {
                if ($model instanceof Rule ? $rules->saveRule($model) : $rules->saveSynonymGroup($model)) {
                    $written++;
                    continue;
                }

                $skipped[] = Craft::t('typesense-sync', 'Item {id} was not adopted: {error}', ['id' => $id, 'error' => implode(' ', $model->getFirstErrors())]);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $result = [
            Rules::KIND_RULES => $kind === Rules::KIND_RULES ? $written : 0,
            Rules::KIND_SYNONYMS => $kind === Rules::KIND_SYNONYMS ? $written : 0,
            'skipped' => $skipped,
            'unlinked' => false,
            'problem' => null,
            'warning' => null,
        ];

        // 30.0 leaves a rule's synonyms out of a read, so the set cannot say which rules had it (BR-18).
        $rulesRead = array_filter($items, fn(array $item) => is_array($item['rule'] ?? null));

        if ($kind === Rules::KIND_RULES && $rulesRead !== [] && array_filter($rulesRead, fn(array $item) => array_key_exists('synonyms', $item['rule'])) === []) {
            $result['warning'] = Craft::t('typesense-sync', 'This Typesense server does not report whether a rule also matches synonyms, so every adopted rule has Also match synonyms off. Check them in the control panel.');
        }

        // The owned set goes live before the source is unlinked, so search never runs on neither.
        $result['problem'] = $rules->pushOrQueue($collection);

        if ($result['problem'] === null) {
            try {
                $result['unlinked'] = $rules->unlink($collection, $kind, $name);
            } catch (SyncException $e) {
                $result['problem'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * What, in a curation or synonym item, lies outside the simple rule (§2): each option named as
     * Typesense names it. Empty when the CP can show the item. Values Typesense fills in on its own
     * (`remove_matched_tokens: false`, a synonym's `root: ""`) are not options.
     *
     * @param string $kind Rules::KIND_RULES or Rules::KIND_SYNONYMS.
     * @param array<array-key, mixed> $item A set item as Typesense returns it.
     * @return list<string>
     */
    public static function advancedOptions(string $kind, array $item): array
    {
        $options = [];

        // normaliseItem() drops false, so this one is read first.
        if ($kind === Rules::KIND_RULES && ($item['stop_processing'] ?? true) === false) {
            $options[] = 'stop_processing: false';
        }

        $item = Rules::normaliseItem($item);

        foreach (array_keys($item) as $key) {
            if (!in_array($key, self::SIMPLE_KEYS[$kind], true)) {
                $options[] = (string)$key;
            }
        }

        if ($kind === Rules::KIND_SYNONYMS) {
            if (!self::isList($item['synonyms'] ?? null) || array_filter($item['synonyms'], fn($word) => !is_string($word)) !== []) {
                $options[] = 'synonyms';
            }

            if (isset($item['root']) && !is_string($item['root'])) {
                $options[] = 'root';
            }

            return $options;
        }

        $rule = is_array($item['rule'] ?? null) ? $item['rule'] : [];

        foreach ($rule as $key => $value) {
            // Also match synonyms is simple (BR-23); normalising has already dropped a false one.
            if ($key !== 'query' && $key !== 'match' && !($key === 'synonyms' && $value === true)) {
                $options[] = 'rule.' . $key;
            }
        }

        if (!is_string($rule['query'] ?? null)) {
            $options[] = 'no rule.query';
        }

        if (!in_array($rule['match'] ?? null, Rule::MATCH_TYPES, true)) {
            $options[] = 'rule.match';
        }

        $includes = $item['includes'] ?? [];
        $positions = is_array($includes) ? array_map(fn($include) => is_array($include) && is_string($include['id'] ?? null) ? ($include['position'] ?? null) : null, $includes) : [null];
        sort($positions);

        // Pins are positions 1, 2, 3… in the CP; a gap or a repeat cannot be shown.
        if ($positions !== range(1, count($positions)) && $positions !== []) {
            $options[] = 'includes.position';
        }

        $excludes = $item['excludes'] ?? [];

        if (!is_array($excludes) || array_filter($excludes, fn($exclude) => !is_array($exclude) || !is_string($exclude['id'] ?? null)) !== []) {
            $options[] = 'excludes';
        }

        foreach (['effective_from_ts', 'effective_to_ts'] as $key) {
            if (isset($item[$key]) && !is_int($item[$key])) {
                $options[] = $key;
            }
        }

        return $options;
    }

    /**
     * A simple curation item as a rule, its documents turned back into elements; null (listed)
     * when none of them are elements feeding the collection.
     *
     * @param array<string, mixed> $item
     * @param list<string> $skipped
     */
    private function adoptedRule(string $collection, array $item, array &$skipped): ?Rule
    {
        $rules = $this->rulesService();
        $query = (string)$item['rule']['query'];
        $includes = (array)($item['includes'] ?? []);
        usort($includes, fn(array $a, array $b) => $a['position'] <=> $b['position']);
        $ids = ['pins' => [], 'hides' => []];

        foreach (['pins' => $includes, 'hides' => (array)($item['excludes'] ?? [])] as $key => $documents) {
            foreach ($documents as $document) {
                $id = $rules->elementForDocument($collection, (string)$document['id']);

                if ($id === null) {
                    $skipped[] = Craft::t('typesense-sync', 'Rule “{query}”: document {id} is not an entry feeding this collection and was dropped.', ['query' => $query, 'id' => $document['id']]);
                    continue;
                }

                $ids[$key][] = $id;
            }
        }

        if ($ids['pins'] === [] && $ids['hides'] === []) {
            $skipped[] = Craft::t('typesense-sync', 'Rule “{query}” was skipped: none of its documents are entries feeding this collection.', ['query' => $query]);

            return null;
        }

        return new Rule([
            'collection' => $collection,
            'query' => $query,
            'matchType' => $item['rule']['match'],
            'matchSynonyms' => ($item['rule']['synonyms'] ?? false) === true,
            'pins' => $ids['pins'],
            'hides' => $ids['hides'],
            'effectiveFrom' => isset($item['effective_from_ts']) ? new DateTime('@' . $item['effective_from_ts']) : null,
            'effectiveTo' => isset($item['effective_to_ts']) ? new DateTime('@' . $item['effective_to_ts']) : null,
        ]);
    }

    /**
     * A simple synonym item as a group; null (listed) when the collection already has the same
     * group, which is what a second run finds.
     *
     * @param array<string, mixed> $item
     * @param list<string> $skipped
     */
    private function adoptedGroup(string $collection, array $item, array &$skipped): ?SynonymGroup
    {
        $root = is_string($item['root'] ?? null) && trim($item['root']) !== '' ? $item['root'] : null;
        $group = new SynonymGroup(['collection' => $collection, 'root' => $root, 'words' => $item['synonyms']]);
        $same = static fn(SynonymGroup $other): array => [mb_strtolower(trim((string)$other->root)), array_unique(array_map(fn(string $word) => mb_strtolower(trim($word)), $other->words))];
        $wanted = $same($group);
        sort($wanted[1]);

        foreach ($this->rulesService()->getSynonymGroups($collection) as $existing) {
            $have = $same($existing);
            sort($have[1]);

            if ($have === $wanted) {
                $skipped[] = Craft::t('typesense-sync', 'Item {id} is already a synonym group here and was skipped.', ['id' => $item['id'] ?? '?']);

                return null;
            }
        }

        return $group;
    }

    /**
     * How an element travels: its uid, and what finds it again where the uid differs.
     *
     * @return array<string, string>
     */
    public static function reference(ElementInterface $element): array
    {
        $ref = [
            'uid' => (string)$element->uid,
            'type' => $element::class,
            'site' => (string)$element->getSite()->handle,
        ];

        if ($element instanceof User) {
            return $ref + ['username' => (string)$element->username];
        }

        $source = match (true) {
            $element instanceof Entry => $element->getSection()?->handle,
            $element instanceof Category => $element->getGroup()->handle,
            default => Commerce::productTypeOf($element)['handle'] ?? null,
        };

        if ($source !== null) {
            $ref[self::SOURCE_KEYS[$element::class] ?? 'productType'] = (string)$source;
        }

        return $ref + ['slug' => (string)$element->slug];
    }

    /**
     * The id of the element a reference names, feeding the collection: by uid, then by type, site,
     * source and slug (or username). Null when neither finds one (BR-17).
     *
     * @param array<string, mixed> $ref
     */
    private function resolve(string $collection, array $ref): ?int
    {
        $type = $ref['type'] ?? null;

        if (!is_string($type) || !is_subclass_of($type, ElementInterface::class)) {
            return null;
        }

        $rules = $this->rulesService();
        $site = is_string($ref['site'] ?? null) ? Craft::$app->getSites()->getSiteByHandle($ref['site']) : null;
        $siteId = $site->id ?? '*';

        if (is_string($ref['uid'] ?? null)) {
            $element = Craft::$app->getElements()->getElementByUid($ref['uid'], $type, $siteId, ['status' => null]);

            if ($element !== null && $rules->elementFor($collection, (int)$element->id) !== null) {
                return (int)$element->id;
            }
        }

        $criteria = [];

        foreach (['section' => 'section', 'group' => 'group', 'productType' => 'type', 'slug' => 'slug', 'username' => 'username'] as $key => $param) {
            if (is_string($ref[$key] ?? null) && $ref[$key] !== '') {
                $criteria[$param] = $ref[$key];
            }
        }

        if (!isset($criteria['slug']) && !isset($criteria['username'])) {
            return null;
        }

        try {
            $query = $type::find()->siteId($siteId)->status(null);

            foreach ($criteria as $param => $value) {
                call_user_func([$query, $param], $value);
            }

            $id = $query->ids()[0] ?? null;
        } catch (Throwable) {
            // A criterion this element type does not have: the reference cannot name one here.
            return null;
        }

        return $id !== null && $rules->elementFor($collection, (int)$id) !== null ? (int)$id : null;
    }

    /**
     * A reference as an editor would recognise it: `section/slug`, a username, or the uid.
     *
     * @param array<string, mixed> $ref
     */
    private static function describe(array $ref): string
    {
        $source = $ref['section'] ?? $ref['group'] ?? $ref['productType'] ?? null;
        $name = $ref['slug'] ?? $ref['username'] ?? null;

        if (is_string($name)) {
            return is_string($source) ? $source . '/' . $name : $name;
        }

        return is_string($ref['uid'] ?? null) ? $ref['uid'] : '?';
    }

    private static function formatDate(?DateTime $date): ?string
    {
        return $date !== null ? (clone $date)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM) : null;
    }

    private static function parseDate(?string $date): ?DateTime
    {
        return $date !== null ? new DateTime($date) : null;
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function isRule(mixed $rule): bool
    {
        if (!is_array($rule) || !is_string($rule['query'] ?? null) || !is_string($rule['matchType'] ?? null) || !is_bool($rule['enabled'] ?? null)) {
            return false;
        }

        // Added in 1.1 within version 1: a file without it reads as off (BR-16).
        if (array_key_exists('matchSynonyms', $rule) && !is_bool($rule['matchSynonyms'])) {
            return false;
        }

        foreach (['pins', 'hides'] as $key) {
            if (!self::isList($rule[$key] ?? null)) {
                return false;
            }

            foreach ($rule[$key] as $ref) {
                if (!is_array($ref)) {
                    return false;
                }
            }
        }

        foreach (['effectiveFrom', 'effectiveTo'] as $key) {
            if (!array_key_exists($key, $rule) || ($rule[$key] !== null && !self::isDate($rule[$key]))) {
                return false;
            }
        }

        return true;
    }

    private static function isSynonymGroup(mixed $group): bool
    {
        return is_array($group)
            && array_key_exists('root', $group)
            && ($group['root'] === null || is_string($group['root']))
            && self::isList($group['words'] ?? null)
            && array_filter($group['words'], fn($word) => !is_string($word)) === []
            && is_bool($group['enabled'] ?? null);
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && DateTime::createFromFormat(DATE_ATOM, $value) !== false;
    }

    /**
     * @throws InvalidConfigException
     */
    private function requireDeclared(string $collection): void
    {
        if (TypesenseSync::getInstance()->targets->getSettings()->getCollectionConfig($collection) === null) {
            throw new InvalidConfigException(Craft::t('typesense-sync', 'No collection "{collection}" is declared in config/typesense-sync.php.', ['collection' => $collection]));
        }
    }

    private function rulesService(): Rules
    {
        return TypesenseSync::getInstance()->rules;
    }
}
