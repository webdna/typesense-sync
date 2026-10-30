<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Stub;
use Codeception\Test\Unit;
use Craft;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use DateTime;
use DateTimeZone;
use Typesense\Client as TypesenseClient;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NotAFormatter;
use webdna\typesensesync\tests\fixtures\formatters\PrefixedIdFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\console\ExitCode;

/**
 * The rules console commands against the real server: export, then import on a database where one
 * pinned entry has a new uid and another is gone (TS-7); every refusal changing nothing (TN-11);
 * and an import racing an editor's save (TN-13). BR-16, BR-17, BR-4. Adopt (TS-8, TN-12, BR-18)
 * and prune (TN-10, BR-21).
 */
class RulesConsoleTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private string $file;

    /**
     * @var array<string, Section>
     */
    private array $sections = [];

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        $this->file = Craft::$app->getPath()->getTempPath() . '/' . $this->prefix . 'rules.json';
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();
        // The test container runs as root; Craft's console controllers refuse root without it.
        putenv('CRAFT_ALLOW_SUPERUSER=1');
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);

        $this->sections['news'] = $this->createSection('news');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $applied = $this->plugin->collections->apply('content');
        $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);
    }

    protected function _after(): void
    {
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);
        Db::delete(Table::QUEUE);
        TestCollections::deleteAll($this->admin, $this->prefix);
        FileHelper::unlink($this->file);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
        putenv('CRAFT_ALLOW_SUPERUSER');
    }

    // TS-7 - export and import (BR-16, BR-17) ------------------------------------------------------

    public function testExportThenImportRecreatesTheRulesAndListsWhatIsMissing(): void
    {
        $a = $this->saveEntry('Garden Room');
        $b = $this->saveEntry('Garden Bath');
        $c = $this->saveEntry('Garden Old');
        $from = new DateTime('2030-01-01 09:00:00', new DateTimeZone('UTC'));
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id, $b->id], [$c->id], $from);
        $this->saveRule('hall', Rule::MATCH_CONTAINS, [$c->id], enabled: false);
        $this->saveGroup(null, ['care home', 'nursing home']);
        $this->saveGroup('garden', ['allotment']);

        // Step 1.
        $run = $this->command('rules/export', ['content', 'file' => $this->file]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $data = Json::decode((string)file_get_contents($this->file));
        $this->assertSame(1, $data['version']);
        $this->assertSame('content', $data['collection']);
        $this->assertSame(['garden', 'hall'], array_column($data['rules'], 'query'), 'rules in order');
        $this->assertSame([
            'uid' => $a->uid,
            'type' => Entry::class,
            'site' => Craft::$app->getSites()->getPrimarySite()->handle,
            'section' => $this->sections['news']->handle,
            'slug' => 'garden-room',
        ], $data['rules'][0]['pins'][0], 'an element travels by uid and by section + slug');
        $this->assertSame('2030-01-01T09:00:00+00:00', $data['rules'][0]['effectiveFrom'], 'dates in UTC');
        $this->assertFalse($data['rules'][1]['enabled']);
        $this->assertSame([['root' => null, 'words' => ['care home', 'nursing home'], 'enabled' => true], ['root' => 'garden', 'words' => ['allotment'], 'enabled' => true]], $data['synonyms']);

        $printed = $this->command('rules/export', ['content']);
        $this->assertSame($data, Json::decode($printed['out']), 'without --file the export is printed');

        // Step 2: a fresh database's rows, an entry made separately (new uid, same slug), one gone.
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);
        $data['rules'][0]['pins'][0]['uid'] = StringHelper::UUID();
        file_put_contents($this->file, Json::encode($data));
        $this->assertTrue(Craft::$app->getElements()->deleteElement($b, true));

        // Step 3.
        $run = $this->command('rules/import', [$this->file, 'confirm' => 'content']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('Imported 2 rules and 2 synonym groups into content.', $run['out']);
        $this->assertStringContainsString($this->sections['news']->handle . '/garden-bath was not found here and was left out', $run['err'], 'the deleted entry is listed');

        $rules = $this->plugin->rules->getRules('content');
        $this->assertSame(['garden', 'hall'], array_map(fn(Rule $rule) => $rule->query, $rules), 'rows recreated in order');
        $this->assertSame([$a->id], $rules[0]->pins, 'the slug-matched entry is kept, the deleted one left out');
        $this->assertSame([$c->id], $rules[0]->hides);
        $this->assertSame($from->getTimestamp(), $rules[0]->effectiveFrom?->getTimestamp());
        $this->assertSame(Rule::MATCH_CONTAINS, $rules[1]->matchType);
        $this->assertFalse($rules[1]->enabled);
        $groups = $this->plugin->rules->getSynonymGroups('content');
        $this->assertSame([null, 'garden'], array_map(fn(SynonymGroup $group) => $group->root, $groups));

        $this->assertInStep('the import is pushed');
        $items = $this->plugin->rules->desiredSet('content', Rules::KIND_RULES)['items'];
        $this->assertSame([['id' => (string)$a->id, 'position' => 1]], $items[0]['includes'], 'the same entry is pinned here');

        // Step 4.
        $before = $this->rowIds();
        $run = $this->command('rules/import', [$this->file], ['contnet']);
        $this->assertSame(ExitCode::USAGE, $run['exit']);
        $this->assertSame($before, $this->rowIds(), 'a wrong answer changes nothing');
    }

    public function testARuleWithNothingLeftIsLeftOutAndListed(): void
    {
        $a = $this->saveEntry('Garden Room');
        $gone = $this->saveEntry('Garden Gone');
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $this->saveRule('gone', Rule::MATCH_EXACT, [$gone->id]);
        $data = $this->plugin->rulesTransfer->export('content')['data'];
        $this->assertTrue(Craft::$app->getElements()->deleteElement($gone, true));

        $result = $this->plugin->rulesTransfer->import($this->plugin->rulesTransfer->read(Json::encode($data)));

        $this->assertSame(1, $result['rules']);
        $this->assertStringContainsString('Rule “gone” was left out', implode("\n", $result['leftOut']));
        $this->assertSame(['garden'], array_map(fn(Rule $rule) => $rule->query, $this->plugin->rules->getRules('content')));
    }

    // TN-11 - a bad file changes nothing --------------------------------------------------------------

    public function testABadFileIsRefusedAndChangesNothing(): void
    {
        $a = $this->saveEntry('Garden Room');
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $this->saveGroup(null, ['care home', 'nursing home']);
        $good = $this->plugin->rulesTransfer->export('content')['data'];
        $before = $this->rowIds();

        $duplicate = $good;
        $duplicate['rules'][] = array_merge($good['rules'][0], ['query' => 'GARDEN']);
        $undeclared = array_merge($good, ['collection' => 'nowhere']);

        foreach ([
            'malformed' => '{"version": 1, "rules": [',
            'unknown version' => Json::encode(array_merge($good, ['version' => 2])),
            'no version' => Json::encode(array_diff_key($good, ['version' => true])),
            'rules not a list' => Json::encode(array_merge($good, ['rules' => ['a' => 1]])),
            'a malformed rule' => Json::encode(array_merge($good, ['rules' => [['query' => 'x']]])),
            'a bad date' => Json::encode(array_merge($good, ['rules' => [array_merge($good['rules'][0], ['effectiveFrom' => 'tomorrow'])]])),
            'a malformed group' => Json::encode(array_merge($good, ['synonyms' => [['root' => null, 'words' => [1, 2], 'enabled' => true]]])),
            'a row the CP would refuse' => Json::encode($duplicate),
            'an undeclared collection' => Json::encode($undeclared),
        ] as $case => $json) {
            file_put_contents($this->file, $json);
            $run = $this->command('rules/import', [$this->file, 'confirm' => 'content']);
            $this->assertSame(ExitCode::USAGE, $run['exit'], "$case: exit 64");
            $this->assertSame($before, $this->rowIds(), "$case: nothing changed");
        }

        $this->assertSame(ExitCode::USAGE, $this->command('rules/import', [$this->file . '.missing', 'confirm' => 'content'])['exit'], 'a missing file');
        $this->assertSame(ExitCode::USAGE, $this->command('rules/export', ['nowhere'])['exit'], 'export of an undeclared collection');

        // BR-17: 78 on bad config, before the file is read.
        file_put_contents($this->file, Json::encode($good));
        $this->useSettings($this->settings(NotAFormatter::class));
        $this->assertSame(ExitCode::CONFIG, $this->command('rules/import', [$this->file, 'confirm' => 'content'])['exit']);
        $this->assertSame(ExitCode::CONFIG, $this->command('rules/export', ['content'])['exit']);
        $this->assertSame($before, $this->rowIds());
    }

    public function testTheRefusedRowCaseNamesItsRule(): void
    {
        $a = $this->saveEntry('Garden Room');
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $data = $this->plugin->rulesTransfer->export('content')['data'];
        $data['rules'][] = array_merge($data['rules'][0], ['query' => 'GARDEN']);
        file_put_contents($this->file, Json::encode($data));

        $run = $this->command('rules/import', [$this->file, 'confirm' => 'content']);

        $this->assertSame(ExitCode::USAGE, $run['exit']);
        $this->assertStringContainsString('Rule “GARDEN” in the file was refused', $run['err']);
    }

    // TN-13 - concurrent writers ----------------------------------------------------------------------

    /**
     * An editor's save whose push arrives after an import's, and the reverse: every push sends the
     * whole set as stored when it runs, so the later push always equals the stored rows.
     */
    public function testTheLaterPushAlwaysEqualsTheStoredRows(): void
    {
        $a = $this->saveEntry('Garden Room');
        $b = $this->saveEntry('Hall Room');
        $this->saveRule('hall', Rule::MATCH_EXACT, [$b->id]);
        $file = $this->plugin->rulesTransfer->export('content')['data'];
        Db::delete(RuleRecord::TABLE);

        // The editor saves; before their push lands, an import replaces the rows and pushes.
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $this->plugin->rulesTransfer->import($file);
        $this->plugin->rules->push('content');
        $this->plugin->rules->push('content'); // the editor's late push
        $this->assertInStep('editor late');
        $this->assertSame(['hall'], $this->liveQueries());

        // The import lands first; the editor saves after it, and the import's push is the late one.
        $this->plugin->rulesTransfer->import($file);
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $this->plugin->rules->push('content');
        $this->plugin->rules->push('content'); // the import's late push
        $this->assertInStep('import late');
        $this->assertSame(['hall', 'garden'], $this->liveQueries());
    }

    // TS-8 - adopt (BR-18) ------------------------------------------------------------------------------

    public function testAdoptCopiesTheSimpleItemsAndSwapsTheLink(): void
    {
        $a = $this->saveEntry('Garden Room');
        $b = $this->saveEntry('Garden Bath');
        $this->runQueue();
        $this->saveRule('hall', Rule::MATCH_EXACT, [$a->id]);
        $this->plugin->rules->push('content');
        $old = $this->prefix . 'old';

        // Step 1. Ids out of order on purpose: Typesense applies them in lexical order.
        $this->admin->curationSets->upsert($old, ['items' => [
            ['id' => 'b-replace', 'rule' => ['query' => 'bath', 'match' => 'exact'], 'replace_query' => 'garden', 'includes' => [['id' => (string)$a->id, 'position' => 1]]],
            ['id' => 'a-simple', 'rule' => ['query' => 'garden', 'match' => 'exact'], 'includes' => [['id' => (string)$b->id, 'position' => 1], ['id' => '999999', 'position' => 2]], 'excludes' => [['id' => 'not-an-element']]],
        ]]);
        $this->linkToLive('curation_sets', $old);
        $before = $this->searchIds('garden');
        $this->assertSame([(string)$b->id, (string)$a->id], $before, 'the hand-made rule pins B');

        // Step 2.
        $run = $this->command('rules/adopt', ['content', 'curationSet' => $old]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString("Adopted 1 rule from $old into content.", $run['out']);
        $this->assertStringContainsString('Item b-replace uses more than a simple rule (replace_query) and was skipped.', $run['err']);
        $this->assertStringContainsString('Rule “garden”: document 999999 is not an entry feeding this collection and was dropped.', $run['err']);
        $this->assertStringContainsString('Rule “garden”: document not-an-element is not an entry', $run['err']);
        $this->assertStringContainsString("Live. content no longer uses $old", $run['out']);

        $rules = $this->plugin->rules->getRules('content');
        $this->assertSame(['hall', 'garden'], array_map(fn(Rule $rule) => $rule->query, $rules), 'appended after the existing rule');
        $this->assertSame([$b->id], $rules[1]->pins);
        $this->assertSame([], $rules[1]->hides);
        $this->assertSame(['hall', 'garden'], $this->liveQueries(), 'pushed');

        // Step 3.
        $schema = $this->plugin->collections->getLiveSchema('content');
        $this->assertSame([$this->prefix . 'content_rules'], Collections::normaliseSets($schema['curation_sets'] ?? []), 'owned linked, source unlinked');
        $this->assertNotNull($this->plugin->rules->liveSet(Rules::KIND_RULES, $old), 'the source set is kept');
        $this->assertInStep('after adopt');

        // Step 4.
        $this->assertSame($before, $this->searchIds('garden'), 'search behaves as before');

        // Run again: nothing is adopted twice, and there is nothing left to unlink.
        $again = $this->command('rules/adopt', ['content', 'curationSet' => $old]);
        $this->assertSame(ExitCode::OK, $again['exit'], $again['err']);
        $this->assertStringContainsString("Adopted 0 rules from $old", $again['out']);
        $this->assertStringContainsString('Item a-simple was not adopted', $again['err']);
        $this->assertStringContainsString("content did not use $old", $again['out']);
        $this->assertCount(2, $this->plugin->rules->getRules('content'));
    }

    // TS-13 - Also match synonyms travels (BR-16, BR-17, BR-18, BR-23) -----------------------------

    public function testAlsoMatchSynonymsTravelsThroughExportImportAndAdopt(): void
    {
        $a = $this->saveEntry('Garden Room');
        $b = $this->saveEntry('Garden Bath');
        $this->runQueue();
        $this->saveRule('care home', Rule::MATCH_EXACT, [$a->id], synonyms: true);
        $this->saveRule('hall', Rule::MATCH_EXACT, [$b->id]);

        // Step 1.
        $run = $this->command('rules/export', ['content', 'file' => $this->file]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $data = Json::decode((string)file_get_contents($this->file));
        $this->assertSame([true, false], array_column($data['rules'], 'matchSynonyms'));

        // Step 2.
        Db::delete(RuleRecord::TABLE);
        $run = $this->command('rules/import', [$this->file, 'confirm' => 'content']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame([true, false], array_map(fn(Rule $rule) => $rule->matchSynonyms, $this->plugin->rules->getRules('content')));
        $items = $this->plugin->rules->desiredSet('content', Rules::KIND_RULES)['items'];
        $this->assertSame(['query' => 'care home', 'match' => 'exact', 'synonyms' => true], $items[0]['rule']);
        $this->assertSame(['query' => 'hall', 'match' => 'exact'], $items[1]['rule'], 'off is not sent');
        $this->assertInStep('the import is pushed');

        // Step 3: the set read back as 30.2 answers, which the 30.0 container cannot.
        Db::delete(RuleRecord::TABLE);
        $old = $this->prefix . 'old';
        $item = ['id' => 'r1', 'rule' => ['query' => 'garden', 'match' => 'exact', 'synonyms' => true], 'includes' => [['id' => (string)$a->id, 'position' => 1]], 'filter_curated_hits' => true, 'stop_processing' => true];
        $this->admin->curationSets->upsert($old, ['items' => [$item]]);
        $this->linkToLive('curation_sets', $old);
        $real = $this->plugin->rules;
        $this->plugin->set('rules', Stub::construct(Rules::class, [], [
            'liveSet' => function(string $kind, string $name) use ($real, $old): ?array {
                $set = $real->liveSet($kind, $name);

                if ($name === $old && $set !== null) {
                    foreach ($set['items'] as $i => $read) {
                        $set['items'][$i]['rule'] += ['stem' => false, 'synonyms' => true];
                    }
                }

                return $set;
            },
        ]));

        try {
            $run = $this->command('rules/adopt', ['content', 'curationSet' => $old]);
        } finally {
            $this->plugin->set('rules', $real);
        }

        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString("Adopted 1 rule from $old into content.", $run['out']);
        $this->assertStringNotContainsString('does not report', $run['err']);
        $this->assertSame([true], array_map(fn(Rule $rule) => $rule->matchSynonyms, $real->getRules('content')));

        // Step 4: the 30.0 container's own read has no synonyms key.
        Db::delete(RuleRecord::TABLE);
        $this->admin->curationSets->upsert($old . '2', ['items' => [$item]]);
        $this->assertArrayNotHasKey('synonyms', $real->liveSet(Rules::KIND_RULES, $old . '2')['items'][0]['rule'] ?? []);
        $run = $this->command('rules/adopt', ['content', 'curationSet' => $old . '2']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame([false], array_map(fn(Rule $rule) => $rule->matchSynonyms, $real->getRules('content')));
        $this->assertStringContainsString('This Typesense server does not report whether a rule also matches synonyms', $run['err']);
    }

    public function testAdoptCopiesSynonymGroupsAndSkipsALocale(): void
    {
        $old = $this->prefix . 'oldsyn';
        $this->admin->synonymSets->upsert($old, ['items' => [
            ['id' => 'all', 'synonyms' => ['care home', 'nursing home']],
            ['id' => 'fr', 'synonyms' => ['maison', 'foyer'], 'locale' => 'fr'],
            ['id' => 'one', 'root' => 'garden', 'synonyms' => ['allotment']],
        ]]);
        $this->linkToLive('synonym_sets', $old);

        $run = $this->command('rules/adopt', ['content', 'synonymSet' => $old]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString("Adopted 2 synonym groups from $old into content.", $run['out']);
        $this->assertStringContainsString('Item fr uses more than a simple rule (locale)', $run['err']);
        $this->assertSame(
            [[null, ['care home', 'nursing home']], ['garden', ['allotment']]],
            array_map(fn(SynonymGroup $group) => [$group->root, $group->words], $this->plugin->rules->getSynonymGroups('content')),
        );
        $schema = $this->plugin->collections->getLiveSchema('content');
        $this->assertSame([$this->prefix . 'content_synonyms'], Collections::normaliseSets($schema['synonym_sets'] ?? []));
        $this->assertInStep('after adopt');

        $again = $this->command('rules/adopt', ['content', 'synonymSet' => $old]);
        $this->assertSame(ExitCode::OK, $again['exit'], $again['err']);
        $this->assertStringContainsString('Item all is already a synonym group here', $again['err']);
        $this->assertCount(2, $this->plugin->rules->getSynonymGroups('content'), 'nothing adopted twice');
    }

    public function testAdoptFindsTheElementBehindAnOverriddenDocumentId(): void
    {
        $this->useSettings($this->settings(PrefixedIdFormatter::class));
        $a = $this->saveEntry('Garden Room');
        $old = $this->prefix . 'old';
        $this->admin->curationSets->upsert($old, ['items' => [
            ['id' => 'x', 'rule' => ['query' => 'garden', 'match' => 'contains'], 'includes' => [['id' => 'doc-' . $a->id, 'position' => 1]], 'excludes' => [['id' => (string)$a->id]]],
        ]]);

        $run = $this->command('rules/adopt', ['content', 'curationSet' => $old]);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertSame([$a->id], $this->plugin->rules->getRules('content')[0]->pins, 'doc-<id> is the element');
        $this->assertStringContainsString("document {$a->id} is not an entry", $run['err'], 'the bare id is not this formatter\'s document');
    }

    // TN-12 - adopt refusals ------------------------------------------------------------------------------

    public function testAdoptRefusesAMissingSetOrTwoSetsChangingNothing(): void
    {
        $owned = $this->prefix . 'content_rules';
        $cases = [
            'no such set' => [['content', 'curationSet' => $this->prefix . 'nope'], 'There is no curation set'],
            'no such synonym set' => [['content', 'synonymSet' => $this->prefix . 'nope'], 'There is no synonym set'],
            'both' => [['content', 'curationSet' => 'a', 'synonymSet' => 'b'], 'Name one set to adopt'],
            'neither' => [['content'], 'Name one set to adopt'],
            'its own set' => [['content', 'curationSet' => $owned], 'is the set the plugin keeps for content'],
            'undeclared' => [['gone', 'curationSet' => 'a'], 'No collection “gone” is declared'],
        ];

        foreach ($cases as $case => [$params, $reason]) {
            $run = $this->command('rules/adopt', $params);
            $this->assertSame(ExitCode::USAGE, $run['exit'], $case);
            $this->assertStringContainsString($reason, $run['err'], $case);
        }

        $this->assertSame(['rules' => [], 'synonyms' => []], $this->rowIds(), 'nothing written');
    }

    // TN-10 - prune (BR-21) -------------------------------------------------------------------------------

    public function testPruneDeletesAnUndeclaredCollectionsRowsAfterConfirming(): void
    {
        $a = $this->saveEntry('Garden Room');
        $this->saveRule('garden', Rule::MATCH_EXACT, [$a->id]);
        $this->saveGroup(null, ['care home', 'nursing home']);
        // The config no longer declares "gone", but its rows are still here.
        $record = new RuleRecord();
        (new Rule(['collection' => 'gone', 'sortOrder' => 1, 'query' => 'q', 'matchType' => Rule::MATCH_EXACT, 'pins' => [1]]))->populateRecord($record);
        $record->save(false);
        $record = new SynonymRecord();
        (new SynonymGroup(['collection' => 'gone', 'words' => ['a', 'b']]))->populateRecord($record);
        $record->save(false);
        $this->assertSame(['gone' => ['rules' => 1, 'synonyms' => 1]], $this->plugin->rules->orphans());

        $wrong = $this->command('rules/prune', ['gone'], ['content']);
        $this->assertSame(ExitCode::USAGE, $wrong['exit'], 'a wrong answer refuses');
        $this->assertSame(['gone' => ['rules' => 1, 'synonyms' => 1]], $this->plugin->rules->orphans());

        $declared = $this->command('rules/prune', ['content', 'confirm' => 'content']);
        $this->assertSame(ExitCode::USAGE, $declared['exit']);
        $this->assertStringContainsString('“content” is still declared', $declared['err']);

        $unknown = $this->command('rules/prune', ['nope', 'confirm' => 'nope']);
        $this->assertSame(ExitCode::USAGE, $unknown['exit']);
        $this->assertStringContainsString('Orphaned: gone.', $unknown['err']);

        $run = $this->command('rules/prune', ['gone'], ['gone']);
        $this->assertSame(ExitCode::OK, $run['exit'], $run['err']);
        $this->assertStringContainsString('Deleted 1 rule and 1 synonym group kept for gone.', $run['out']);
        $this->assertSame([], $this->plugin->rules->orphans());
        $this->assertCount(1, $this->plugin->rules->getRules('content'), 'a declared collection keeps its rows');
        $this->assertCount(1, $this->plugin->rules->getSynonymGroups('content'));
    }

    // Helpers -----------------------------------------------------------------------------------------

    /**
     * Link a set to the live collection by hand, as a developer in the dashboard would.
     */
    private function linkToLive(string $key, string $name): void
    {
        $schema = $this->plugin->collections->getLiveSchema('content');
        $this->assertNotNull($schema);
        $this->admin->collections[(string)$schema['name']]->update([$key => [...(array)($schema[$key] ?? []), $name]]);
    }

    /**
     * @return list<string>
     */
    private function searchIds(string $q): array
    {
        $result = $this->admin->collections[$this->prefix . 'content']->documents->search(['q' => $q, 'query_by' => 'title', 'drop_tokens_threshold' => 0]);

        return array_map(fn(array $hit) => (string)$hit['document']['id'], (array)$result['hits']);
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
    }

    private function assertInStep(string $message): void
    {
        foreach ($this->plugin->rules->state('content') as $kind => $state) {
            $this->assertSame(Rules::STATE_IN_STEP, $state['state'], "$message: $kind " . ($state['message'] ?? ''));
        }
    }

    /**
     * @return list<string>
     */
    private function liveQueries(): array
    {
        $set = $this->plugin->rules->liveSet(Rules::KIND_RULES, $this->prefix . 'content_rules');
        $this->assertNotNull($set);

        return array_map(fn(array $item) => (string)$item['rule']['query'], array_values((array)$set['items']));
    }

    /**
     * Every row of both tables, with what an import would change.
     *
     * @return array<string, mixed>
     */
    private function rowIds(): array
    {
        return [
            'rules' => array_map(fn(Rule $rule) => [$rule->id, $rule->query, $rule->pins], $this->plugin->rules->getRules('content')),
            'synonyms' => array_map(fn(SynonymGroup $group) => [$group->id, $group->words], $this->plugin->rules->getSynonymGroups('content')),
        ];
    }

    /**
     * @param int[] $pins
     * @param int[] $hides
     */
    private function saveRule(string $query, string $match, array $pins = [], array $hides = [], ?DateTime $from = null, bool $enabled = true, bool $synonyms = false): Rule
    {
        $rule = new Rule([
            'collection' => 'content',
            'query' => $query,
            'matchType' => $match,
            'matchSynonyms' => $synonyms,
            'pins' => $pins,
            'hides' => $hides,
            'effectiveFrom' => $from,
            'enabled' => $enabled,
        ]);
        $this->assertTrue($this->plugin->rules->saveRule($rule), implode(' ', $rule->getFirstErrors()));

        return $rule;
    }

    /**
     * @param string[] $words
     */
    private function saveGroup(?string $root, array $words): void
    {
        $group = new SynonymGroup(['collection' => 'content', 'root' => $root, 'words' => $words]);
        $this->assertTrue($this->plugin->rules->saveSynonymGroup($group), implode(' ', $group->getFirstErrors()));
    }

    /**
     * Run a command as the console would, capturing its output (ConsoleTest's pattern).
     *
     * @param array<int|string, mixed> $params
     * @param list<string> $answers
     * @return array{exit: int, out: string, err: string}
     */
    private function command(string $route, array $params = [], array $answers = []): array
    {
        $created = $this->plugin->createController($route);
        $this->assertIsArray($created, "no command $route");
        [$controller, $action] = $created;
        $this->assertInstanceOf(Controller::class, $controller);

        $out = '';
        $err = '';

        /** @var Controller $stub */
        $stub = Stub::construct($controller::class, [$controller->id, $this->plugin], [
            'stdout' => function(string $string) use (&$out): int {
                $out .= $string;

                return strlen($string);
            },
            'stderr' => function(string $string) use (&$err): int {
                $err .= $string;

                return strlen($string);
            },
            'prompt' => function() use (&$answers): string {
                return array_shift($answers) ?? '';
            },
        ]);
        $exit = $stub->runAction($action, $params);

        return ['exit' => (int)$exit, 'out' => $out, 'err' => $err];
    }

    private function settings(string $formatter = DocumentFormatter::class): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => []],
            'sources' => [['handle' => $this->sections['news']->handle, 'collection' => 'content', 'formatter' => $formatter]],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
    }

    private function createSection(string $name): Section
    {
        $handle = $name . substr($this->prefix, 2, 8);
        $entryType = new EntryType(['name' => ucfirst($name), 'handle' => $handle . 'Type']);
        // Without a title field in its layout, an entry's title is not saved.
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new EntryTitleField()]])]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), implode(' ', $entryType->getFirstErrors()));

        $section = new Section([
            'name' => ucfirst($name),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [new Section_SiteSettings([
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $name . '/{slug}',
                'template' => '_entry',
            ])],
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(Craft::$app->getEntries()->saveSection($section), implode(' ', $section->getFirstErrors()));

        return $section;
    }

    private function saveEntry(string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->sections['news']->id,
            'typeId' => $this->sections['news']->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }
}
