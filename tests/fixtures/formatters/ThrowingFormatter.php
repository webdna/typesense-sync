<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use RuntimeException;
use webdna\typesensesync\formatters\SchemaContext;

class ThrowingFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        throw new RuntimeException('schema exploded');
    }
}
