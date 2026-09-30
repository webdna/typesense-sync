<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * A formatter that indexes everything as an empty document; fixtures override schema().
 */
abstract class FixtureFormatter implements FormatterInterface
{
    public function shouldIndex(ElementInterface $element): bool
    {
        return true;
    }

    public function format(ElementInterface $element): array
    {
        return [];
    }

    abstract public function schema(SchemaContext $context): array;
}
