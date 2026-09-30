<?php

namespace webdna\typesensesync\tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use webdna\typesensesync\events\RegisterElementTypesEvent;
use webdna\typesensesync\events\ResolveTargetEvent;
use webdna\typesensesync\models\ResolvedTarget;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Targets;
use webdna\typesensesync\tests\fixtures\elements\TestCategory;
use webdna\typesensesync\tests\fixtures\elements\TestEntry;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NewsFormatter;
use webdna\typesensesync\TypesenseSync;

/**
 * Targets: nothing is indexed unless declared (BR-1), and element events queue nothing for
 * drafts, revisions, resaves, propagation or nested entries (BR-6); its two events (BR-27).
 */
class TargetsTest extends Unit
{
    // Resolution (BR-1) -------------------------------------------------------------------------

    public function testAnEntryInADeclaredSectionResolvesToItsSource(): void
    {
        $target = $this->targets()->resolveTargetFor($this->entry());

        $this->assertNotNull($target);
        $this->assertSame('content', $target->collection);
        $this->assertSame(DocumentFormatter::class, $target->formatter);
        $this->assertSame('article', $target->entryType);
    }

    public function testAnEntryInAnUndeclaredSectionResolvesToNothing(): void
    {
        $this->assertNull($this->targets()->resolveTargetFor($this->entry(['sectionHandle' => 'jobs'])));
    }

    public function testAnEntryTypeOverrideAppliesAndCanSwitchATypeOff(): void
    {
        $targets = $this->targets();

        $this->assertNull($targets->resolveTargetFor($this->entry(['typeHandle' => 'page'])));

        $event = $targets->resolveTargetFor($this->entry(['typeHandle' => 'event']));
        $this->assertNotNull($event);
        $this->assertSame(5, $event->priority);
        $this->assertSame(NewsFormatter::class, $event->formatter);
    }

    public function testADisabledSourceResolvesToNothing(): void
    {
        $settings = $this->settings();
        $settings->sources[0]['enabled'] = false;

        $this->assertNull($this->targets($settings)->resolveTargetFor($this->entry()));
    }

    public function testASourceWithAnInvalidTargetResolvesToNothing(): void
    {
        $settings = $this->settings();
        $settings->sources[0]['collection'] = 'nowhere';

        $this->assertNull($this->targets($settings)->resolveTargetFor($this->entry()));
    }

    public function testANestedEntryResolvesToNothing(): void
    {
        $this->assertNull($this->targets()->resolveTargetFor($this->entry(['sectionHandle' => null])));
    }

    public function testCategoriesAndUsersResolveOnlyWhenDeclared(): void
    {
        $category = new TestCategory(['id' => 7]);
        $user = new User(['id' => 8]);

        $this->assertNull($this->targets()->resolveTargetFor($category));
        $this->assertNull($this->targets()->resolveTargetFor($user));

        $settings = $this->settings();
        $settings->sources[] = ['kind' => 'categoryGroup', 'handle' => 'topics', 'collection' => 'content', 'formatter' => DocumentFormatter::class];
        $settings->sources[] = ['kind' => 'users', 'collection' => 'content', 'formatter' => DocumentFormatter::class];
        $targets = $this->targets($settings);

        $this->assertSame('categoryGroup:topics', $targets->resolveTargetFor($category)?->getDescription());
        $this->assertSame('users', $targets->resolveTargetFor($user)?->getDescription());
        $this->assertNull($targets->resolveTargetFor(new TestCategory(['id' => 9, 'groupHandle' => 'tags'])));
    }

    // Syncability (BR-6) ------------------------------------------------------------------------

    public function testAnOrdinarySavedEntryIsSyncable(): void
    {
        $this->assertTrue($this->targets()->isSyncable($this->entry()));
        $this->assertTrue($this->targets()->shouldQueue($this->entry()));
    }

    public function testDraftsRevisionsResavesPropagationAndUnsavedElementsAreNot(): void
    {
        $targets = $this->targets();

        $this->assertFalse($targets->isSyncable($this->entry(['id' => null])));
        $this->assertFalse($targets->isSyncable($this->entry(['draftId' => 3])));
        $this->assertFalse($targets->isSyncable($this->entry(['revisionId' => 4])));
        $this->assertFalse($targets->isSyncable($this->entry(['resaving' => true])));
        $this->assertFalse($targets->isSyncable($this->entry(['propagating' => true])));
        $this->assertFalse($targets->shouldQueue($this->entry(['draftId' => 3])));
    }

