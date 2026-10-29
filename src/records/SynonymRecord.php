<?php

namespace webdna\typesensesync\records;

use craft\db\ActiveRecord;

/**
 * One row of `typesensesync_synonyms`: a synonym group for one collection.
 *
 * `root` null means all equal; set, it is the main term the words lead to (one way).
 *
 * @property int $id
 * @property string $collection
 * @property int $sortOrder
 * @property string|null $root
 * @property string[]|string $words
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @since 1.1.0
 */
class SynonymRecord extends ActiveRecord
{
    public const TABLE = '{{%typesensesync_synonyms}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
