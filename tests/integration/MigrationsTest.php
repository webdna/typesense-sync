<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use webdna\typesensesync\migrations\Install;
use webdna\typesensesync\migrations\m261029_000000_rules;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;

/**
 * The rules and synonyms tables: made on install, made on an upgrade from 1.0.x, and dropped on
 * uninstall (BR-20). Each test leaves both tables in place for the rest of the suite.
 */
class MigrationsTest extends Unit
{
    protected function _after(): void
    {
        (new Install())->createTables();
    }

    public function testInstallMadeBothTables(): void
    {
        $this->assertTablesExist(true);
    }

    public function testUninstallDropsBothTables(): void
    {
        $this->assertTrue((new Install())->safeDown());

        $this->assertTablesExist(false);
    }

    public function testTheUpgradeMakesWhicheverTableIsMissing(): void
    {
        Craft::$app->getDb()->createCommand()->dropTable(SynonymRecord::TABLE)->execute();
        $this->assertTablesExist(false, SynonymRecord::TABLE);

        $this->assertTrue((new m261029_000000_rules())->safeUp());

        $this->assertTablesExist(true);
    }

    public function testTheUpgradeIsNotReverted(): void
    {
        ob_start();
        $reverted = (new m261029_000000_rules())->safeDown();
        ob_end_clean();

        $this->assertFalse($reverted);
        $this->assertTablesExist(true);
    }

    private function assertTablesExist(bool $exist, string ...$tables): void
    {
        $db = Craft::$app->getDb();
        $db->getSchema()->refresh();

        foreach ($tables ?: [RuleRecord::TABLE, SynonymRecord::TABLE] as $table) {
            $this->assertSame($exist, $db->tableExists($table), $table);
        }
    }
}