    public function testAnUndeclaredEntryQueuesNothing(): void
    {
        $this->assertFalse($this->targets()->shouldQueue($this->entry(['sectionHandle' => 'jobs'])));
    }

    // EVENT_RESOLVE_TARGET (BR-27) ----------------------------------------------------------------

    public function testAHandlerCanKeepADeclaredElementOutOfSearch(): void
    {
        $targets = $this->targets();
        $targets->on(Targets::EVENT_RESOLVE_TARGET, static function(ResolveTargetEvent $event) {
            $event->target = null;
        });

        $this->assertNull($targets->resolveTargetFor($this->entry()));
    }

    public function testAHandlerCanGiveAnUndeclaredElementADeclaredTarget(): void
    {
        $targets = $this->targets();
        $targets->on(Targets::EVENT_RESOLVE_TARGET, static function(ResolveTargetEvent $event) {
            if ($event->target === null) {
                $event->target = new ResolvedTarget(['collection' => 'content', 'formatter' => DocumentFormatter::class]);
            }
        });

        $this->assertSame('content', $targets->resolveTargetFor($this->entry(['sectionHandle' => 'jobs']))?->collection);
    }

    public function testAHandlerCannotRouteIntoAnUndeclaredCollection(): void
    {
        $targets = $this->targets();
        $targets->on(Targets::EVENT_RESOLVE_TARGET, static function(ResolveTargetEvent $event) {
            if ($event->target !== null) {
                $event->target->collection = 'nowhere';
            }
        });

        $this->assertNull($targets->resolveTargetFor($this->entry()));
    }

    // Element types, formatters and sites -------------------------------------------------------

    public function testElementTypesFollowTheDeclaredSourceKinds(): void
    {
        $this->assertSame([Entry::class], $this->targets()->getElementTypes());

        $settings = $this->settings();
        $settings->sources[] = ['kind' => 'categoryGroup', 'handle' => 'topics', 'collection' => 'content', 'formatter' => DocumentFormatter::class];
        $settings->sources[] = ['kind' => 'users', 'collection' => 'content', 'formatter' => DocumentFormatter::class];

        $this->assertSame([Entry::class, Category::class, User::class], $this->targets($settings)->getElementTypes());
    }

    public function testAHandlerCanRegisterAnotherElementType(): void
    {
        $targets = $this->targets();
        $targets->on(Targets::EVENT_REGISTER_ELEMENT_TYPES, static function(RegisterElementTypesEvent $event) {
            $event->types[] = TestCategory::class;
            $event->types[] = Entry::class;
        });

        $this->assertSame([Entry::class, TestCategory::class], $targets->getElementTypes());
    }

    public function testABaseFormatterIsToldItsTarget(): void
    {
        $targets = $this->targets();
        $target = $targets->resolveTargetFor($this->entry());
        $this->assertNotNull($target);

        $formatter = $targets->formatterFor($target);

        $this->assertInstanceOf(DocumentFormatter::class, $formatter);
        $this->assertSame($target, $formatter->getTarget());
    }

    public function testATargetIndexesThePrimarySiteUnlessItNamesAnother(): void
    {
        $targets = $this->targets();

        $this->assertTrue($targets->siteFor(new ResolvedTarget())?->primary);
        $this->assertNull($targets->siteFor(new ResolvedTarget(['site' => 'nowhere'])));
    }

    public function testThePluginRegistersTheService(): void
    {
        $this->assertInstanceOf(Targets::class, TypesenseSync::getInstance()->targets);
    }

    // -------------------------------------------------------------------------------------------

    private function settings(): Settings
    {
        return new Settings([
            'collections' => ['content' => []],
            'sources' => [
                [
                    'handle' => 'news',
                    'collection' => 'content',
                    'formatter' => DocumentFormatter::class,
                    'entryTypes' => [
                        'page' => ['enabled' => false],
                        'event' => ['priority' => 5, 'formatter' => NewsFormatter::class],
                    ],
                ],
            ],
        ]);
    }

    private function targets(?Settings $settings = null): Targets
    {
        $targets = new Targets();
        $targets->setSettings($settings ?? $this->settings());

        return $targets;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entry(array $config = []): TestEntry
    {
        return new TestEntry(array_merge(['id' => 12, 'title' => 'Hello'], $config));
    }
}
