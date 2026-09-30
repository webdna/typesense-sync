<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * Joins into the `people` collection, by handle, through the context.
 */
class ReferencingFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [['name' => 'authorId', 'type' => 'string', 'reference' => $context->reference('people')]];
    }
}
