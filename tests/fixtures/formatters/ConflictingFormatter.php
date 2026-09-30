<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * Declares `title` differently from NewsFormatter.
 */
class ConflictingFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [['name' => 'title', 'type' => 'string', 'facet' => true]];
    }
}
