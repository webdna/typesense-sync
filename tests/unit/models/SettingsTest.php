<?php

namespace webdna\typesensesync\tests\unit\models;

use Codeception\Test\Unit;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SourceConfig;
use webdna\typesensesync\tests\fixtures\formatters\BackReferencingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ConflictingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\EventsFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NewsFormatter;
use webdna\typesensesync\tests\fixtures\formatters\NotAFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ReferencingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\SelfReferencingFormatter;
use webdna\typesensesync\tests\fixtures\formatters\ThrowingFormatter;

/**
 * Settings: the connection (BR-2), config parsing and target resolution (BR-1), and the one
 * validation routine (BR-4, BR-5, TN-3, TN-4, TN-14).
 */
class SettingsTest extends Unit
{
    protected function _after(): void
    {
        unset($_SERVER['TS_TEST_HOST'], $_SERVER['TS_TEST_KEY']);
    }

    // Connection ------------------------------------------------------------------------------

    public function testDefaultsMatchTheSpec(): void
    {
        $settings = new Settings();

        $this->assertSame(2, $settings->connectTimeout);
        $this->assertSame(5, $settings->timeout);
        $this->assertSame(3, $settings->numRetries);
        $this->assertSame(1024, $settings->queuePriority);
        $this->assertSame(100, $settings->batchSize);
        $this->assertSame(443, $settings->getPort());
        $this->assertFalse($settings->isConfigured());
    }

    public function testEnvReferencesAreStoredUnresolvedAndReadResolved(): void
    {
        $_SERVER['TS_TEST_HOST'] = 'search.example.com';
        $_SERVER['TS_TEST_KEY'] = 'admin-secret-1234';
        $settings = new Settings(['host' => '$TS_TEST_HOST', 'apiKey' => '$TS_TEST_KEY']);

        $this->assertSame('$TS_TEST_HOST', $settings->toArray()['host']);
        $this->assertSame('search.example.com', $settings->getHost());
        $this->assertSame('admin-secret-1234', $settings->getApiKey());
        $this->assertTrue($settings->isConfigured());
    }

    public function testAdminKeyIsMaskedToItsLastFour(): void
    {
        $this->assertSame('••••1234', (new Settings(['apiKey' => 'admin-secret-1234']))->getMaskedApiKey());
        $this->assertSame('', (new Settings())->getMaskedApiKey());
    }

    public function testFileOnlyDeclarationsNeverSerialise(): void
    {
        $settings = new Settings(['collections' => ['content' => []], 'sources' => [[]], 'analytics' => ['x' => 1]]);
        $saved = $settings->toArray(['host', 'collections', 'sources', 'analytics']);

        $this->assertArrayHasKey('host', $saved);
        foreach (Settings::FILE_ONLY as $key) {
            $this->assertArrayNotHasKey($key, $saved);
        }
    }

    public function testConnectionRulesRejectABadProtocolAndPort(): void
    {
        $settings = new Settings(['protocol' => 'ftp', 'port' => '0', 'batchSize' => 0]);

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('protocol', $settings->getErrors());
        $this->assertArrayHasKey('port', $settings->getErrors());
        $this->assertArrayHasKey('batchSize', $settings->getErrors());
        $this->assertTrue((new Settings(['host' => '', 'apiKey' => '']))->validate(), 'unconfigured settings still save');
    }

    // Parsing and resolution ------------------------------------------------------------------

    public function testAValidConfigHasNoProblems(): void
    {
        $settings = $this->settings();

        $this->assertSame([], $settings->getProblems([]));
        $this->assertSame([], $settings->getWarnings());
    }

    public function testCollectionsTakeThePrefixAndTheirSearchOptions(): void
    {
        $settings = $this->settings(['collectionPrefix' => 'dev_'], [
            'content' => ['counters' => ['views'], 'search' => ['publicationWindow' => false, 'excludeFields' => ['email']]],
        ]);
        $content = $settings->getCollectionConfig('content');

        $this->assertNotNull($content);
        $this->assertSame('dev_content', $content->getName());
        $this->assertSame(['views'], $content->counters);
        $this->assertFalse($content->publicationWindow);
        $this->assertSame(['email'], $content->excludeFields);
    }

