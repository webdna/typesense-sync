<?php

namespace webdna\typesensesync\models;

use Craft;
use craft\base\Model;
use craft\helpers\Db;
use DateTime;
use webdna\typesensesync\records\RuleRecord;
use yii\db\Expression;

/**
 * A curation rule: "when the search is *query*, pin these, hide those" (Typesense's *curation*).
 *
 * Pins and hides are element ids, in order; the formatter turns each into a document id at push
 * time, so a formatter that overrides `documentId()` is always honoured (BR-8). Rules are applied
 * in `sortOrder`, the first match winning (BR-5).
 *
 * @since 1.1.0
 */
class Rule extends Model
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_CONTAINS = 'contains';

    public const MATCH_TYPES = [self::MATCH_EXACT, self::MATCH_CONTAINS];

    public const MAX_PINS = 20;

    public const MAX_HIDES = 100;

    public const STATUS_ON = 'on';

    public const STATUS_OFF = 'off';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ENDED = 'ended';

    public ?int $id = null;

    /**
     * The collection's handle from config, never a Typesense name.
     */
    public string $collection = '';

    /**
     * 1-based within the collection; earlier wins.
     */
    public int $sortOrder = 1;

    public string $query = '';

    public string $matchType = self::MATCH_EXACT;

    /**
     * Also fire when the search is one of the term's synonyms (BR-23).
     */
    public bool $matchSynonyms = false;

    /**
     * Element ids in the order they are pinned: position = index + 1.
     *
     * @var int[]
     */
    public array $pins = [];

    /**
     * @var int[]
     */
    public array $hides = [];

    public ?DateTime $effectiveFrom = null;

    public ?DateTime $effectiveTo = null;

    public bool $enabled = true;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public static function fromRecord(RuleRecord $record): self
    {
        return new self($record->getAttributes());
    }

    /**
     * Copies the model onto a record for saving; dates go in as UTC (BR-11).
     */
    public function populateRecord(RuleRecord $record): void
    {
        $record->collection = $this->collection;
        $record->sortOrder = $this->sortOrder;
        $record->query = $this->query;
        $record->matchType = $this->matchType;
        $record->matchSynonyms = $this->matchSynonyms;
        $record->pins = $this->pins;
        $record->hides = $this->hides;
        $record->effectiveFrom = Db::prepareDateForDb($this->effectiveFrom);
        $record->effectiveTo = Db::prepareDateForDb($this->effectiveTo);
        $record->enabled = $this->enabled;
    }

    /**
     * On, off, or, for an enabled rule outside its dates, scheduled or ended (BR-11).
     */
    public function getStatus(?DateTime $now = null): string
    {
        $now ??= new DateTime();

        return match (true) {
            !$this->enabled => self::STATUS_OFF,
            $this->effectiveFrom !== null && $this->effectiveFrom > $now => self::STATUS_SCHEDULED,
            $this->effectiveTo !== null && $this->effectiveTo <= $now => self::STATUS_ENDED,
            default => self::STATUS_ON,
        };
    }

    public function attributeLabels(): array
    {
        return [
            'query' => Craft::t('typesense-sync', 'Search term'),
            'matchType' => Craft::t('typesense-sync', 'Match'),
            'matchSynonyms' => Craft::t('typesense-sync', 'Also match synonyms'),
            'pins' => Craft::t('typesense-sync', 'Pin'),
            'hides' => Craft::t('typesense-sync', 'Hide'),
            'effectiveFrom' => Craft::t('typesense-sync', 'Starts'),
            'effectiveTo' => Craft::t('typesense-sync', 'Ends'),
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['collection', 'query'], 'trim'],
            [['pins', 'hides'], 'filter', 'filter' => [self::class, 'normaliseIds']],
            [['collection', 'query', 'matchType'], 'required'],
            [['collection'], 'string', 'max' => 64],
            [['query'], 'string', 'max' => 255],
            [['matchType'], 'in', 'range' => self::MATCH_TYPES],
            [['sortOrder'], 'integer', 'min' => 1],
            [['pins'], 'validatePins', 'skipOnEmpty' => false],
            [['hides'], 'validateHides'],
            [['effectiveTo'], 'validateEffectiveTo'],
            [['query'], 'validateUniqueQuery'],
        ];
    }

    /**
     * Integer element ids, each once, in the order first given.
     *
     * @param mixed $ids
     * @return int[]
     */
    public static function normaliseIds(mixed $ids): array
    {
        $ids = array_map('intval', array_filter((array)$ids, 'is_numeric'));

        return array_values(array_unique(array_filter($ids, fn(int $id) => $id > 0)));
    }

    /**
     * BR-6 (at least one pin or hide) and BR-7 (at most 20 pins, none also hidden).
     */
    public function validatePins(string $attribute): void
    {
        if ($this->pins === [] && $this->hides === []) {
            $this->addError($attribute, Craft::t('typesense-sync', 'Pin or hide at least one entry.'));
        }

        if (count($this->pins) > self::MAX_PINS) {
            $this->addError($attribute, Craft::t('typesense-sync', 'A rule can pin at most {max} entries.', ['max' => self::MAX_PINS]));
        }

        if (array_intersect($this->pins, $this->hides) !== []) {
            $this->addError($attribute, Craft::t('typesense-sync', 'An entry cannot be both pinned and hidden.'));
        }
    }

    /**
     * BR-7: at most 100 hides.
     */
    public function validateHides(string $attribute): void
    {
        if (count($this->hides) > self::MAX_HIDES) {
            $this->addError($attribute, Craft::t('typesense-sync', 'A rule can hide at most {max} entries.', ['max' => self::MAX_HIDES]));
        }
    }

    /**
     * BR-11: Ends must be after Starts.
     */
    public function validateEffectiveTo(string $attribute): void
    {
        if ($this->effectiveFrom !== null && $this->effectiveTo !== null && $this->effectiveTo <= $this->effectiveFrom) {
            $this->addError($attribute, Craft::t('typesense-sync', 'Ends must be after Starts.'));
        }
    }

    /**
     * BR-6: no two rules in a collection share a query (case-insensitive) and a match type.
     */
    public function validateUniqueQuery(string $attribute): void
    {
        $taken = RuleRecord::find()
            ->where([
                'collection' => $this->collection,
                'matchType' => $this->matchType,
            ])
            ->andWhere(['=', new Expression('LOWER([[query]])'), mb_strtolower($this->query)]);

        if ($this->id !== null) {
            $taken->andWhere(['not', ['id' => $this->id]]);
        }

        if ($taken->exists()) {
            $this->addError($attribute, Craft::t('typesense-sync', 'This collection already has a rule for “{query}” with this match.', ['query' => $this->query]));
        }
    }
}
