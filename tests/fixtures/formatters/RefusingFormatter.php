<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;

/**
 * A formatter that keeps everything out of search.
 */
class RefusingFormatter extends DocumentFormatter
{
    public function shouldIndex(ElementInterface $element): bool
    {
        return false;
    }
}
