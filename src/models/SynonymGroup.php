<?php

namespace webdna\typesensesync\models;

use Craft;
use craft\base\Model;
use DateTime;
use webdna\typesensesync\records\SynonymRecord;

/**
 * Words that count as the same in a collection's searches.
 *
 * With no `root` the group is *all equal*: each word finds the others. With a root it is *one
 * way*: the words find the root, not the reverse.
 *
 * @since 1.1.0
 */
class SynonymGroup extends Model
{
    public const MAX_WORDS = 50;

    public const MAX_LENGTH = 255;

    public ?int $id = null;

    /**
     * The collection's handle from config.
     */
    public string $collection = '';

    /**
     * Display order only; synonyms have no precedence.
     */
    public int $sortOrder = 1;

    /**
     * Null for all equal; the main term for one way.
     */
    public ?string $root = null;

    /**
     * @var string[]
     */
    public array $words = [];

    public bool $enabled = true;

    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;

    public static function fromRecord(SynonymRecord $record): self
    {
        return new self($record->getAttributes());
    }

    public function populateRecord(SynonymRecord $record): void
    {
        $record->collection = $this->collection;
        $record->sortOrder = $this->sortOrder;
        $record->root = $this->root;
        $record->words = $this->words;
        $record->enabled = $this->enabled;
    }

    public function isOneWay(): bool
    {
        return $this->root !== null;
    }

    public function attributeLabels(): array
    {
        return [
            'root' => Craft::t('typesense-sync', 'Main term'),
            'words' => Craft::t('typesense-sync', 'Words'),
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['collection'], 'trim'],
            [['root'], 'filter', 'filter' => fn(mixed $root) => ($root = trim((string)$root)) === '' ? null : $root],
            [['words'], 'filter', 'filter' => [self::class, 'normaliseWords']],
            [['collection'], 'required'],
            [['collection'], 'string', 'max' => 64],
            [['root'], 'string', 'max' => self::MAX_LENGTH],
            [['sortOrder'], 'integer', 'min' => 1],
            [['words'], 'validateWords', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Trimmed, blanks dropped, each word once whatever its case, in the order first given (BR-10).
     *
     * @param mixed $words
     * @return string[]
     */
    public static function normaliseWords(mixed $words): array
    {
        $kept = [];

        foreach ((array)$words as $word) {
            $word = trim((string)$word);
            if ($word !== '' && !isset($kept[mb_strtolower($word)])) {
                $kept[mb_strtolower($word)] = $word;
            }
        }

        return array_values($kept);
    }

    /**
     * BR-10: 2-50 words of 1-255 characters; one way needs only one word beside its main term.
     */
    public function validateWords(string $attribute): void
    {
        $min = $this->isOneWay() ? 1 : 2;

        if (count($this->words) < $min) {
            $this->addError($attribute, $this->isOneWay()
                ? Craft::t('typesense-sync', 'Enter at least one word for the main term.')
                : Craft::t('typesense-sync', 'Enter at least two words.'));
        }

        if (count($this->words) > self::MAX_WORDS) {
            $this->addError($attribute, Craft::t('typesense-sync', 'A group can have at most {max} words.', ['max' => self::MAX_WORDS]));
        }

        foreach ($this->words as $word) {
            if (mb_strlen($word) > self::MAX_LENGTH) {
                $this->addError($attribute, Craft::t('typesense-sync', 'Each word can be at most {max} characters.', ['max' => self::MAX_LENGTH]));
                break;
            }
        }
    }
}
