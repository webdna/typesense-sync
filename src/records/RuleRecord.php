<?php

namespace webdna\typesensesync\records;

use craft\db\ActiveRecord;

/**
 * One row of `typesensesync_rules`: a curation rule for one collection, as an editor saved it.
 *
 * Rows are content, never project config (BR-20 drops the table on uninstall). Pins and hides are
 * element ids; the Typesense item id and each document id are derived at push time, never stored.
 *
 * @property int $id
 * @property string $collection
 * @property int $sortOrder
 * @property string $query
 * @property string $matchType
 * @property int[]|string $pins
 * @property int[]|string $hides
 * @property string|null $effectiveFrom
 * @property string|null $effectiveTo
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @since 1.1.0
 */
class RuleRecord extends ActiveRecord
{
    public const TABLE = '{{%typesensesync_rules}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
