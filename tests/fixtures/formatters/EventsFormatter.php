<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use webdna\typesensesync\formatters\SchemaContext;

/**
 * Declares `title` as NewsFormatter does, spelling out Typesense's defaults.
 */
class EventsFormatter extends FixtureFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [
            ['type' => 'string', 'name' => 'title', 'facet' => false, 'optional' => false],
            ['name' => 'startDate', 'type' => 'int64'],
        ];
    }
}
