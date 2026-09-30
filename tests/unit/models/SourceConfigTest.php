<?php

namespace webdna\typesensesync\tests\unit\models;

use Codeception\Test\Unit;
use webdna\typesensesync\models\SourceConfig;

/**
 * Entry-type overrides applied over a source's defaults.
 */
class SourceConfigTest extends Unit
{
    public function testOverrideReplacesOnlyWhatItSets(): void
    {
        $source = new SourceConfig([
            'handle' => 'news',
            'collection' => 'content',
            'formatter' => 'A',
            'priority' => 50,
            'site' => 'en',
            'entryTypes' => ['event' => ['collection' => 'events', 'formatter' => 'B']],
        ]);

        $event = $source->resolveFor('event');
        $this->assertSame('events', $event->collection);
        $this->assertSame('B', $event->formatter);
        $this->assertSame(50, $event->priority);
        $this->assertSame('en', $event->site);
        $this->assertSame('section:news/event', $event->getDescription());

        $article = $source->resolveFor('article');
        $this->assertSame('content', $article->collection);
        $this->assertSame('A', $article->formatter);
    }

    public function testAnOverrideCanSwitchATypeOffButNotOnInADisabledSource(): void
    {
        $on = new SourceConfig(['handle' => 'news', 'entryTypes' => ['draft' => ['enabled' => false]]]);
        $this->assertTrue($on->resolveFor()->enabled);
        $this->assertFalse($on->resolveFor('draft')->enabled);

        $off = new SourceConfig(['handle' => 'news', 'enabled' => false, 'entryTypes' => ['event' => ['enabled' => true]]]);
        $this->assertFalse($off->resolveFor('event')->enabled);
    }

    public function testResolveAllIsTheDefaultThenEachOverride(): void
    {
        $source = new SourceConfig(['handle' => 'news', 'entryTypes' => ['a' => [], 'b' => []]]);

        $this->assertSame([null, 'a', 'b'], array_map(fn($t) => $t->entryType, $source->resolveAll()));
    }

    public function testUsersDescribeThemselvesWithoutAHandle(): void
    {
        $this->assertSame('users', (new SourceConfig(['kind' => SourceConfig::KIND_USERS]))->getDescription());
    }
}
