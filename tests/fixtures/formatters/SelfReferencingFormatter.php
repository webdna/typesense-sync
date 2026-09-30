<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * Joins into its own collection.
 */
class SelfReferencingFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [['name' => 'parentId', 'type' => 'string', 'reference' => $context->reference($context->getCollection())]];
    }
}
