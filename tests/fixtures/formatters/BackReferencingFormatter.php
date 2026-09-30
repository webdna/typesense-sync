<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * Joins into the `posts` collection, closing a cycle with ReferencingFormatter.
 */
class BackReferencingFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [['name' => 'postId', 'type' => 'string', 'reference' => $context->reference('posts')]];
    }
}
