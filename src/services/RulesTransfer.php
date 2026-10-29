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
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\TypesenseSync;
use yii\base\InvalidConfigException;

/**
 * Moves a collection's rules and synonyms between environments as a file (BR-16, BR-17).
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
