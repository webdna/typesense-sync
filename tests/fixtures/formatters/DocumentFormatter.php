<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;
use webdna\typesensesync\formatters\BaseFormatter;

/**
 * A BaseFormatter whose own fields are whatever the test sets.
 */
class DocumentFormatter extends BaseFormatter
{
    /**
     * @var array<string, mixed>
     */
    public array $extra = [];

    protected function fields(ElementInterface $element): array
    {
        return $this->extra;
    }
}