    public function testOnlyDeclaredEnabledSourcesResolve(): void
    {
        $settings = $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class,
                'entryTypes' => ['draft' => ['enabled' => false]], ],
            ['handle' => 'archive', 'enabled' => false, 'collection' => 'content', 'formatter' => NewsFormatter::class],
        ]);

        $this->assertTrue($settings->resolveTarget(SourceConfig::KIND_SECTION, 'news', 'article')?->isIndexable());
        $this->assertFalse($settings->resolveTarget(SourceConfig::KIND_SECTION, 'news', 'draft')?->isIndexable());
        $this->assertFalse($settings->resolveTarget(SourceConfig::KIND_SECTION, 'archive')?->isIndexable());
        $this->assertNull($settings->resolveTarget(SourceConfig::KIND_SECTION, 'private'));
        $this->assertNull($settings->resolveTarget(SourceConfig::KIND_CATEGORY_GROUP, 'news'));
        $this->assertCount(1, $settings->getIndexableTargets());
        $this->assertCount(1, $settings->getTargetsForCollection('content'));
    }

    public function testATargetWithABrokenFormatterOrCollectionIndexesNothing(): void
    {
        // TN-3: listed as a problem, and saves queue nothing for that source.
        $settings = $this->settings([], null, [
            ['handle' => 'a', 'collection' => 'content', 'formatter' => 'No\\Such\\Formatter'],
            ['handle' => 'b', 'collection' => 'content', 'formatter' => NotAFormatter::class],
            ['handle' => 'c', 'collection' => 'nowhere', 'formatter' => NewsFormatter::class],
        ]);

        foreach (['a', 'b', 'c'] as $handle) {
            $target = $settings->resolveTarget(SourceConfig::KIND_SECTION, $handle);
            $this->assertNotNull($target);
            $this->assertFalse($target->isIndexable(), $handle);
        }
        $this->assertSame([], $settings->getIndexableTargets());
    }

    public function testUsersResolveWithoutAHandle(): void
    {
        $settings = $this->settings([], ['people' => []], [
            ['kind' => 'users', 'collection' => 'people', 'formatter' => NewsFormatter::class],
        ]);

        $this->assertTrue($settings->resolveTarget(SourceConfig::KIND_USERS)?->isIndexable());
    }

    // Problems (BR-4) -------------------------------------------------------------------------

    public function testSourceNamingAnUndeclaredCollection(): void
    {
        $this->assertProblem('routes to undeclared collection "nowhere"', $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'nowhere', 'formatter' => NewsFormatter::class],
        ]));
    }

    public function testFormatterMissingOrNotAFormatter(): void
    {
        $settings = $this->settings([], null, [
            ['handle' => 'a', 'collection' => 'content', 'formatter' => 'No\\Such\\Formatter'],
            ['handle' => 'b', 'collection' => 'content', 'formatter' => NotAFormatter::class],
            ['handle' => 'c', 'collection' => 'content'],
        ]);

        $this->assertProblem('section:a declares formatter No\\Such\\Formatter, which does not exist', $settings);
        $this->assertProblem('section:b declares formatter ' . NotAFormatter::class . ', which does not implement', $settings);
        $this->assertProblem('section:c declares no formatter', $settings);
    }

    public function testAnOverrideIsValidatedAsItsOwnTarget(): void
    {
        $this->assertProblem('section:news/event routes to undeclared collection "events"', $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class,
                'entryTypes' => ['event' => ['collection' => 'events']], ],
        ]));
    }

    public function testADisabledSourceIsNotAProblem(): void
    {
        $this->assertSame([], $this->settings([], null, [
            ['handle' => 'news', 'enabled' => false, 'collection' => 'nowhere'],
        ])->getProblems([]));
    }

    public function testTwoFormattersDeclaringOneFieldDifferently(): void
    {
        $settings = $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class],
            ['handle' => 'blog', 'collection' => 'content', 'formatter' => ConflictingFormatter::class],
        ]);

        $this->assertProblem('declare field "title" differently', $settings);
    }

    public function testStatingTypesenseDefaultsIsNotAConflict(): void
    {
        $settings = $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class],
            ['handle' => 'events', 'collection' => 'content', 'formatter' => EventsFormatter::class],
        ]);

        $this->assertSame([], $settings->getProblems([]));
    }

    public function testAFormatterWhoseSchemaThrowsIsAProblemNotACrash(): void
    {
        $this->assertProblem('could not give its schema: schema exploded', $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => ThrowingFormatter::class],
        ]));
    }

    public function testTwoCollectionsResolvingToOneLiveName(): void
    {
        // TN-4
        $settings = $this->settings(['collectionPrefix' => 'dev_'], [
            'content' => [],
            'other' => ['name' => 'dev_content'],
        ]);

        $this->assertProblem('Collections "content", "other" all resolve to the live name "dev_content"', $settings);
    }

    public function testAnExplicitNameThatResolvesToNothing(): void
    {
        $this->assertProblem('Collection "content" is named "$TS_TEST_UNSET", which resolves to nothing', $this->settings([], [
            'content' => ['name' => '$TS_TEST_UNSET'],
        ]));
    }

    public function testAReferenceIntoAnUndeclaredCollection(): void
    {
        $this->assertProblem('field "authorId" references "people.id", which is not a declared collection', $this->settings([], [
            'posts' => [],
        ], [
            ['handle' => 'posts', 'collection' => 'posts', 'formatter' => ReferencingFormatter::class],
        ]));
    }

    public function testAReferenceFollowsTheLiveName(): void
    {
        $settings = $this->settings(['collectionPrefix' => 'dev_'], ['posts' => [], 'people' => []], [
            ['handle' => 'posts', 'collection' => 'posts', 'formatter' => ReferencingFormatter::class],
            ['kind' => 'users', 'collection' => 'people', 'formatter' => NewsFormatter::class],
        ]);

        $this->assertSame([], $settings->getProblems([]));
    }

    public function testAReferenceInConfigSchemaExtrasIsCheckedToo(): void
    {
        $this->assertProblem('field "tagId" references "tags.id"', $this->settings([], [
            'content' => ['schema' => [['name' => 'tagId', 'type' => 'string', 'reference' => 'tags.id']]],
        ]));
    }

    public function testAReferenceCycle(): void
    {
        $settings = $this->settings([], ['posts' => [], 'people' => []], [
            ['handle' => 'posts', 'collection' => 'posts', 'formatter' => ReferencingFormatter::class],
            ['kind' => 'users', 'collection' => 'people', 'formatter' => BackReferencingFormatter::class],
        ]);

        $this->assertProblem('posts → people → posts', $settings);
        $this->assertCount(1, $settings->getProblems([]), 'one cycle is reported once');
    }

    public function testASelfReferenceIsACycle(): void
    {
        $this->assertProblem('content → content', $this->settings([], null, [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => SelfReferencingFormatter::class],
        ]));
    }

    // Declaration shape and unknown keys (TN-14) ----------------------------------------------

    public function testUnknownKeysInTheFileAreNamed(): void
    {
        // Craft drops these without a word, so the plugin must name them.
        $settings = $this->settings();

        $this->assertContains(
            '`config/typesense-sync.php` sets an unknown key "colections".',
            $settings->getProblems(['host' => 'x', 'colections' => []]),
        );
    }

    public function testAFileOnlyKeyThatIsNotAnArray(): void
    {
        $this->assertContains(
            '`config/typesense-sync.php` sets "sources" to something other than an array.',
            $this->settings()->getProblems(['sources' => 'news']),
        );
    }

    public function testUnknownKeysInCollectionsSourcesAndOverrides(): void
    {
        $settings = $this->settings([], ['content' => ['counter' => ['views'], 'search' => ['exclude' => []]]], [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class, 'formater' => 'x',
                'entryTypes' => ['event' => ['colection' => 'x']], ],
        ]);

        $this->assertProblem('Collection "content" sets an unknown key "counter"', $settings);
        $this->assertProblem('Collection "content" sets an unknown search key "exclude"', $settings);
        $this->assertProblem('Source 1 sets an unknown key "formater"', $settings);
        $this->assertProblem('section:news/event sets an unknown key "colection"', $settings);
    }

    public function testMalformedSources(): void
    {
        $settings = $this->settings([], null, [
            'news',
            ['kind' => 'entries', 'handle' => 'news'],
            ['collection' => 'content'],
            ['kind' => 'categoryGroup', 'handle' => 'topics', 'collection' => 'content', 'formatter' => NewsFormatter::class,
                'entryTypes' => ['x' => []], ],
        ]);

        $this->assertProblem('Source 1 is not an array', $settings);
        $this->assertProblem('Source 2 has unknown kind "entries"', $settings);
        $this->assertProblem('Source 3 (section) has no handle', $settings);
        $this->assertProblem('categoryGroup:topics sets entryTypes, which only a section has', $settings);
    }

    public function testADuplicateSourceIsAProblemAndTheFirstWins(): void
    {
        $settings = $this->settings([], ['content' => [], 'other' => []], [
            ['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class],
            ['handle' => 'news', 'collection' => 'other', 'formatter' => NewsFormatter::class],
        ]);

        $this->assertProblem('section:news is declared more than once', $settings);
        $this->assertSame('content', $settings->resolveTarget(SourceConfig::KIND_SECTION, 'news')?->collection);
    }

    public function testAnUnknownSite(): void
    {
        $this->assertProblem('names site "nowhere", which does not exist', $this->settings([], null, [
            ['handle' => 'news', 'site' => 'nowhere', 'collection' => 'content', 'formatter' => NewsFormatter::class],
        ]));
    }

    // Commerce (BR-5) -------------------------------------------------------------------------

    public function testProductsWithoutCommerceWarnAndAreIgnored(): void
    {
        $settings = $this->settings([], null, [
            ['kind' => 'productType', 'handle' => 'shoes', 'collection' => 'nowhere'],
        ]);

        $this->assertSame([], $settings->getProblems([]), 'a warning, not an error');
        $this->assertSame(['productType:shoes is ignored because Commerce is not installed.'], $settings->getWarnings());
        $this->assertNull($settings->resolveTarget(SourceConfig::KIND_PRODUCT_TYPE, 'shoes'));
    }

    public function testProductsWithCommerceAreValidatedAndResolve(): void
    {
        $config = [
            'collections' => ['content' => []],
            'sources' => [['kind' => 'productType', 'handle' => 'shoes', 'collection' => 'content', 'formatter' => NewsFormatter::class]],
        ];

        $settings = new class($config) extends Settings {
            protected function isCommerceInstalled(): bool
            {
                return true;
            }
        };

        $this->assertSame([], $settings->getWarnings());
        $this->assertTrue($settings->resolveTarget(SourceConfig::KIND_PRODUCT_TYPE, 'shoes')?->isIndexable());
    }

    // Helpers ---------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed>|null $collections
     * @param array<int|string, mixed>|null $sources
     */
    private function settings(array $attributes = [], ?array $collections = null, ?array $sources = null): Settings
    {
        return new Settings($attributes + [
            'collections' => $collections ?? ['content' => []],
            'sources' => $sources ?? [['handle' => 'news', 'collection' => 'content', 'formatter' => NewsFormatter::class]],
        ]);
    }

    private function assertProblem(string $needle, Settings $settings): void
    {
        $problems = $settings->getProblems([]);

        foreach ($problems as $problem) {
            if (str_contains($problem, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(sprintf("No problem contains \"%s\". Problems:\n%s", $needle, implode("\n", $problems) ?: '(none)'));
    }
}
