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

    // Helpers ----------------------------------------------------------------------------------

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
     * @param array{cp?: bool, method?: string, csrf?: bool} $options
     */
    private function dispatch(string $route, array $params, ?User $user, array $options = []): Response
    {
        $request = Craft::$app->getRequest();
        Craft::$app->getUser()->setIdentity($user);
        $method = $options['method'] ?? 'POST';
        $_SERVER['REQUEST_METHOD'] = $method;
        $request->setIsCpRequest($options['cp'] ?? true);
        $request->getHeaders()->set('Accept', 'application/json');
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
