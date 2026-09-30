<?php

namespace webdna\typesensesync\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\events\DefineMenuItemsEvent;
use craft\events\RegisterElementActionsEvent;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\web\View;
use Throwable;
use Typesense\Client as TypesenseClient;
use webdna\typesensesync\elements\actions\Sync as SyncAction;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\jobs\Recreate;
use webdna\typesensesync\jobs\Reindex;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\WiderFormatter;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use webdna\typesensesync\utilities\Utility;
use yii\base\Event;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * The utility, its CP actions, the element action and the edit-screen menu item, run through
 * Craft's real controller dispatch: CSRF, login, the CP-only and POST checks and the utility's
 * permission (BR-22, BR-23; TN-8, TN-9, TN-10), then what each action does against the server.
 */
class UtilityTest extends Unit
{
    private const ACTIONS = ['sync-element', 'reindex', 'apply', 'recreate'];

    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    private CmsEdition $edition;

    private string $controllerNamespace;

    private Section $news;

    private Section $private;

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        // Craft picks a plugin's controllers by getIsConsoleRequest(), which PHP's CLI makes true
        // even for the test web app; a real CP request resolves to these.
        $this->controllerNamespace = $this->plugin->controllerNamespace;
        $this->plugin->controllerNamespace = 'webdna\typesensesync\controllers';
        // Solo lets every user do everything; permissions only mean something on Pro.
        $this->edition = Craft::$app->edition;
        Craft::$app->edition = CmsEdition::Pro;
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();

