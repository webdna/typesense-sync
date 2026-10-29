<?php

namespace webdna\typesensesync\migrations;

use craft\db\Migration;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;

/**
 * The plugin's tables: the curation rules and synonym groups editors manage in the CP.
 *
 * Uninstalling drops both and leaves the Typesense sets attached and intact (BR-20): deleting a
 * set a collection still links breaks every search on it, and unlinking would change live search.
 *
 * @since 1.1.0
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(RuleRecord::TABLE);
        $this->dropTableIfExists(SynonymRecord::TABLE);

        return true;
    }

    /**
     * Creates whichever of the two tables is missing, with its index. Shared with the 1.1.0
     * update migration, which an install never runs.
     */
    public function createTables(): void
    {
        if (!$this->db->tableExists(RuleRecord::TABLE)) {
            $this->createTable(RuleRecord::TABLE, [
                'id' => $this->primaryKey(),
                'collection' => $this->string(64)->notNull(),
                'sortOrder' => $this->smallInteger()->unsigned()->notNull(),
                'query' => $this->string(255)->notNull(),
                'matchType' => $this->enum('matchType', Rule::MATCH_TYPES)->notNull(),
                'pins' => $this->json(),
                'hides' => $this->json(),
                'effectiveFrom' => $this->dateTime(),
                'effectiveTo' => $this->dateTime(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, RuleRecord::TABLE, ['collection', 'sortOrder']);
        }

        if (!$this->db->tableExists(SynonymRecord::TABLE)) {
            $this->createTable(SynonymRecord::TABLE, [
                'id' => $this->primaryKey(),
                'collection' => $this->string(64)->notNull(),
                'sortOrder' => $this->smallInteger()->unsigned()->notNull(),
                'root' => $this->string(255),
                'words' => $this->json(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, SynonymRecord::TABLE, ['collection', 'sortOrder']);
        }
    }
}
