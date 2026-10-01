<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * A BaseFormatter whose documents use `type` for a list of their own, so its collection names the
 * document type's field elsewhere with `typeField`.
 */
class KindsFormatter extends BaseFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            ['name' => 'type', 'type' => 'string[]', 'facet' => true, 'optional' => true],
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        return ['type' => ['Collector', 'Dealer']];
    }
}
