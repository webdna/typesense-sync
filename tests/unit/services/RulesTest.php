<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use webdna\typesensesync\services\Rules;

/**
 * Comparing what Typesense holds with what a push would send (BR-14). The retrieved shapes are
 * the ones Typesense 30.0 answers with: it fills in `remove_matched_tokens: false` on a curation
 * item and `root: ""` on an all-equal synonym item.
 */
class RulesTest extends Unit
{
    public function testARetrievedItemEqualsTheOneSent(): void
    {
        $sent = [
            'id' => 'rule-0001',
            'rule' => ['query' => 'garden', 'match' => 'exact'],
            'includes' => [['id' => '2', 'position' => 1]],
            'excludes' => [['id' => '3']],
            'filter_curated_hits' => true,
            'stop_processing' => true,
        ];
        $retrieved = [
            'excludes' => [['id' => '3']],
            'filter_curated_hits' => true,
            'id' => 'rule-0001',
            'includes' => [['id' => '2', 'position' => 1]],
            'remove_matched_tokens' => false,
            'rule' => ['match' => 'exact', 'query' => 'garden'],
            'stop_processing' => true,
        ];

        $this->assertSame(0, Rules::countDiffering([$sent], [$retrieved]));
        $this->assertSame(0, Rules::countDiffering(
            [['id' => 'synonym-0001', 'synonyms' => ['care home', 'nursing home']]],
            [['id' => 'synonym-0001', 'root' => '', 'synonyms' => ['care home', 'nursing home']]],
        ));
    }

    public function testChangedMissingAndExtraItemsEachCountOnce(): void
    {
        $wanted = [
            ['id' => 'synonym-0001', 'synonyms' => ['a', 'b']],
            ['id' => 'synonym-0002', 'synonyms' => ['c', 'd']],
        ];

        $this->assertSame(1, Rules::countDiffering($wanted, [$wanted[0]]), 'missing');
        $this->assertSame(1, Rules::countDiffering([$wanted[0]], $wanted), 'extra');
        $this->assertSame(1, Rules::countDiffering($wanted, [$wanted[0], ['id' => 'synonym-0002', 'synonyms' => ['d', 'c']]]), 'order of words');
        $this->assertSame(2, Rules::countDiffering($wanted, []));
    }

    public function testADashboardEditThatSwitchesSomethingOffIsADifference(): void
    {
        $sent = ['id' => 'rule-0001', 'rule' => ['query' => 'a', 'match' => 'exact'], 'excludes' => [['id' => '1']], 'filter_curated_hits' => true, 'stop_processing' => true];

        $this->assertSame(1, Rules::countDiffering([$sent], [['filter_curated_hits' => false] + $sent]));
        $this->assertSame(1, Rules::countDiffering([$sent], [['replace_query' => 'b'] + $sent]));
    }
}
