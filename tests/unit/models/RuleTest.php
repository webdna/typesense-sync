<?php

namespace webdna\typesensesync\tests\unit\models;

use Codeception\Test\Unit;
use DateTime;
use DateTimeZone;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\records\RuleRecord;

/**
 * A rule's validation (BR-6, BR-7, BR-11), its status, and its round trip through the table.
 */
class RuleTest extends Unit
{
    public function testAPinOrAHideIsEnough(): void
    {
        $this->assertTrue($this->rule(['pins' => [1]])->validate());
        $this->assertTrue($this->rule(['pins' => [], 'hides' => [2]])->validate());
    }

    public function testARuleWithNothingPinnedOrHiddenIsRefused(): void
    {
        $rule = $this->rule(['pins' => [], 'hides' => []]);

        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('pins', $rule->getErrors());
    }

    public function testTheQueryIsTrimmedAndMustBeOneTo255Characters(): void
    {
        $rule = $this->rule(['query' => '  garden  ']);
        $this->assertTrue($rule->validate());
        $this->assertSame('garden', $rule->query);

        $this->assertTrue($this->rule(['query' => str_repeat('a', 255)])->validate());

        foreach (['   ', str_repeat('a', 256)] as $query) {
            $rule = $this->rule(['query' => $query]);
            $this->assertFalse($rule->validate(), 'refused: ' . strlen($query) . ' characters');
            $this->assertArrayHasKey('query', $rule->getErrors());
        }
    }

    public function testTheMatchTypeIsExactOrContains(): void
    {
        $this->assertTrue($this->rule(['matchType' => Rule::MATCH_CONTAINS])->validate());
        $this->assertFalse($this->rule(['matchType' => 'starts'])->validate());
    }

    public function testASecondRuleWithTheSameQueryInAnyCaseAndMatchIsRefused(): void
    {
        $first = $this->save($this->rule(['query' => 'Garden']));

        $same = $this->rule(['query' => 'gARDEN ']);
        $this->assertFalse($same->validate());
        $this->assertArrayHasKey('query', $same->getErrors());

        $this->assertTrue($this->rule(['query' => 'garden', 'matchType' => Rule::MATCH_CONTAINS])->validate(), 'another match type');
        $this->assertTrue($this->rule(['query' => 'garden', 'collection' => 'other'])->validate(), 'another collection');
        $this->assertTrue($first->validate(), 'a rule does not clash with itself');
    }

    public function testAtMostTwentyPinsAndAHundredHides(): void
    {
        $this->assertTrue($this->rule(['pins' => range(1, 20), 'hides' => range(101, 200)])->validate());

        $pins = $this->rule(['pins' => range(1, 21)]);
        $this->assertFalse($pins->validate());
        $this->assertArrayHasKey('pins', $pins->getErrors());

        $hides = $this->rule(['pins' => [], 'hides' => range(1, 101)]);
        $this->assertFalse($hides->validate());
        $this->assertArrayHasKey('hides', $hides->getErrors());
    }

    public function testAnElementCannotBeBothPinnedAndHidden(): void
    {
        $rule = $this->rule(['pins' => [1, 2], 'hides' => [2]]);

        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('pins', $rule->getErrors());
    }

    public function testIdsAreIntegersEachOnceInTheirFirstOrder(): void
    {
        $rule = $this->rule(['pins' => ['3', 1, 3, '', 'x', 0, 2]]);
        $rule->validate();

        $this->assertSame([3, 1, 2], $rule->pins);
    }

    public function testEndsMustBeAfterStarts(): void
    {
        $from = new DateTime('2026-11-01 09:00', new DateTimeZone('UTC'));

        $this->assertTrue($this->rule(['effectiveFrom' => $from, 'effectiveTo' => new DateTime('2026-11-02 09:00')])->validate());
        $this->assertTrue($this->rule(['effectiveTo' => $from])->validate(), 'an end alone');

        foreach (['2026-11-01 09:00', '2026-10-31 09:00'] as $to) {
            $rule = $this->rule(['effectiveFrom' => $from, 'effectiveTo' => new DateTime($to, new DateTimeZone('UTC'))]);
            $this->assertFalse($rule->validate(), 'refused: ends ' . $to);
            $this->assertArrayHasKey('effectiveTo', $rule->getErrors());
        }
    }

    public function testStatusFollowsTheSwitchThenTheDates(): void
    {
        $now = new DateTime('2026-11-01 12:00', new DateTimeZone('UTC'));
        $before = new DateTime('2026-10-01', new DateTimeZone('UTC'));
        $after = new DateTime('2026-12-01', new DateTimeZone('UTC'));

        $this->assertSame(Rule::STATUS_ON, $this->rule()->getStatus($now));
        $this->assertSame(Rule::STATUS_ON, $this->rule(['effectiveFrom' => $before, 'effectiveTo' => $after])->getStatus($now));
        $this->assertSame(Rule::STATUS_SCHEDULED, $this->rule(['effectiveFrom' => $after])->getStatus($now));
        $this->assertSame(Rule::STATUS_ENDED, $this->rule(['effectiveTo' => $before])->getStatus($now));
        $this->assertSame(Rule::STATUS_ENDED, $this->rule(['effectiveTo' => $now])->getStatus($now), 'ends at its end');
        $this->assertSame(Rule::STATUS_OFF, $this->rule(['enabled' => false, 'effectiveFrom' => $after])->getStatus($now));
    }

    public function testARuleSurvivesTheTableWithItsOrderAndDatesInUtc(): void
    {
        $saved = $this->save($this->rule([
            'pins' => [5, 3, 9],
            'hides' => [7],
            'effectiveFrom' => new DateTime('2026-11-01 09:00', new DateTimeZone('Europe/London')),
            'effectiveTo' => new DateTime('2026-11-02 09:00', new DateTimeZone('Europe/London')),
            'enabled' => false,
        ]));

        $record = RuleRecord::findOne($saved->id);
        $this->assertNotNull($record);
        $this->assertSame('2026-11-01 09:00:00', $record->effectiveFrom, 'stored in UTC (GMT in November)');

        $loaded = Rule::fromRecord($record);
        $this->assertSame([5, 3, 9], $loaded->pins);
        $this->assertSame([7], $loaded->hides);
        // Read back in the system timezone; the instant is what matters.
        $this->assertSame((new DateTime('2026-11-01 09:00', new DateTimeZone('UTC')))->getTimestamp(), $loaded->effectiveFrom?->getTimestamp());
        $this->assertSame((new DateTime('2026-11-02 09:00', new DateTimeZone('UTC')))->getTimestamp(), $loaded->effectiveTo?->getTimestamp());
        $this->assertFalse($loaded->enabled);
        $this->assertSame(Rule::MATCH_EXACT, $loaded->matchType);
        $this->assertNotNull($loaded->uid);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function rule(array $config = []): Rule
    {
        return new Rule($config + [
            'collection' => 'content',
            'query' => 'garden',
            'matchType' => Rule::MATCH_EXACT,
            'pins' => [1],
        ]);
    }

    private function save(Rule $rule): Rule
    {
        $this->assertTrue($rule->validate(), implode(' ', $rule->getErrorSummary(true)));

        $record = new RuleRecord();
        $rule->populateRecord($record);
        $this->assertTrue($record->save());
        $rule->id = $record->id;

        return $rule;
    }
}
