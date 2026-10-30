<?php

namespace webdna\typesensesync\migrations;

use craft\db\Migration;
use webdna\typesensesync\records\RuleRecord;

/**
 * 1.1.0: adds a rule's "Also match synonyms" option (BR-23), off for every existing rule, to a
 * rules table made before it. A fresh install, and an upgrade from 1.0.x, get the column from
 * {@see Install}.
 *
 * @since 1.1.0
 */
class m261030_000000_rule_synonyms extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(RuleRecord::TABLE, 'matchSynonyms')) {
            $this->addColumn(RuleRecord::TABLE, 'matchSynonyms', $this->boolean()->notNull()->defaultValue(false)->after('matchType'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261030_000000_rule_synonyms cannot be reverted: it would change what editors' rules match.\n";

        return false;
    }
}
