<?php

namespace webdna\typesensesync\tests\unit\models;

use Codeception\Test\Unit;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\SynonymRecord;

/**
 * A synonym group's validation (BR-10) and its round trip through the table.
 */
class SynonymGroupTest extends Unit
{
    public function testAllEqualNeedsTwoToFiftyWords(): void
    {
        $this->assertTrue($this->group(['words' => ['care home', 'nursing home']])->validate());
        $this->assertTrue($this->group(['words' => $this->words(50)])->validate());

        foreach ([['care home'], $this->words(51)] as $words) {
            $group = $this->group(['words' => $words]);
            $this->assertFalse($group->validate(), 'refused: ' . count($words) . ' words');
            $this->assertArrayHasKey('words', $group->getErrors());
        }
    }

    public function testOneWayNeedsOneWordBesideItsMainTerm(): void
    {
        $group = $this->group(['root' => ' nursing home ', 'words' => ['care home']]);

        $this->assertTrue($group->validate());
        $this->assertSame('nursing home', $group->root);
        $this->assertTrue($group->isOneWay());

        $this->assertFalse($this->group(['root' => 'nursing home', 'words' => []])->validate());
    }

    public function testABlankMainTermMakesTheGroupAllEqual(): void
    {
        $group = $this->group(['root' => '  ', 'words' => ['care home']]);

        $this->assertFalse($group->validate(), 'all equal needs two words');
        $this->assertNull($group->root);
        $this->assertFalse($group->isOneWay());
    }

    public function testWordsAreTrimmedBlanksDroppedAndEachKeptOnceInAnyCase(): void
    {
        $group = $this->group(['words' => [' Care Home ', '', '   ', 'care home', 'nursing home', 'NURSING HOME']]);

        $this->assertTrue($group->validate());
        $this->assertSame(['Care Home', 'nursing home'], $group->words);
    }

    public function testAWordOrMainTermIsAtMost255Characters(): void
    {
        $this->assertTrue($this->group(['words' => [str_repeat('a', 255), 'b']])->validate());
        $this->assertFalse($this->group(['words' => [str_repeat('a', 256), 'b']])->validate());

        $root = $this->group(['root' => str_repeat('a', 256), 'words' => ['b']]);
        $this->assertFalse($root->validate());
        $this->assertArrayHasKey('root', $root->getErrors());
    }

    public function testAGroupSurvivesTheTable(): void
    {
        $group = $this->group(['root' => 'nursing home', 'words' => ['care home', 'residential care'], 'sortOrder' => 3]);
        $this->assertTrue($group->validate());

        $record = new SynonymRecord();
        $group->populateRecord($record);
        $this->assertTrue($record->save());

        $loaded = SynonymGroup::fromRecord(SynonymRecord::findOne($record->id) ?? new SynonymRecord());
        $this->assertSame('content', $loaded->collection);
        $this->assertSame(3, $loaded->sortOrder);
        $this->assertSame('nursing home', $loaded->root);
        $this->assertSame(['care home', 'residential care'], $loaded->words);
        $this->assertTrue($loaded->enabled);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function group(array $config): SynonymGroup
    {
        return new SynonymGroup($config + ['collection' => 'content']);
    }

    /**
     * @return string[]
     */
    private function words(int $n): array
    {
        return array_map(fn(int $i) => 'word ' . $i, range(1, $n));
    }
}
