<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\services\UserPermissions;
use craft\web\Request;
use craft\web\TemplateResponseBehavior;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\UrlRule;
use craft\web\View;
use DateTime;
use Throwable;
use Typesense\Client as TypesenseClient;
use webdna\typesensesync\jobs\PushRules;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\records\RuleRecord;
use webdna\typesensesync\records\SynonymRecord;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use webdna\typesensesync\web\SectionController;
use yii\base\Event;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Typesense section through Craft's real controller dispatch: the one permission (BR-13;
 * TS-9, TN-1), the section only while connected (BR-19; TN-14), undeclared collections hidden
 * (TN-10's section half), and each action's write going live, or saved and queued when the push
 * fails (BR-4).
 */
class RulesControllerTest extends Unit
{
    /**
     * Every POST action, by route.
     */
    private const ACTIONS = [
        'rules/save', 'rules/delete', 'rules/reorder', 'rules/toggle', 'rules/push',
        'synonyms/save', 'synonyms/delete', 'synonyms/reorder', 'synonyms/toggle',
    ];

    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private CmsEdition $edition;

    private string $controllerNamespace;

    private Section $news;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        // As in UtilityTest: the CLI makes the plugin pick its console controllers.
        $this->controllerNamespace = $this->plugin->controllerNamespace;
        $this->plugin->controllerNamespace = 'webdna\typesensesync\controllers';
        // Solo lets every user do everything; permissions only mean something on Pro.
        $this->edition = Craft::$app->edition;
        Craft::$app->edition = CmsEdition::Pro;
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);
        Db::delete(Table::QUEUE);

        $this->news = $this->createSection('news');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $this->plugin->collections->apply('content');
    }

    protected function _after(): void
    {
        Db::delete(RuleRecord::TABLE);
        Db::delete(SynonymRecord::TABLE);
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->edition = $this->edition;
        $this->plugin->controllerNamespace = $this->controllerNamespace;
        Craft::$app->getRequest()->setIsCpRequest(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // BR-13 ------------------------------------------------------------------------------------

    public function testThePermissionIsRegisteredUnderOneLabel(): void
    {
        $event = new RegisterUserPermissionsEvent(['permissions' => []]);
        Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);
        $ours = array_values(array_filter($event->permissions, fn($group) => isset($group['permissions'][TypesenseSync::PERMISSION_RULES])));

        $this->assertCount(1, $ours);
        $this->assertSame(['label' => 'Manage Typesense rules'], $ours[0]['permissions'][TypesenseSync::PERMISSION_RULES]);
        $this->assertCount(1, $ours[0]['permissions'], 'one permission, nothing else under it');
    }

    public function testWithoutThePermissionEveryScreenAndActionIs403AndNothingIsWritten(): void
    {
        // TS-9 step 1, TN-1.
        $rule = $this->savedRule('garden');
        $group = $this->savedGroup(['care home', 'nursing home']);
        $this->plugin->rules->push('content');
        $before = $this->setItems();
        $user = $this->user(['accessCp']);

        $this->assertNull($this->navItem($user), 'no nav item');

        foreach ($this->screens($rule, $group) as [$route, $params]) {
            $this->assertRefused(ForbiddenHttpException::class, $route, $params, $user, ['method' => 'GET']);
        }

        foreach (self::ACTIONS as $action) {
            $this->assertRefused(ForbiddenHttpException::class, $action, $this->validBody($action, $rule, $group), $user);
        }

        $this->assertSame([$rule->id], $this->ruleIds(), 'nothing added or deleted');
        $this->assertSame('garden', $this->plugin->rules->getRuleById((int)$rule->id)?->query);
        $this->assertTrue($this->plugin->rules->getRuleById((int)$rule->id)->enabled, 'not switched off');
        $this->assertSame(1, (int)SynonymRecord::find()->count());
        $this->assertSame($before, $this->setItems(), 'nothing pushed');
        $this->assertSame(0, $this->countJobs(), 'nothing queued');
    }

    public function testAnAnonymousRequestIsRefused(): void
    {
        $rule = $this->savedRule('garden');

        $this->assertNull($this->navItem(null));
        $this->assertRefused(ForbiddenHttpException::class, 'rules/index', ['collection' => 'content'], null, ['method' => 'GET']);
        $this->assertRefused(ForbiddenHttpException::class, 'rules/delete', ['id' => $rule->id], null);
        $this->assertSame([$rule->id], $this->ruleIds());
    }

    public function testWithThePermissionTheNavItemShowsEveryScreenRendersAndSaveWorks(): void
    {
        // TS-9 step 2.
        $rule = $this->savedRule('garden');
        $group = $this->savedGroup(['care home', 'nursing home']);
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);

        $this->assertSame(['label' => 'Typesense', 'url' => 'typesense', 'icon' => 'magnifying-glass'], $this->navItem($user));

        foreach ($this->screens($rule, $group) as [$route, $params]) {
            $response = $this->dispatch($route, $params, $user, ['method' => 'GET']);
            $this->assertContains($response->getStatusCode(), [200, 302], $route);
        }

        $entry = $this->saveEntry('Garden room');
        $response = $this->dispatch('rules/save', ['collection' => 'content', 'query' => '  hall ', 'matchType' => Rule::MATCH_EXACT, 'pins' => [$entry->id]], $user);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->data['live'] ?? null);
        $saved = $this->plugin->rules->getRules('content');
        $this->assertSame(['garden', 'hall'], array_map(fn(Rule $rule) => $rule->query, $saved), 'trimmed, added last');
        $this->assertSame(Rules::STATE_IN_STEP, $this->plugin->rules->state('content')[Rules::KIND_RULES]['state'], 'live');

        $response = $this->dispatch('synonyms/save', ['collection' => 'content', 'type' => 'oneWay', 'root' => 'care home', 'words' => [['word' => 'nursing home'], ['word' => ' ']]], $user);
        $this->assertSame(200, $response->getStatusCode());
        $groups = $this->plugin->rules->getSynonymGroups('content');
        $this->assertSame('care home', $groups[1]->root);
        $this->assertSame(['nursing home'], $groups[1]->words);
        $this->assertSame(Rules::STATE_IN_STEP, $this->plugin->rules->state('content')[Rules::KIND_SYNONYMS]['state']);
    }

    public function testAnAdminHasThePermissionWithoutBeingGrantedIt(): void
    {
        $admin = $this->user([], admin: true);

        $this->assertNotNull($this->navItem($admin));
        $this->assertSame(200, $this->dispatch('rules/index', ['collection' => 'content'], $admin, ['method' => 'GET'])->getStatusCode());
    }

    public function testTheNavItemSitsBeforeUtilities(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        Craft::$app->getUser()->setIdentity($user);
        $event = new RegisterCpNavItemsEvent(['navItems' => [['url' => 'dashboard'], ['url' => 'utilities'], ['url' => 'settings']]]);
        (new Cp())->trigger(Cp::EVENT_REGISTER_CP_NAV_ITEMS, $event);

        $this->assertSame(['dashboard', 'typesense', 'utilities', 'settings'], array_column($event->navItems, 'url'));
    }

    public function testEveryPathOfTheSectionResolvesToItsScreen(): void
    {
        // §6 Interfaces: `admin/typesense…`, never the plugin handle (which Craft gates on accessPlugin-*).
        $event = new RegisterUrlRulesEvent(['rules' => []]);
        Event::trigger(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $event);
        $urlManager = Craft::$app->getUrlManager();
        $resolve = function(string $path) use ($event, $urlManager): ?array {
            foreach ($event->rules as $pattern => $route) {
                $request = new Request(['pathInfo' => $path]);
                $result = (new UrlRule(['pattern' => $pattern, 'route' => $route]))->parseRequest($urlManager, $request);

                if ($result !== false) {
                    return $result;
                }
            }

            return null;
        };

        $this->assertSame(['typesense-sync/rules/collections', []], $resolve('typesense'));
        $this->assertSame(['typesense-sync/rules/collection', ['collection' => 'content']], $resolve('typesense/content'));
        $this->assertSame(['typesense-sync/rules/index', ['collection' => 'content']], $resolve('typesense/content/rules'));
        $this->assertSame(['typesense-sync/rules/edit', ['collection' => 'content']], $resolve('typesense/content/rules/new'));
        $this->assertSame(['typesense-sync/rules/edit', ['collection' => 'content', 'ruleId' => '7']], $resolve('typesense/content/rules/7'));
        $this->assertSame(['typesense-sync/synonyms/index', ['collection' => 'content']], $resolve('typesense/content/synonyms'));
        $this->assertSame(['typesense-sync/synonyms/edit', ['collection' => 'content']], $resolve('typesense/content/synonyms/new'));
        $this->assertSame(['typesense-sync/synonyms/edit', ['collection' => 'content', 'groupId' => '3']], $resolve('typesense/content/synonyms/3'));
        $this->assertNull($resolve('typesense-sync/content/rules'));
    }

    public function testAPostNeedsCsrfPostAndTheCp(): void
    {
        $rule = $this->savedRule('garden');
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $body = ['id' => $rule->id];

        $this->assertRefused(BadRequestHttpException::class, 'rules/toggle', $body, $user, ['csrf' => false]);
        $this->assertRefused(MethodNotAllowedHttpException::class, 'rules/toggle', $body, $user, ['method' => 'GET']);
        $this->assertRefused(BadRequestHttpException::class, 'rules/toggle', $body, $user, ['cp' => false]);
        $this->assertTrue($this->plugin->rules->getRuleById((int)$rule->id)?->enabled);
    }

    // BR-19, TN-14 -----------------------------------------------------------------------------

    public function testUnconfiguredThereIsNoNavItemTheSectionSaysSoAndWritesAreRefused(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $this->useSettings($this->settings(['host' => '', 'apiKey' => '']));

        $this->assertNull($this->navItem($user));

        foreach ([['rules/collections', []], ['rules/index', ['collection' => 'content']], ['synonyms/edit', ['collection' => 'content']]] as [$route, $params]) {
            $this->assertSame('typesense-sync/_section/unconfigured.twig', $this->template($this->dispatch($route, $params, $user, ['method' => 'GET'])), $route);
        }

        $response = $this->dispatch('synonyms/save', ['collection' => 'content', 'words' => ['a', 'b']], $user);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, (int)SynonymRecord::find()->count(), 'nothing written');
    }

    public function testAnUnreachableClusterNeverBlocksTheScreens(): void
    {
        $rule = $this->savedRule('garden');
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $this->useSettings($this->settings(['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]));

        $response = $this->dispatch('rules/index', ['collection' => 'content'], $user, ['method' => 'GET']);
        $variables = $this->variables($response);

        $this->assertSame([$rule->id], array_map(fn(Rule $rule) => $rule->id, $variables['rules']), 'rows from Craft');
        $this->assertSame(Rules::STATE_UNKNOWN, $variables['state'][Rules::KIND_RULES]['state']);
    }

    // BR-4 -------------------------------------------------------------------------------------

    public function testAFailedPushKeepsTheSaveSaysNotLiveYetAndQueuesARetry(): void
    {
        $entry = $this->saveEntry('Garden room');
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $this->useSettings($this->settings(['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]));

        $response = $this->dispatch('rules/save', ['collection' => 'content', 'query' => 'garden', 'matchType' => Rule::MATCH_EXACT, 'pins' => [$entry->id]], $user);

        $this->assertSame(200, $response->getStatusCode(), 'the save succeeded');
        $this->assertFalse($response->data['live']);
        $this->assertStringStartsWith('Saved, not live yet: ', (string)$response->data['message']);
        $this->assertSame(1, (int)RuleRecord::find()->count());
        $this->assertSame(1, $this->countJobs(PushRules::class));

        // A page post (the form, the table's Status action) gets it as a warning, not a success.
        $response = $this->dispatch('rules/toggle', ['id' => $this->plugin->rules->getRules('content')[0]->id], $user, ['json' => false]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('Saved, not live yet: ', (string)(Craft::$app->getSession()->getFlash('cp-notification-error')[0] ?? ''));
        $this->assertNull(Craft::$app->getSession()->getFlash('cp-notification-success'));
    }

    public function testAnInvalidSaveIsRefusedWithItsErrorsAndWritesNothing(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $outside = $this->saveEntry('Elsewhere', $this->createSection('private'));

        $response = $this->dispatch('rules/save', ['collection' => 'content', 'query' => 'garden', 'matchType' => Rule::MATCH_EXACT, 'pins' => [$outside->id]], $user);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('pins', $response->data['errors'] ?? [], 'BR-8 refusal reaches the form');
        $this->assertSame(0, (int)RuleRecord::find()->count());
    }

    public function testDeleteReorderAndToggleEachPushTheWholeSet(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $a = $this->savedRule('garden');
        $b = $this->savedRule('hall');
        $this->plugin->rules->push('content');

        $this->dispatch('rules/reorder', ['collection' => 'content', 'ids' => json_encode([$b->id, $a->id])], $user);
        $this->assertSame(['hall', 'garden'], $this->setQueries(), 'reordered live');

        $response = $this->dispatch('rules/toggle', ['id' => $b->id], $user);
        $this->assertFalse($response->data['enabled']);
        $this->assertSame(['garden'], $this->setQueries(), 'switched off live');

        $this->dispatch('rules/delete', ['id' => $a->id], $user);
        $this->assertSame([$b->id], $this->ruleIds());
        $this->assertSame([], $this->setQueries(), 'deleted live');

        $group = $this->savedGroup(['care home', 'nursing home']);
        $this->dispatch('synonyms/delete', ['id' => $group->id], $user);
        $this->assertSame(0, (int)SynonymRecord::find()->count());
        $this->assertSame(Rules::STATE_IN_STEP, $this->plugin->rules->state('content')[Rules::KIND_SYNONYMS]['state']);
    }

    public function testPushNowPutsCraftsVersionBack(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $this->savedRule('garden');
        $this->admin->curationSets->upsert($this->prefix . 'content_rules', ['items' => []]);

        $response = $this->dispatch('rules/push', ['collection' => 'content'], $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['garden'], $this->setQueries());
    }

    // TN-10, the section half ------------------------------------------------------------------

    public function testAnUndeclaredCollectionIsNeitherListedNorReachable(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $orphan = $this->savedRule('garden');
        RuleRecord::updateAll(['collection' => 'gone'], ['id' => $orphan->id]);

        foreach ([['rules/index', ['collection' => 'gone']], ['synonyms/index', ['collection' => 'gone']], ['rules/edit', ['collection' => 'gone', 'ruleId' => $orphan->id]]] as [$route, $params]) {
            $this->assertRefused(NotFoundHttpException::class, $route, $params, $user, ['method' => 'GET']);
        }

        $this->assertRefused(NotFoundHttpException::class, 'rules/toggle', ['id' => $orphan->id], $user);
        $this->assertTrue($this->plugin->rules->getRuleById((int)$orphan->id)?->enabled, 'not touched');

        // One declared collection: straight to it.
        $response = $this->dispatch('rules/collections', [], $user, ['method' => 'GET']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('typesense/content/rules', (string)$response->getHeaders()->get('Location'));

        // Several: listed, the undeclared one not among them.
        $this->useSettings($this->settings(['collections' => ['content' => [], 'other' => []]]));
        $variables = $this->variables($this->dispatch('rules/collections', [], $user, ['method' => 'GET']));
        $this->assertSame(['content', 'other'], array_column($variables['collections'], 'handle'));
    }

    // 3.2 Screens ------------------------------------------------------------------------------

    public function testEveryScreenRendersInTheCp(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $hooks = [
            'rules/index' => 'data-ts-empty="rules"',
            'synonyms/index' => 'data-ts-empty="synonyms"',
        ];

        // Empty states first, then every screen with a row in it.
        foreach ($hooks as $route => $hook) {
            $this->assertStringContainsString($hook, $this->render($this->dispatch($route, ['collection' => 'content'], $user, ['method' => 'GET'])));
        }

        $rule = $this->savedRule('garden');
        $group = $this->savedGroup(['care home', 'nursing home']);
        $this->plugin->rules->push('content');
        $expected = [
            'rules/index' => 'data-ts-rules',
            'rules/edit' => 'data-ts-picker="pins"',
            'synonyms/index' => 'data-ts-synonyms',
            'synonyms/edit' => 'data-ts-form="synonyms"',
        ];

        foreach ($this->screens($rule, $group) as [$route, $params]) {
            if (!isset($expected[$route])) {
                continue;
            }

            $html = $this->render($this->dispatch($route, $params, $user, ['method' => 'GET']));
            $this->assertStringContainsString($expected[$route], $html, $route);
            $this->assertStringNotContainsString('data-ts-banner', $html, $route . ': in step, so no banner');
        }

        $this->useSettings($this->settings(['collections' => ['content' => [], 'other' => []]]));
        $html = $this->render($this->dispatch('rules/collections', [], $user, ['method' => 'GET']));
        $this->assertStringContainsString('data-collection="other"', $html);
        $html = $this->render($this->dispatch('rules/index', ['collection' => 'content'], $user, ['method' => 'GET']));
        $this->assertStringContainsString('typesense/other/rules', $html, 'the collection switcher');
    }

    public function testTheRulesTabNamesEachStatusAndAScheduledRuleSaysSo(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $entry = $this->saveEntry('Hall');
        $tomorrow = ['date' => (new DateTime('+1 day'))->format('n/j/Y'), 'time' => '9:00 AM', 'timezone' => 'UTC'];

        // TS-2 step 3: Starts tomorrow reads Scheduled.
        $this->dispatch('rules/save', ['collection' => 'content', 'query' => 'hall', 'matchType' => Rule::MATCH_EXACT, 'pins' => [$entry->id], 'effectiveFrom' => $tomorrow], $user);
        $ended = $this->savedRule('garden');
        $ended->effectiveTo = new DateTime('-1 day');
        $this->assertTrue($this->plugin->rules->saveRule($ended));
        $off = $this->savedRule('pool');
        $this->dispatch('rules/toggle', ['id' => $off->id], $user);

        $rows = $this->variables($this->dispatch('rules/index', ['collection' => 'content'], $user, ['method' => 'GET']))['tableData'];

        $this->assertSame(
            ['hall' => 'Scheduled', 'garden' => 'Ended', 'pool' => 'Off'],
            array_combine(array_column($rows, 'title'), array_column($rows, 'statusLabel')),
        );
        $this->assertStringStartsWith('from ', $rows[0]['dates']);
        $this->assertSame([1, 0], [$rows[0]['pins'], $rows[0]['hides']]);
    }

    public function testTheBannerShowsDriftAndTheNextSaveClearsIt(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $rule = $this->savedRule('garden');
        $this->plugin->rules->push('content');
        // TS-6 step 1: an item changed in the dashboard.
        $this->admin->curationSets->upsert($this->prefix . 'content_rules', ['items' => [
            ['id' => 'rule-0001', 'rule' => ['query' => 'lawn', 'match' => 'exact'], 'excludes' => [['id' => '999']]],
        ]]);

        // Step 2: out of step, 1 item differs, with Push now, on either tab.
        $response = $this->dispatch('synonyms/index', ['collection' => 'content'], $user, ['method' => 'GET']);
        $banner = $this->variables($response)['banner'];
        $this->assertSame([[Rules::KIND_RULES, Rules::STATE_OUT_OF_STEP]], array_map(fn($line) => [$line['kind'], $line['state']], $banner['lines']));
        $this->assertStringContainsString('1 item differs', $banner['lines'][0]['text']);
        $this->assertTrue($banner['push']);
        $html = $this->render($response);
        $this->assertStringContainsString('data-state="out-of-step"', $html);
        $this->assertStringContainsString('data-ts-action="push"', $html);

        // Step 3: any save puts Craft's version back.
        $this->dispatch('rules/save', ['ruleId' => $rule->id, 'query' => 'garden'], $user);
        $this->assertSame([], $this->variables($this->dispatch('rules/index', ['collection' => 'content'], $user, ['method' => 'GET']))['banner']['lines']);
    }

    public function testAnUnreachableClusterShowsOneCantCheckLineAndNoPushNow(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $this->savedRule('garden');
        $this->useSettings($this->settings(['port' => '1', 'connectTimeout' => 1, 'timeout' => 1]));

        // TS-10 step 1: rules listed, "Can't check", nothing to push to.
        $response = $this->dispatch('rules/index', ['collection' => 'content'], $user, ['method' => 'GET']);
        $variables = $this->variables($response);

        $this->assertSame(['garden'], array_column($variables['tableData'], 'title'));
        $this->assertCount(1, $variables['banner']['lines'], 'one line for one failure');
        $this->assertSame(Rules::STATE_UNKNOWN, $variables['banner']['lines'][0]['state']);
        $this->assertStringStartsWith('Can’t check', $variables['banner']['lines'][0]['text']);
        $this->assertFalse($variables['banner']['push']);
        $this->assertStringNotContainsString('data-ts-action="push"', $this->render($response));
    }

    public function testEachStateHasItsBannerLine(): void
    {
        $row = fn(string $state, array $values = []) => $values + ['state' => $state, 'name' => 'x', 'exists' => true, 'linked' => true, 'differs' => 0, 'message' => null];

        $this->assertSame(['lines' => [], 'push' => false], SectionController::banner([
            Rules::KIND_RULES => $row(Rules::STATE_IN_STEP),
            Rules::KIND_SYNONYMS => $row(Rules::STATE_IN_STEP),
        ]));

        $banner = SectionController::banner([
            Rules::KIND_RULES => $row(Rules::STATE_NOT_LIVE, ['linked' => false]),
            Rules::KIND_SYNONYMS => $row(Rules::STATE_OUT_OF_STEP, ['differs' => 2]),
        ]);
        $this->assertSame(
            ['Rules are saved but not live yet (not linked). They go live on the next save, or now with Push now.', 'Synonyms are out of step: 2 items differ from what Craft holds, usually after an edit in the Typesense dashboard. The next save, or Push now, puts Craft’s version back.'],
            array_column($banner['lines'], 'text'),
        );
        $this->assertTrue($banner['push']);

        // Two different failures are a line each.
        $banner = SectionController::banner([
            Rules::KIND_RULES => $row(Rules::STATE_UNKNOWN, ['message' => 'one']),
            Rules::KIND_SYNONYMS => $row(Rules::STATE_UNKNOWN, ['message' => 'two']),
        ]);
        $this->assertSame([Rules::KIND_RULES, Rules::KIND_SYNONYMS], array_column($banner['lines'], 'kind'));
        $this->assertFalse($banner['push']);
    }

    /**
     * TN-2, TN-3, TN-6, TN-16 and BR-10 (TN-7, and one way with no main term) through the forms:
     * each refused, with its error on the field the screen shows, and nothing written.
     */
    public function testEachInvalidFormIsRefusedOnItsField(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $entry = $this->saveEntry('Garden room');
        $this->savedRule('garden');
        $many = array_map(fn(int $i) => (int)$this->saveEntry('Room ' . $i)->id, range(1, 21));
        $date = fn(string $when) => ['date' => (new DateTime($when))->format('n/j/Y'), 'time' => '9:00 AM', 'timezone' => 'UTC'];
        $rule = fn(array $body) => $body + ['collection' => 'content', 'query' => 'lawn', 'matchType' => Rule::MATCH_EXACT, 'pins' => [$entry->id]];

        $cases = [
            'TN-2 case differs' => ['rules/save', $rule(['query' => 'GARDEN']), 'query'],
            'TN-3 21 pins' => ['rules/save', $rule(['pins' => $many]), 'pins'],
            'TN-3 pinned and hidden' => ['rules/save', $rule(['hides' => [$entry->id]]), 'pins'],
            'TN-6 ends before starts' => ['rules/save', $rule(['effectiveFrom' => $date('+2 days'), 'effectiveTo' => $date('+1 day')]), 'effectiveTo'],
            'TN-16 256 characters' => ['rules/save', $rule(['query' => str_repeat('a', 256)]), 'query'],
            'TN-16 only spaces' => ['rules/save', $rule(['query' => '   ']), 'query'],
            'TN-7 one word' => ['synonyms/save', ['collection' => 'content', 'words' => [['word' => 'care home']]], 'words'],
            'TN-7 51 words' => ['synonyms/save', ['collection' => 'content', 'words' => array_map(fn(int $i) => ['word' => 'w' . $i], range(1, 51))], 'words'],
            'one way, no main term' => ['synonyms/save', ['collection' => 'content', 'type' => 'oneWay', 'root' => ' ', 'words' => [['word' => 'a'], ['word' => 'b']]], 'root'],
        ];

        foreach ($cases as $name => [$route, $body, $field]) {
            $response = $this->dispatch($route, $body, $user);
            $this->assertSame(400, $response->getStatusCode(), $name);
            $this->assertArrayHasKey($field, $response->data['errors'] ?? [], $name);
        }

        $this->assertSame(1, (int)RuleRecord::find()->count(), 'nothing written');
        $this->assertSame(0, (int)SynonymRecord::find()->count(), 'nothing written');

        // A failed form comes back with its values and the error under the field.
        $failed = new Rule(['collection' => 'content', 'query' => 'GARDEN', 'pins' => [$entry->id]]);
        $failed->addError('query', 'This collection already has a rule for “GARDEN” with this match.');
        $html = $this->render($this->dispatch('rules/edit', ['collection' => 'content', 'rule' => $failed], $user, ['method' => 'GET']));
        $this->assertStringContainsString('already has a rule for “GARDEN”', $html);
        $this->assertStringContainsString('value="GARDEN"', $html);

        $group = new SynonymGroup(['collection' => 'content', 'words' => ['a', 'b']]);
        // As the controller leaves it; Craft's constructor would typecast '' to null.
        $group->root = '';
        $group->addError('root', 'Enter the main term, or choose All equal.');
        $html = $this->render($this->dispatch('synonyms/edit', ['collection' => 'content', 'group' => $group], $user, ['method' => 'GET']));
        $this->assertStringContainsString('Enter the main term', $html);
        $this->assertStringNotContainsString('id="ts-type-oneWay" class="hidden"', $html, 'the form stays on One way');
    }

    /**
     * BR-8: the pickers offer exactly the sections feeding the collection, and pins posted by
     * several pickers arrive as one list in picker order.
     */
    public function testThePickersOfferOnlyTheCollectionsSources(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $other = $this->createSection('other');
        $a = $this->saveEntry('Garden room');
        $b = $this->saveEntry('Garden hall');

        $pickers = $this->plugin->rules->pickers('content');

        $this->assertSame([Entry::class], array_column($pickers, 'elementType'));
        $this->assertSame(['section:' . $this->news->uid], $pickers[0]['sources'], 'not section:' . $other->uid);
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->id, $pickers[0]['siteId']);

        $response = $this->dispatch('rules/save', ['collection' => 'content', 'query' => 'garden', 'matchType' => Rule::MATCH_EXACT, 'pins' => [['', $b->id, $a->id], ''], 'hides' => ['']], $user);
        $this->assertTrue($response->data['live'] ?? null);
        $rule = $this->plugin->rules->getRules('content')[0];
        $this->assertSame([$b->id, $a->id], $rule->pins);

        $variables = $this->variables($this->dispatch('rules/edit', ['collection' => 'content', 'ruleId' => $rule->id], $user, ['method' => 'GET']));
        $this->assertSame([$b->id, $a->id], array_map(fn(Entry $entry) => $entry->id, $variables['pickers'][0]['pinned']), 'shown in pin order');
    }

    /**
     * The admin table posts reorder as `ids` alone and its Status action as `ids[]` + `enabled`.
     */
    public function testTheAdminTablesPostsWork(): void
    {
        $user = $this->user(['accessCp', TypesenseSync::PERMISSION_RULES]);
        $a = $this->savedRule('garden');
        $b = $this->savedRule('hall');
        $this->plugin->rules->push('content');

        $this->dispatch('rules/reorder', ['ids' => json_encode([(string)$b->id, (string)$a->id]), 'startPosition' => 1], $user);
        $this->assertSame(['hall', 'garden'], $this->setQueries());

        $response = $this->dispatch('rules/toggle', ['ids' => [$a->id, $b->id], 'enabled' => '0'], $user);
        $this->assertFalse($response->data['enabled']);
        $this->assertSame([], $this->setQueries(), 'both off, one push');

        $this->dispatch('rules/toggle', ['ids' => [$a->id], 'enabled' => '1'], $user);
        $this->assertSame(['garden'], $this->setQueries());
    }

    // Helpers ----------------------------------------------------------------------------------

    /**
     * The screen a response would show, rendered as the CP renders it.
     */
    private function render(Response $response): string
    {
        $template = $this->template($response);
        $this->assertNotNull($template);

        return Craft::$app->getView()->renderTemplate($template, $this->variables($response), View::TEMPLATE_MODE_CP);
    }

    /**
     * The GET screens, each with the route params its URL rule gives it.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function screens(Rule $rule, SynonymGroup $group): array
    {
        return [
            ['rules/collections', []],
            ['rules/collection', ['collection' => 'content']],
            ['rules/index', ['collection' => 'content']],
            ['rules/edit', ['collection' => 'content']],
            ['rules/edit', ['collection' => 'content', 'ruleId' => $rule->id]],
            ['synonyms/index', ['collection' => 'content']],
            ['synonyms/edit', ['collection' => 'content']],
            ['synonyms/edit', ['collection' => 'content', 'groupId' => $group->id]],
        ];
    }

    /**
     * A body each action would accept, so a refusal is down to who is asking.
     *
     * @return array<string, mixed>
     */
    private function validBody(string $action, Rule $rule, SynonymGroup $group): array
    {
        return match ($action) {
            'rules/save' => ['ruleId' => $rule->id, 'query' => 'changed'],
            'synonyms/save' => ['groupId' => $group->id, 'words' => ['x', 'y']],
            'rules/reorder', 'synonyms/reorder', 'rules/push' => ['collection' => 'content', 'ids' => '[]'],
            'rules/delete', 'rules/toggle' => ['id' => $rule->id],
            default => ['id' => $group->id],
        };
    }

    /**
     * @param class-string<Throwable> $expected
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    private function assertRefused(string $expected, string $route, array $params, ?User $user, array $options = []): void
    {
        try {
            $response = $this->dispatch($route, $params, $user, $options);
        } catch (Throwable $e) {
            $this->assertInstanceOf($expected, $e, sprintf('%s: %s', $route, $e->getMessage()));

            return;
        }

        $this->fail(sprintf('%s answered %d instead of refusing', $route, $response->getStatusCode()));
    }

    /**
     * Dispatch as Craft would a CP request, with a JSON Accept header. A GET passes `$params` as
     * the URL rule's route params; a POST as the body.
     *
     * @param array<string, mixed> $params
     * @param array{cp?: bool, method?: string, csrf?: bool, json?: bool} $options
     */
    private function dispatch(string $route, array $params, ?User $user, array $options = []): Response
    {
        $request = Craft::$app->getRequest();
        Craft::$app->getUser()->setIdentity($user);
        $method = $options['method'] ?? 'POST';
        $_SERVER['REQUEST_METHOD'] = $method;
        $request->setIsCpRequest($options['cp'] ?? true);
        $accept = ($options['json'] ?? true) ? 'application/json' : 'text/html';
        $request->getHeaders()->set('Accept', $accept);
        // Accept is read once per request object.
        $request->setAcceptableContentTypes([$accept => ['q' => 1]]);
        $request->enableCsrfValidation = true;
        $body = $method === 'POST' ? $params : [];

        if ($options['csrf'] ?? true) {
            $body[$request->csrfParam] = $request->getCsrfToken(true);
        }

        $request->setBodyParams($body);
        Craft::$app->getResponse()->clear();
        Craft::$app->getResponse()->detachBehavior(TemplateResponseBehavior::NAME);
        $response = Craft::$app->runAction('typesense-sync/' . $route, $method === 'POST' ? [] : $params);
        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function template(Response $response): ?string
    {
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return $behavior instanceof TemplateResponseBehavior ? $behavior->template : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(Response $response): array
    {
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        $this->assertInstanceOf(TemplateResponseBehavior::class, $behavior);

        return $behavior->variables;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function navItem(?User $user): ?array
    {
        Craft::$app->getUser()->setIdentity($user);

        return $this->plugin->sectionNavItem();
    }

    /**
     * @param string[] $permissions
     */
    private function user(array $permissions, bool $admin = false): User
    {
        $name = 'u' . bin2hex(random_bytes(4));
        $user = new User(['username' => $name, 'email' => $name . '@example.test', 'admin' => $admin]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false), implode(' ', $user->getFirstErrors()));
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

        return $user;
    }

    private function savedRule(string $query): Rule
    {
        $entry = $this->saveEntry(ucfirst($query) . ' ' . bin2hex(random_bytes(3)));
        $rule = new Rule(['collection' => 'content', 'query' => $query, 'matchType' => Rule::MATCH_EXACT, 'pins' => [$entry->id]]);
        $this->assertTrue($this->plugin->rules->saveRule($rule), implode(' ', $rule->getFirstErrors()));

        return $rule;
    }

    /**
     * @param string[] $words
     */
    private function savedGroup(array $words): SynonymGroup
    {
        $group = new SynonymGroup(['collection' => 'content', 'words' => $words]);
        $this->assertTrue($this->plugin->rules->saveSynonymGroup($group), implode(' ', $group->getFirstErrors()));

        return $group;
    }

    /**
     * @return int[]
     */
    private function ruleIds(): array
    {
        return array_map(fn(Rule $rule) => (int)$rule->id, $this->plugin->rules->getRules('content'));
    }

    /**
     * Both live sets' items, to prove nothing was pushed.
     *
     * @return array<string, mixed>
     */
    private function setItems(): array
    {
        return [
            Rules::KIND_RULES => $this->plugin->rules->liveSet(Rules::KIND_RULES, $this->prefix . 'content_rules'),
            Rules::KIND_SYNONYMS => $this->plugin->rules->liveSet(Rules::KIND_SYNONYMS, $this->prefix . 'content_synonyms'),
        ];
    }

    /**
     * The live curation set's queries, in id order.
     *
     * @return string[]
     */
    private function setQueries(): array
    {
        $items = (array)($this->plugin->rules->liveSet(Rules::KIND_RULES, $this->prefix . 'content_rules')['items'] ?? []);
        usort($items, fn($a, $b) => strcmp((string)$a['id'], (string)$b['id']));

        return array_map(fn($item) => (string)$item['rule']['query'], $items);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values = []): Settings
    {
        return new Settings($values + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['content' => []],
            'sources' => [['handle' => $this->news->handle, 'collection' => 'content', 'formatter' => DocumentFormatter::class]],
        ]);
    }

    private function useSettings(Settings $settings): void
    {
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($settings->isConfigured() ? $this->plugin->client->createClient($settings, 0) : null);
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

    private function saveEntry(string $title, ?Section $section = null): Entry
    {
        $section ??= $this->news;
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));
        // The save queued its own sync; only what an action queues may be counted.
        Db::delete(Table::QUEUE);

        return $entry;
    }

    private function countJobs(?string $class = null): int
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->column();
        $jobs = array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );

        return count(array_filter($jobs, fn($job) => $class === null || $job instanceof $class));
    }
}