        $this->news = $this->createSection('news');
        $this->private = $this->createSection('private');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);
        $this->clearQueue();
    }

    protected function _after(): void
    {
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->edition = $this->edition;
        $this->plugin->controllerNamespace = $this->controllerNamespace;
        Craft::$app->getRequest()->setIsCpRequest(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // TN-9, BR-23 ------------------------------------------------------------------------------

    public function testAnAnonymousRequestToAnyActionIsRefusedAndQueuesNothing(): void
    {
        foreach (self::ACTIONS as $action) {
            $this->assertRefused(ForbiddenHttpException::class, $action, $this->validBody($action), null);
        }

        $this->assertSame(0, $this->countJobs(), 'nothing queued');
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'), 'nothing created');
    }

    public function testAPostWithoutACsrfTokenIsRefusedEvenWithThePermission(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);

        foreach (self::ACTIONS as $action) {
            $this->assertRefused(BadRequestHttpException::class, $action, $this->validBody($action), $user, ['csrf' => false]);
        }

        $this->assertSame(0, $this->countJobs());
    }

    public function testAGetAndASiteRequestAreRefused(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);

        foreach (self::ACTIONS as $action) {
            $this->assertRefused(MethodNotAllowedHttpException::class, $action, $this->validBody($action), $user, ['method' => 'GET']);
            $this->assertRefused(BadRequestHttpException::class, $action, $this->validBody($action), $user, ['cp' => false]);
        }

        $this->assertSame(0, $this->countJobs());
    }

    // TN-8, BR-22 ------------------------------------------------------------------------------

    public function testACpUserWithoutThePermissionGets403FromEveryAction(): void
    {
        $user = $this->user(['accessCp']);

        foreach (self::ACTIONS as $action) {
            $this->assertRefused(ForbiddenHttpException::class, $action, $this->validBody($action), $user);
        }

        $this->assertSame(0, $this->countJobs());
        $this->assertNull($this->plugin->collections->getActiveCollectionName('content'));
    }

    public function testWithoutThePermissionThereIsNoElementActionAndNoMenuItem(): void
    {
        $entry = $this->saveEntry($this->news, 'Hidden control');
        Craft::$app->getUser()->setIdentity($this->user(['accessCp']));

        $this->assertNotContains(SyncAction::class, $this->registeredActions());
        $this->assertSame([], $this->menuItems($entry));
        $this->assertFalse((new SyncAction())->performAction(Entry::find()->id($entry->id)), 'performAction checks again');
    }

    // TN-10, BR-22 -----------------------------------------------------------------------------

    public function testRecreateWithAWrongOrMissingTypedHandleIsRefusedAndChangesNothing(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);
        $this->plugin->collections->apply('content');

        foreach (['', 'Content', 'content ', $this->prefix . 'content', 'other'] as $typed) {
            $response = $this->post('recreate', ['collection' => 'content', 'confirm' => $typed], $user);
            $this->assertSame(400, $response->getStatusCode(), sprintf('typed "%s"', $typed));
            $this->assertStringContainsString('Nothing was changed', (string)json_encode($response->data));
        }

        $this->assertSame(0, $this->countJobs(Recreate::class), 'no recreate queued');
        $this->assertSame($this->prefix . 'content_1', $this->plugin->collections->getActiveCollectionName('content'), 'alias unmoved');
    }

    public function testRecreateWithTheTypedHandleQueuesOneRecreate(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);
        $this->plugin->collections->apply('content');

        $response = $this->post('recreate', ['collection' => 'content', 'confirm' => 'content'], $user);

        $this->assertSame(200, $response->getStatusCode(), (string)json_encode($response->data));
        $this->assertSame(1, $this->countJobs(Recreate::class));
    }

    public function testAnUndeclaredCollectionIsABadRequest(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);

        foreach (['reindex', 'apply', 'recreate'] as $action) {
            $this->assertRefused(BadRequestHttpException::class, $action, ['collection' => 'nope', 'confirm' => 'nope'], $user);
        }
    }

    // What the actions do ----------------------------------------------------------------------

    public function testApplyCreatesTheCollectionThenReindexQueuesAPruningReindex(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);

        $refused = $this->post('reindex', ['collection' => 'content'], $user);
        $this->assertSame(400, $refused->getStatusCode(), 'no reindex into a collection not created yet');

        $applied = $this->post('apply', ['collection' => 'content'], $user);
        $this->assertSame(200, $applied->getStatusCode(), (string)json_encode($applied->data));
        $this->assertSame($this->prefix . 'content_1', $this->plugin->collections->getActiveCollectionName('content'));

        $reindex = $this->post('reindex', ['collection' => 'content', 'prune' => '1'], $user);
        $this->assertSame(200, $reindex->getStatusCode());
        $jobs = $this->jobs(Reindex::class);
        $this->assertCount(1, $jobs);
        $this->assertTrue($jobs[0]->prune);
    }

    public function testActionsRefuseAnUnreachableServerWithTheReason(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);
        $this->useSettings($this->settings(['port' => '1', 'connectTimeout' => 1]));

        foreach (['reindex', 'apply'] as $action) {
            $response = $this->post($action, ['collection' => 'content'], $user);
            $this->assertSame(400, $response->getStatusCode());
            $this->assertStringContainsString('Could not reach', (string)json_encode($response->data));
        }

        $this->assertSame(0, $this->countJobs());
    }

    public function testSyncElementQueuesADeclaredElementAndRefusesAnUndeclaredOne(): void
    {
        $user = $this->user(['accessCp', Utility::PERMISSION]);
        $news = $this->saveEntry($this->news, 'Declared');
        $private = $this->saveEntry($this->private, 'Undeclared');
        $this->clearQueue();

        $this->assertSame(200, $this->post('sync-element', ['elementId' => $news->id], $user)->getStatusCode());
        $this->assertSame(400, $this->post('sync-element', ['elementId' => $private->id], $user)->getStatusCode());

        $this->assertSame(1, $this->countJobs(SyncElement::class));
    }

    public function testTheElementActionQueuesOnlyDeclaredElements(): void
    {
        $news = $this->saveEntry($this->news, 'Selected, declared');
        $private = $this->saveEntry($this->private, 'Selected, undeclared');
        $this->clearQueue();
        Craft::$app->getUser()->setIdentity($this->user(['accessCp', Utility::PERMISSION]));

        $this->assertContains(SyncAction::class, $this->registeredActions());

        $action = new SyncAction();
        $this->assertTrue($action->performAction(Entry::find()->id([$news->id, $private->id])));
        $this->assertSame(1, $this->countJobs(SyncElement::class));
        $this->assertStringContainsString('1 element', (string)$action->getMessage());

        $this->assertFalse((new SyncAction())->performAction(Entry::find()->id($private->id)), 'nothing declared selected');
    }

    public function testTheMenuItemAppearsOnlyForADeclaredPublishedElement(): void
    {
        $news = $this->saveEntry($this->news, 'Menu, declared');
        $private = $this->saveEntry($this->private, 'Menu, undeclared');
        Craft::$app->getUser()->setIdentity($this->user(['accessCp', Utility::PERMISSION]));

        $items = $this->menuItems($news);
        $this->assertCount(1, $items);
        $this->assertSame('typesense-sync/utility/sync-element', $items[0]['action']);
        $this->assertSame($news->id, $items[0]['params']['elementId']);
        $this->assertSame('sync-element', $items[0]['attributes']['data-ts-action']);

        $this->assertSame([], $this->menuItems($private));

        // A provisional draft offers the item for its canonical entry, which is what search holds.
        $draft = Craft::$app->getDrafts()->createDraft($news, (int)Craft::$app->getUser()->getId(), provisional: true);
        $this->assertSame($news->id, $this->menuItems($draft)[0]['params']['elementId'] ?? null);
    }

    // The page ---------------------------------------------------------------------------------

    public function testUnconfiguredTheUtilityShowsOneMessageLinkingToSettings(): void
    {
        $this->useSettings($this->settings(['host' => '', 'apiKey' => '']));

        $this->assertSame('unconfigured', Utility::variables()['state']);
        $html = $this->render();
        $this->assertStringContainsString('settings/plugins/typesense-sync', $html);
        $this->assertStringNotContainsString('data-collection', $html);
    }

    public function testUnreachableTheUtilityNamesTheProblem(): void
    {
        $this->useSettings($this->settings(['port' => '1', 'connectTimeout' => 1]));

        $variables = Utility::variables();

        $this->assertSame('unreachable', $variables['state']);
        $this->assertNotEmpty($variables['connectionProblems']);
        $this->assertStringContainsString('Could not reach', $this->render());
    }

    public function testConnectedWithNothingDeclaredTheUtilitySaysSo(): void
    {
        $this->useSettings($this->settings(['collections' => [], 'sources' => []]));

        $this->assertSame('empty', Utility::variables()['state']);
        $this->assertStringContainsString('No collections declared', $this->render());
    }

    public function testEachCollectionRowShowsItsStateWithTheTestHooksAndNoKey(): void
    {
        Craft::$app->getUser()->setIdentity($this->user(['accessCp', Utility::PERMISSION]));

        $html = $this->render();
        $this->assertStringContainsString('data-collection="content"', $html);
        $this->assertStringContainsString('data-ts-action="apply"', $html, 'not created yet: create it');
        $this->assertStringNotContainsString('data-ts-action="recreate"', $html);

        $this->plugin->collections->apply('content');
        $html = $this->render();
        $this->assertStringContainsString('data-ts-schema="up-to-date"', $html);
        $this->assertStringContainsString('data-ts-action="reindex"', $html);
        $this->assertStringContainsString('data-ts-action="recreate"', $html);
        $this->assertStringNotContainsString('data-ts-action="apply"', $html, 'nothing to apply');
        $this->assertStringContainsString($this->prefix . 'content_1', $html);

        // A formatter gains a field (TS-4 step 1, AC-5).
        $this->useSettings($this->settings(['sources' => [$this->source(WiderFormatter::class)]]));
        $html = $this->render();
        $this->assertStringContainsString('data-ts-schema="out-of-date"', $html);
        $this->assertStringContainsString('Add subtitle', $html);
        $this->assertStringContainsString('data-ts-action="apply"', $html);

        // BR-17: the admin key is nowhere on the page.
        $this->assertStringNotContainsString((string)getenv('TYPESENSE_TEST_API_KEY'), $html);
    }

    public function testAConfigProblemIsListedAndAFailingCollectionDoesNotBreakThePage(): void
    {
        $this->useSettings($this->settings(['sources' => [
            $this->source(DocumentFormatter::class),
            ['handle' => 'x', 'collection' => 'undeclared', 'formatter' => DocumentFormatter::class],
        ]]));

        $variables = Utility::variables();
        $this->assertNotEmpty($variables['problems']);
        $this->assertStringContainsString('Configuration problems', $this->render());
    }

    public function testAProductsSourceWithoutCommerceIsAWarningNotAProblem(): void
    {
        // TS-10 step 1 (BR-5).
        $this->assertFalse(Commerce::isInstalled(), 'this leg runs without Commerce');
        $this->useSettings($this->settings(['sources' => [
            $this->source(DocumentFormatter::class),
            ['kind' => 'productType', 'handle' => 'shoes', 'collection' => 'content', 'formatter' => DocumentFormatter::class],
        ]]));

        $variables = Utility::variables();
        $this->assertSame([], $variables['problems']);
        $this->assertSame('ready', $variables['state']);

        $html = $this->render();
        $this->assertStringContainsString('data-ts-section="warnings"', $html);
        $this->assertStringContainsString('productType:shoes is ignored because Commerce is not installed.', $html);
        $this->assertStringNotContainsString('Configuration problems', $html);
    }

    // Helpers ----------------------------------------------------------------------------------

    /**
     * @param class-string<Throwable> $expected
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    private function assertRefused(string $expected, string $action, array $body, ?User $user, array $options = []): void
    {
        try {
            $response = $this->post($action, $body, $user, $options);
        } catch (Throwable $e) {
            $this->assertInstanceOf($expected, $e, sprintf('%s: %s', $action, $e->getMessage()));

            return;
        }

        $this->fail(sprintf('%s answered %d instead of refusing', $action, $response->getStatusCode()));
    }

    /**
     * Dispatch a utility action as Craft would a CP request, with a JSON Accept header so a
     * refusal is an exception or a 400 rather than a redirect.
     *
     * @param array<string, mixed> $body
     * @param array{cp?: bool, method?: string, csrf?: bool} $options
     */
    private function post(string $action, array $body, ?User $user, array $options = []): Response
    {
        $request = Craft::$app->getRequest();
        Craft::$app->getUser()->setIdentity($user);
        $_SERVER['REQUEST_METHOD'] = $options['method'] ?? 'POST';
        $request->setIsCpRequest($options['cp'] ?? true);
        $request->getHeaders()->set('Accept', 'application/json');
        $request->enableCsrfValidation = true;

        if ($options['csrf'] ?? true) {
            $body[$request->csrfParam] = $request->getCsrfToken(true);
        }

        $request->setBodyParams($body);
        Craft::$app->getResponse()->clear();

        $response = Craft::$app->runAction('typesense-sync/utility/' . $action);
        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * A body each action would accept, so a refusal is down to who is asking and how.
     *
     * @return array<string, mixed>
     */
    private function validBody(string $action): array
    {
        if ($action === 'sync-element') {
            $entry = $this->saveEntry($this->news, 'Body ' . bin2hex(random_bytes(3)));
            // The save queued its own sync; only what the action queues may be counted.
            $this->clearQueue();

            return ['elementId' => $entry->id];
        }

        return $action === 'recreate' ? ['collection' => 'content', 'confirm' => 'content'] : ['collection' => 'content'];
    }

    /**
     * @param string[] $permissions
     */
    private function user(array $permissions): User
    {
        $name = 'u' . bin2hex(random_bytes(4));
        $user = new User(['username' => $name, 'email' => $name . '@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false), implode(' ', $user->getFirstErrors()));
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

        return $user;
    }

    /**
     * @return string[]
     */
    private function registeredActions(): array
    {
        $event = new RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
        Event::trigger(Entry::class, Element::EVENT_REGISTER_ACTIONS, $event);

        return array_map(fn($action) => is_array($action) ? (string)$action['type'] : (is_string($action) ? $action : $action::class), $event->actions);
    }

    /**
     * The plugin's items on the element's ⋯ menu. The event is triggered directly because
     * getActionMenuItems() also asks for Craft's own items, which read web request headers.
     *
     * @return list<array<string, mixed>>
     */
    private function menuItems(Entry $entry): array
    {
        $event = new DefineMenuItemsEvent(['items' => []]);
        $entry->trigger(Element::EVENT_DEFINE_ACTION_MENU_ITEMS, $event);

        return array_values(array_filter($event->items, fn($item) => ($item['action'] ?? null) === 'typesense-sync/utility/sync-element'));
    }

    private function render(): string
    {
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        return Utility::contentHtml();
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
            'sources' => [$this->source(DocumentFormatter::class)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $formatter): array
    {
        return ['handle' => $this->news->handle, 'collection' => 'content', 'formatter' => $formatter];
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

    private function saveEntry(Section $section, string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }

    private function clearQueue(): void
    {
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    /**
     * Waiting jobs, of one class or all.
     *
     * @return list<object>
     */
    private function jobs(?string $class = null): array
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->column();
        $jobs = array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );

        return array_values(array_filter($jobs, fn($job) => $class === null || $job instanceof $class));
    }

    private function countJobs(?string $class = null): int
    {
        return count($this->jobs($class));
    }
}
