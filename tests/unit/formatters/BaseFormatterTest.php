<?php

namespace webdna\typesensesync\tests\unit\formatters;

use Codeception\Test\Unit;
use craft\elements\User;
use DateTime;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;
use webdna\typesensesync\models\ResolvedTarget;
use webdna\typesensesync\tests\fixtures\elements\TestEntry;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\fixtures\formatters\KindsFormatter;

/**
 * BaseFormatter: the document every result shares (BR-11) and what leaves search (BR-8).
 */
class BaseFormatterTest extends Unit
{
    public function testTheBaseDocument(): void
    {
        $formatter = (new DocumentFormatter())->setTarget(new ResolvedTarget(['priority' => 7]));
        $entry = new TestEntry([
            'id' => 12,
            'title' => 'Hello',
            'testUrl' => 'https://test.craftcms.test/news/hello?x=1',
            'postDate' => new DateTime('@1700000000'),
        ]);

        $this->assertSame([
            'id' => '12',
            'title' => 'Hello',
            'type' => 'Article',
            'url' => '/news/hello?x=1',
            'priority' => 7,
            'postDate' => 1700000000,
            'expiryDate' => BaseFormatter::FAR_FUTURE,
        ], $formatter->format($entry));
    }

    public function testFarFutureIsTheSpecSentinel(): void
    {
        $this->assertSame(253402300799, BaseFormatter::FAR_FUTURE);
        $this->assertSame('9999-12-31T23:59:59+00:00', (new DateTime('@' . BaseFormatter::FAR_FUTURE))->format('c'));
    }

    public function testAnEntryWithNoPostDateIsNeverPublished(): void
    {
        $document = (new DocumentFormatter())->format(new TestEntry(['id' => 1, 'title' => 'Pending']));

        $this->assertSame(BaseFormatter::FAR_FUTURE, $document['postDate']);
    }

    public function testAnElementWithNoPublicationIsAlwaysPublished(): void
    {
        $document = (new DocumentFormatter())->format(new User(['id' => 3, 'email' => 'a@example.com']));

        $this->assertSame(0, $document['postDate']);
        $this->assertSame(BaseFormatter::FAR_FUTURE, $document['expiryDate']);
        $this->assertSame('User', $document['type']);
    }

    public function testEmptyValuesAreDroppedButFalseAndZeroKept(): void
    {
        $formatter = new DocumentFormatter();
        $formatter->extra = ['summary' => null, 'tags' => [], 'body' => '', 'featured' => false, 'rating' => 0];

        $document = $formatter->format(new TestEntry(['id' => 1, 'title' => 'x']));

        $this->assertArrayNotHasKey('summary', $document);
        $this->assertArrayNotHasKey('tags', $document);
        $this->assertArrayNotHasKey('body', $document);
        $this->assertArrayNotHasKey('url', $document);
        $this->assertArrayNotHasKey('keywords', $document);
        $this->assertFalse($document['featured']);
        $this->assertSame(0, $document['rating']);
    }

    public function testASubclassFieldReplacesABaseField(): void
    {
        $formatter = new DocumentFormatter();
        $formatter->extra = ['type' => 'Guide'];

        $this->assertSame('Guide', $formatter->format(new TestEntry(['id' => 1, 'title' => 'x']))['type']);
    }

    public function testAnUntitledElementStillHasATitleAndPriorityDefaults(): void
    {
        $document = (new DocumentFormatter())->format(new TestEntry(['id' => 44, 'title' => null]));

        $this->assertSame('#44', $document['title']);
        $this->assertSame(100, $document['priority']);
    }

    public function testTheDocumentIdIsTheElementId(): void
    {
        $this->assertSame('44', (new DocumentFormatter())->documentId(new TestEntry(['id' => 44])));
    }

    public function testDisabledElementsLeaveSearch(): void
    {
        $formatter = new DocumentFormatter();

        $this->assertTrue($formatter->shouldIndex(new TestEntry(['id' => 1, 'enabled' => true])));
        $this->assertFalse($formatter->shouldIndex(new TestEntry(['id' => 1, 'enabled' => false])));
    }

    public function testTheSchemaDeclaresEveryRequiredBaseField(): void
    {
        $schema = (new DocumentFormatter())->schema(new SchemaContext('content', ['content' => 'content']));
        $byName = array_column($schema, null, 'name');

        $this->assertSame(['title', 'type', 'url', 'priority', 'postDate', 'expiryDate', 'keywords'], array_keys($byName));
        $this->assertSame(['name' => 'type', 'type' => 'string', 'facet' => true], $byName['type']);
        $this->assertSame('int32', $byName['priority']['type']);
        $this->assertArrayNotHasKey('optional', $byName['postDate']);
        $this->assertArrayNotHasKey('optional', $byName['expiryDate']);

        // Every field the base document always writes is required, so none may be optional.
        foreach (['title', 'type', 'priority', 'postDate', 'expiryDate'] as $required) {
            $this->assertArrayNotHasKey('optional', $byName[$required], $required);
        }
    }

    public function testTheTypeIsWrittenWhereTheTargetsCollectionNamesIt(): void
    {
        $formatter = (new DocumentFormatter())->setTarget(new ResolvedTarget(['typeField' => 'marketplace']));
        $document = $formatter->format(new TestEntry(['id' => 5, 'title' => 'x']));

        $this->assertSame('Article', $document['marketplace']);
        $this->assertArrayNotHasKey('type', $document);
    }

    public function testTheSchemaDeclaresTheTypeWhereTheContextNamesIt(): void
    {
        $schema = (new DocumentFormatter())->schema(new SchemaContext('people', [], 'marketplace'));
        $byName = array_column($schema, null, 'name');

        $this->assertSame(['name' => 'marketplace', 'type' => 'string', 'facet' => true], $byName['marketplace']);
        $this->assertArrayNotHasKey('type', $byName);
        $this->assertSame('type', (new SchemaContext('content', []))->getTypeField(), 'the default');
    }

    public function testAFormatterKeepsItsOwnTypeBesideARenamedTypeField(): void
    {
        $formatter = (new KindsFormatter())->setTarget(new ResolvedTarget(['typeField' => 'marketplace']));
        $document = $formatter->format(new TestEntry(['id' => 5, 'title' => 'x']));
        $schema = array_column($formatter->schema(new SchemaContext('people', [], 'marketplace')), null, 'name');

        $this->assertSame('Article', $document['marketplace']);
        $this->assertSame(['Collector', 'Dealer'], $document['type']);
        $this->assertSame('string', $schema['marketplace']['type']);
        $this->assertSame('string[]', $schema['type']['type']);
    }
}
