<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\User;
use craft\helpers\Json;
use InvalidArgumentException;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\services\RulesTransfer;

/**
 * Reading an export file (BR-17, TN-11) and how a user travels in one (BR-16). Resolution against
 * real elements is integration (`RulesConsoleTest`).
 */
class RulesTransferTest extends Unit
{
    private const GOOD = [
        'version' => 1,
        'collection' => 'content',
        'rules' => [[
            'query' => 'garden',
            'matchType' => 'exact',
            'pins' => [['uid' => 'u1', 'type' => 'craft\\elements\\Entry', 'site' => 'default', 'section' => 'news', 'slug' => 'garden']],
            'hides' => [],
            'effectiveFrom' => '2030-01-01T09:00:00+00:00',
            'effectiveTo' => null,
            'enabled' => true,
        ]],
        'synonyms' => [['root' => null, 'words' => ['care home', 'nursing home'], 'enabled' => true]],
    ];

    public function testAWholeVersionOneExportIsRead(): void
    {
        $this->assertSame(self::GOOD, (new RulesTransfer())->read(Json::encode(self::GOOD)));
    }

    public function testAlsoMatchSynonymsIsReadAndMayBeMissing(): void
    {
        // TN-19: the 1.1 key is optional within version 1 (BR-16).
        $with = ['rules' => [['matchSynonyms' => true] + self::GOOD['rules'][0]]] + self::GOOD;

        $this->assertSame($with, (new RulesTransfer())->read(Json::encode($with)));
        $this->assertArrayNotHasKey('matchSynonyms', (new RulesTransfer())->read(Json::encode(self::GOOD))['rules'][0]);
    }

    /**
     * @dataProvider badFiles
     */
    public function testAnythingElseIsRefusedWithAReason(string $json, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        (new RulesTransfer())->read($json);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badFiles(): array
    {
        $rule = self::GOOD['rules'][0];

        return [
            'not JSON' => ['{', 'not valid JSON'],
            'a list' => ['[]', 'not a version 1 rules export'],
            'version 2' => [Json::encode(['version' => 2] + self::GOOD), 'not a version 1'],
            'version "1"' => [Json::encode(['version' => '1'] + self::GOOD), 'not a version 1'],
            'no collection' => [Json::encode(['collection' => ''] + self::GOOD), 'needs a collection'],
            'synonyms an object' => [Json::encode(['synonyms' => ['x' => []]] + self::GOOD), 'needs a collection'],
            'pins not a list' => [Json::encode(['rules' => [['pins' => 'x'] + $rule]] + self::GOOD), 'Rule 1 in the file is malformed'],
            'a date not ISO 8601' => [Json::encode(['rules' => [['effectiveTo' => 'next week'] + $rule]] + self::GOOD), 'Rule 1'],
            'no effectiveTo key' => [Json::encode(['rules' => [$rule, array_diff_key($rule, ['effectiveTo' => 1])]] + self::GOOD), 'Rule 2'],
            'enabled a string' => [Json::encode(['synonyms' => [['root' => null, 'words' => ['a', 'b'], 'enabled' => 'yes']]] + self::GOOD), 'Synonym group 1'],
            'a word not a string' => [Json::encode(['synonyms' => [['root' => null, 'words' => ['a', 2], 'enabled' => true]]] + self::GOOD), 'Synonym group 1'],
            'matchSynonyms a string' => [Json::encode(['rules' => [['matchSynonyms' => 'yes'] + $rule]] + self::GOOD), 'Rule 1 in the file is malformed'],
        ];
    }

    /**
     * Adopt's simple-scope filter (BR-18): what a retrieved item may carry and still be shown in
     * the CP.
     *
     * @dataProvider adoptItems
     * @param array<string, mixed> $item
     * @param list<string> $expected
     */
    public function testAdoptTakesOnlyTheSimpleRule(string $kind, array $item, array $expected): void
    {
        $this->assertSame($expected, RulesTransfer::advancedOptions($kind, $item));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function adoptItems(): array
    {
        $simple = [
            'id' => 'r1',
            'rule' => ['query' => 'garden', 'match' => 'exact'],
            'includes' => [['id' => '2', 'position' => 2], ['id' => '1', 'position' => 1]],
            'excludes' => [['id' => '3']],
            'effective_from_ts' => 1893456000,
            // As Typesense returns them: its own defaults are not options.
            'remove_matched_tokens' => false,
            'filter_curated_hits' => false,
            'stop_processing' => true,
            'filter_by' => '',
            'metadata' => [],
        ];
        $rules = Rules::KIND_RULES;
        $synonyms = Rules::KIND_SYNONYMS;

        return [
            'a simple rule, as retrieved' => [$rules, $simple, []],
            'stop_processing false' => [$rules, ['stop_processing' => false] + $simple, ['stop_processing: false']],
            'replace_query' => [$rules, ['replace_query' => 'x'] + $simple, ['replace_query']],
            'filter_by and sort_by' => [$rules, ['filter_by' => 'a:1', 'sort_by' => 'b:asc'] + $simple, ['filter_by', 'sort_by']],
            'remove_matched_tokens' => [$rules, ['remove_matched_tokens' => true] + $simple, ['remove_matched_tokens']],
            'metadata' => [$rules, ['metadata' => ['k' => 'v']] + $simple, ['metadata']],
            'diversity' => [$rules, ['diversity' => ['similarity_metric' => 'x']] + $simple, ['diversity']],
            'tags' => [$rules, ['rule' => ['query' => 'garden', 'match' => 'exact', 'tags' => ['a']]] + $simple, ['rule.tags']],
            'also match synonyms, as 30.2 returns it' => [$rules, ['rule' => ['query' => 'garden', 'match' => 'exact', 'stem' => false, 'synonyms' => true]] + $simple, []],
            'synonyms off, as 30.2 returns it' => [$rules, ['rule' => ['query' => 'garden', 'match' => 'exact', 'stem' => false, 'synonyms' => false]] + $simple, []],
            'stemming' => [$rules, ['rule' => ['query' => 'garden', 'match' => 'exact', 'stem' => true]] + $simple, ['rule.stem']],
            'synonyms not a boolean' => [$rules, ['rule' => ['query' => 'garden', 'match' => 'exact', 'synonyms' => 'yes']] + $simple, ['rule.synonyms']],
            'triggered by a filter' => [$rules, ['rule' => ['filter_by' => 'a:1']] + $simple, ['rule.filter_by', 'no rule.query', 'rule.match']],
            'a pin position gap' => [$rules, ['includes' => [['id' => '1', 'position' => 3]]] + $simple, ['includes.position']],
            'no pins' => [$rules, ['includes' => []] + $simple, []],
            'a synonym group' => [$synonyms, ['id' => 's1', 'root' => '', 'synonyms' => ['a', 'b'], 'locale' => '', 'symbols_to_index' => []], []],
            'one way' => [$synonyms, ['id' => 's1', 'root' => 'a', 'synonyms' => ['b']], []],
            'a locale' => [$synonyms, ['id' => 's1', 'synonyms' => ['a', 'b'], 'locale' => 'fr'], ['locale']],
            'symbols' => [$synonyms, ['id' => 's1', 'synonyms' => ['a', 'b'], 'symbols_to_index' => ['+']], ['symbols_to_index']],
        ];
    }

    public function testAUserTravelsByUsernameNotSlug(): void
    {
        $user = new User(['uid' => 'u2', 'username' => 'alex']);

        $ref = RulesTransfer::reference($user);

        $this->assertSame('alex', $ref['username']);
        $this->assertArrayNotHasKey('slug', $ref);
        $this->assertSame(User::class, $ref['type']);
    }
}
