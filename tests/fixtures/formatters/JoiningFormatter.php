<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * A BaseFormatter whose documents join into the `people` collection through `authorId`, set by
 * the test to the id of a document there.
 */
class JoiningFormatter extends DocumentFormatter
{
    public static string $authorId = '';

    public function schema(SchemaContext $context): array
    {
        return [...parent::schema($context), ['name' => 'authorId', 'type' => 'string', 'reference' => $context->reference('people')]];
    }

    protected function fields(ElementInterface $element): array
    {
        return ['authorId' => self::$authorId];
    }
}
