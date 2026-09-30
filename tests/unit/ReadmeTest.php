<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use ReflectionClass;
use ReflectionProperty;
use webdna\typesensesync\models\AnalyticsRule;
use webdna\typesensesync\models\CollectionConfig;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SourceConfig;
use webdna\typesensesync\services\Search;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\services\Targets;

/**
 * The README stays in step with the code on the two lists a developer relies on: every config key
 * the plugin accepts (Craft ignores an unknown one silently, so the README is where a developer
 * learns the valid ones — TN-14), and every extension point (BR-27, TS-11 step 4).
 */
class ReadmeTest extends Unit
{
    private string $readme;

    protected function _before(): void
    {
        $this->readme = (string)file_get_contents(dirname(__DIR__, 2) . '/README.md');
    }

    public function testEveryConfigKeyIsDocumented(): void
    {
        $settings = array_map(
            static fn(ReflectionProperty $p) => $p->getName(),
            array_filter(
                (new ReflectionClass(Settings::class))->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn(ReflectionProperty $p) => !$p->isStatic() && $p->getDeclaringClass()->getName() === Settings::class,
            ),
        );

        $keys = [
            ...$settings,
            ...CollectionConfig::KEYS,
            ...CollectionConfig::SEARCH_KEYS,
            ...SourceConfig::KEYS,
            ...SourceConfig::OVERRIDE_KEYS,
            ...SourceConfig::KINDS,
            ...Settings::ANALYTICS_KEYS,
            ...AnalyticsRule::KEYS,
            ...AnalyticsRule::TYPES,
        ];
        $this->assertContains('batchSize', $keys, 'the reflection found the settings');

        $missing = array_values(array_filter(
            array_unique($keys),
            fn(string $key) => !str_contains($this->readme, '`' . $key . '`') && !str_contains($this->readme, '`search.' . $key . '`'),
        ));

        $this->assertSame([], $missing, 'Document these in the README configuration reference');
    }

    public function testEveryExtensionPointIsDocumented(): void
    {
        // Named through ::class so a renamed service or constant fails here, not only in the README.
        $points = [
            [Sync::class, 'EVENT_BEFORE_INDEX_DOCUMENT'],
            [Sync::class, 'EVENT_AFTER_SYNC'],
            [Sync::class, 'EVENT_AFTER_DELETE_DOCUMENT'],
            [Targets::class, 'EVENT_RESOLVE_TARGET'],
            [Targets::class, 'EVENT_REGISTER_ELEMENT_TYPES'],
            [Search::class, 'EVENT_DEFINE_SCOPED_KEY'],
        ];

        foreach ($points as [$class, $constant]) {
            $this->assertTrue(defined($class . '::' . $constant), $class . '::' . $constant);
            $short = substr($class, (int)strrpos($class, '\\') + 1);
            $this->assertStringContainsString('### `' . $short . '::' . $constant . '`', $this->readme);
        }

        $this->assertStringContainsString('->sync->queueElement(', $this->readme);
    }
}
