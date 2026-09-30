<?php

namespace webdna\typesensesync\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\User;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\UserGroup;
use craft\services\Users;
use ReflectionFunction;
use ReflectionProperty;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\jobs\DeleteElement;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\Support\Examples;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;
use yii\base\Event;

/**
 * The two non-entry sources against the real server, through real saves, real user service
 * calls and the real queue: categories (BR-1) and users, whose status changes write no save
 * (TS-9, BR-7).
 *
 * Each test declares its own category groups and users and writes into its own collection
 * prefix, which is deleted afterwards.
 */
class SourcesTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    /**
     * @var array<string, CategoryGroup>
     */
    private array $groups = [];

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();

        $this->groups['topics'] = $this->createCategoryGroup('topics');
        $this->groups['tags'] = $this->createCategoryGroup('tags');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);

        foreach (['topics', 'people'] as $handle) {
            $applied = $this->plugin->collections->apply($handle);
            $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);
        }

        $this->clearQueue();
    }

    protected function _after(): void
    {
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    // Categories (BR-1) -------------------------------------------------------------------------

    public function testACategoryOfADeclaredGroupFollowsItsLifecycle(): void
    {
        $category = $this->saveCategory('topics', 'Gardening');
        $this->assertSame(1, $this->countJobs(SyncElement::class));
        $this->runQueue();
        $this->assertSame('Gardening', $this->document('topics', $category)['title'] ?? null);

        $category->title = 'Kitchen gardening';
        $this->save($category);
        $this->runQueue();
        $this->assertSame('Kitchen gardening', $this->document('topics', $category)['title'] ?? null);

        $this->assertTrue(Craft::$app->getElements()->deleteElement($category));
        $this->assertSame(1, $this->countJobs(DeleteElement::class));
        $this->runQueue();
        $this->assertNull($this->document('topics', $category));

        $this->assertTrue(Craft::$app->getElements()->restoreElement($category));
        $this->runQueue();
        $this->assertNotNull($this->document('topics', $category));
    }

    public function testACategoryOfAnUndeclaredGroupQueuesNothing(): void
    {
        $this->saveCategory('tags', 'Unlisted');

        $this->assertSame(0, $this->countJobs(SyncElement::class));
    }

    public function testASwitchedOffCategoryGroupQueuesNothing(): void
    {
        $this->useSettings($this->settings(topics: ['enabled' => false]));

        $this->saveCategory('topics', 'Switched off');

        $this->assertSame(0, $this->countJobs(SyncElement::class));
    }

    public function testAReindexWalksTheDeclaredGroupOnly(): void
    {
        $kept = [$this->saveCategory('topics', 'One'), $this->saveCategory('topics', 'Two')];
        $other = $this->saveCategory('tags', 'Elsewhere');
        $this->clearQueue();

        $run = $this->plugin->sync->reindex('topics');

        $this->assertSame(2, $run['indexed']);
        foreach ($kept as $category) {
            $this->assertNotNull($this->document('topics', $category));
        }
        $this->assertNull($this->document('topics', $other));
    }

    // Users: TS-9 (BR-7) ------------------------------------------------------------------------

    public function testAStatusChangeReachesSearchWithoutASave(): void
    {
        // Step 1: declared users with the example formatter, which lists the active only; a
        // reindex (what setup runs) lists them.
        $alice = $this->createActiveUser('alice');
        $bob = $this->createActiveUser('bob');
        $this->clearQueue();
        $this->plugin->sync->reindex('people');
        $this->assertNotNull($this->document('people', $alice));
        $this->assertNotNull($this->document('people', $bob));

        // Step 2: suspended through the users service, which writes the users table only.
        $saves = 0;
        $countSaves = function() use (&$saves) {
            $saves++;
        };
        Event::on(User::class, User::EVENT_AFTER_SAVE, $countSaves);
        Craft::$app->getUsers()->suspendUser($alice);
        $this->assertSame(0, $saves, 'Suspending a user saved the element; this test would prove nothing.');
        $this->assertSame([$alice->id], $this->queuedSyncIds());
        $this->runQueue();
        $this->assertNull($this->document('people', $alice));

        // Step 3.
        Craft::$app->getUsers()->unsuspendUser($alice);
        $this->runQueue();
        $this->assertNotNull($this->document('people', $alice));
        Event::off(User::class, User::EVENT_AFTER_SAVE, $countSaves);

        // Step 4: with the users source gone, the listener is still wired and queues nothing.
        $this->useSettings($this->settings(users: false));
        Craft::$app->getUsers()->suspendUser($bob);
        $this->assertSame([], $this->queuedSyncIds());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusChanges(): array
    {
        return [
            'activate' => ['activate'],
            'deactivate' => ['deactivate'],
            'suspend' => ['suspend'],
            'unsuspend' => ['unsuspend'],
            'lock' => ['lock'],
            'unlock' => ['unlock'],
            'group assignment' => ['assign'],
        ];
    }

    /**
     * @dataProvider statusChanges
     */
    public function testEveryStatusChangeQueuesASyncOfThatUser(string $change): void
    {
        $user = $this->createActiveUser('carol');
        $users = Craft::$app->getUsers();

        // Put the user in the state the change starts from, without leaving a job behind.
        match ($change) {
            'activate' => $users->deactivateUser($user),
            'unsuspend' => $users->suspendUser($user),
            'unlock' => $this->lock($user),
            default => null,
        };
        $this->clearQueue();

        match ($change) {
            'activate' => $users->activateUser($user),
            'deactivate' => $users->deactivateUser($user),
            'suspend' => $users->suspendUser($user),
            'unsuspend' => $users->unsuspendUser($user),
            'lock' => $this->lock($user),
            'unlock' => $users->unlockUser($user),
            'assign' => $users->assignUserToGroups((int)$user->id, [$this->createUserGroup()->id]),
            default => $this->fail("Unknown change $change"),
        };

        $this->assertSame([$user->id], $this->queuedSyncIds());
    }

    /**
     * Counted, not observed: the dedupe flag would fold a doubled handler into one job.
     */
    public function testFollowingAgainNeverDoublesAListener(): void
    {
        $this->plugin->followElementTypes();
        $this->plugin->followElementTypes();

        foreach (TypesenseSync::USER_LIFECYCLE_EVENTS as $name) {
            $this->assertSame(1, $this->pluginListeners(Users::class, $name), $name);
        }

        foreach ([User::class, Category::class] as $type) {
            $this->assertSame(1, $this->pluginListeners($type, User::EVENT_AFTER_SAVE), $type);
        }
    }

    public function testASaveOfAUserQueuesNothingWhileUsersAreUndeclared(): void
    {
        $this->useSettings($this->settings(users: false));

        $this->createActiveUser('erin');

        $this->assertSame([], $this->queuedSyncIds());
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $topics
     */
    private function settings(array $topics = [], bool $users = true): Settings
    {
        $sources = [$topics + [
            'kind' => 'categoryGroup',
            'handle' => $this->groups['topics']->handle,
            'collection' => 'topics',
            'formatter' => DocumentFormatter::class,
        ]];

        if ($users) {
            // The users source exactly as `examples/config/users.php` declares it, with the
            // example UserFormatter, which lists active users with a name.
            $sources[] = Examples::config('users')['sources'][0];
        }

        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['topics' => [], 'people' => Examples::config('users')['collections']['people']],
            'sources' => $sources,
        ]);
    }

    /**
     * Injects settings and follows whatever element types they declare, as a site booting with
     * that config file would.
     */
    private function useSettings(Settings $settings): void
    {
        $this->assertSame([], $settings->getProblems());
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
        $this->plugin->followElementTypes();
    }

    private function createCategoryGroup(string $name): CategoryGroup
    {
        $handle = $name . substr($this->prefix, 2, 8);
        $group = new CategoryGroup(['name' => ucfirst($name) . ' ' . $handle, 'handle' => $handle]);
        $layout = new FieldLayout(['type' => Category::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new TitleField()]])]);
        $group->setFieldLayout($layout);
        $group->setSiteSettings([new CategoryGroup_SiteSettings([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'hasUrls' => true,
            'uriFormat' => $name . '/{slug}',
            'template' => '_category',
        ])]);
        $this->assertTrue(Craft::$app->getCategories()->saveGroup($group), implode(' ', $group->getFirstErrors()));

        return $group;
    }

    private function createUserGroup(): UserGroup
    {
        $handle = 'group' . bin2hex(random_bytes(4));
        $group = new UserGroup(['name' => $handle, 'handle' => $handle]);
        $this->assertTrue(Craft::$app->getUserGroups()->saveGroup($group), implode(' ', $group->getFirstErrors()));

        return $group;
    }

    private function saveCategory(string $group, string $title): Category
    {
        $category = new Category([
            'groupId' => $this->groups[$group]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $this->save($category);

        return $category;
    }

    private function createActiveUser(string $name): User
    {
        $username = $name . substr($this->prefix, 2, 8);
        // A name, because the example formatter never lists a user without one.
        $user = new User(['username' => $username, 'email' => $username . '@example.test', 'fullName' => ucfirst($name) . ' Test']);
        $this->save($user);
        Craft::$app->getUsers()->activateUser($user);
        $this->assertSame(User::STATUS_ACTIVE, $user->getStatus());

        return $user;
    }

    /**
     * Locks a user the only way Craft does: too many invalid logins in the window.
     */
    private function lock(User $user): void
    {
        for ($i = 0; $i < Craft::$app->getConfig()->getGeneral()->maxInvalidLogins; $i++) {
            Craft::$app->getUsers()->handleInvalidLogin($user);
        }

        $this->assertTrue($user->locked);
    }

    private function save(Category|User $element): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($element), implode(' ', $element->getFirstErrors()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(string $collection, Category|User $element): ?array
    {
        try {
            return $this->admin->collections[$this->prefix . $collection]->documents[(string)$element->id]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        }
    }

    /**
     * Class-level handlers for an event that are closures of the plugin.
     */
    private function pluginListeners(string $class, string $name): int
    {
        /** @var array<string, array<string, array<int, array{0: mixed, 1: mixed}>>> $events */
        $events = (new ReflectionProperty(Event::class, '_events'))->getValue();
        $handlers = array_column($events[$name][ltrim($class, '\\')] ?? [], 0);

        return count(array_filter(
            $handlers,
            fn($handler) => $handler instanceof Closure && (new ReflectionFunction($handler))->getClosureThis() === $this->plugin,
        ));
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
    }

    private function clearQueue(): void
    {
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    private function countJobs(string $class): int
    {
        return count(array_filter($this->queuedJobs(), fn(object $job) => $job instanceof $class));
    }

    /**
     * Element ids of the waiting sync jobs.
     *
     * @return array<int, int>
     */
    private function queuedSyncIds(): array
    {
        $jobs = array_filter($this->queuedJobs(), fn(object $job) => $job instanceof SyncElement);

        return array_values(array_map(fn(SyncElement $job) => (int)$job->elementId, $jobs));
    }

    /**
     * @return array<int, object>
     */
    private function queuedJobs(): array
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->orderBy('id')->column();

        return array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );
    }
}
