<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * DocumentFormatter after a developer has added a field to it (TS-4 step 1).
 */
class WiderFormatter extends DocumentFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [...parent::schema($context), ['name' => 'subtitle', 'type' => 'string', 'optional' => true]];
    }
}
