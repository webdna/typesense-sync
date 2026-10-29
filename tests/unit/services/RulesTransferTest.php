<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\User;
use craft\helpers\Json;
use InvalidArgumentException;
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
