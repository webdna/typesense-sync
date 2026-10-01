<?php

namespace webdna\typesensesync\tests\unit\models;

use Codeception\Test\Unit;
use webdna\typesensesync\formatters\SchemaContext;
use webdna\typesensesync\models\CollectionConfig;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use yii\base\InvalidConfigException;

/**
 * A collection's live name (BR-3) and its versioned physical names.
 */
class CollectionConfigTest extends Unit
{
    protected function _after(): void
    {
        unset($_SERVER['TS_TEST_COLLECTION']);
    }

    public function testLiveNameIsPrefixPlusHandleByDefault(): void
    {
        $collection = new CollectionConfig(['handle' => 'content', 'prefix' => 'staging_']);

        $this->assertSame('staging_content', $collection->getName());
    }

    public function testExplicitNameIgnoresThePrefixAndResolvesEnvReferences(): void
    {
        $_SERVER['TS_TEST_COLLECTION'] = 'live_CONTENT';
        $collection = new CollectionConfig(['handle' => 'content', 'prefix' => 'staging_', 'name' => '$TS_TEST_COLLECTION']);

        $this->assertTrue($collection->hasName());
        $this->assertSame('live_CONTENT', $collection->getName());
    }

    public function testExplicitNameThatResolvesToNothingIsNotANameAndThrows(): void
    {
        $collection = new CollectionConfig(['handle' => 'content', 'name' => '$TS_TEST_COLLECTION']);

        $this->assertFalse($collection->hasName());
        $this->expectException(InvalidConfigException::class);
        $collection->getName();
    }

    public function testVersionedNamesRoundTrip(): void
    {
        $collection = new CollectionConfig(['handle' => 'content']);

        $this->assertSame('content_3', $collection->getVersionedName(3));
        $this->assertSame(3, $collection->getVersionFromName('content_3'));
        $this->assertNull($collection->getVersionFromName('content'));
        $this->assertNull($collection->getVersionFromName('content_x'));
        $this->assertNull($collection->getVersionFromName('other_content_3'));
    }

    public function testTheBaseFieldsAreEveryFieldTheBaseDocumentWritesButTheType(): void
    {
        $schema = (new DocumentFormatter())->schema(new SchemaContext('content', []));
        $written = ['id', ...array_column($schema, 'name')];

        $this->assertEqualsCanonicalizing(
            array_values(array_diff($written, [CollectionConfig::DEFAULT_TYPE_FIELD])),
            CollectionConfig::BASE_FIELDS,
        );
        $this->assertSame('type', (new CollectionConfig())->typeField);
    }
}
