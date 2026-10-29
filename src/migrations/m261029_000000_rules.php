<?php

namespace webdna\typesensesync\migrations;

use craft\db\Migration;

/**
 * 1.1.0: adds the rules and synonyms tables to a site installed on 1.0.x. A fresh install gets
 * them from {@see Install} and never runs this.
 *
 * @since 1.1.0
 */
class m261029_000000_rules extends Migration
{
    public function safeUp(): bool
    {
        (new Install(['db' => $this->db]))->createTables();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261029_000000_rules cannot be reverted: it would delete editors' rules.\n";

        return false;
    }
}
