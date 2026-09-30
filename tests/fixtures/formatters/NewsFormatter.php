<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

class NewsFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'url', 'type' => 'string', 'index' => false],
        ];
    }
}
