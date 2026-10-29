<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;

/**
 * A formatter that overrides `documentId()`, so a document's id is not its element's (TN-9).
 */
class PrefixedIdFormatter extends DocumentFormatter
{
    public function documentId(ElementInterface $element): string
    {
        return 'doc-' . $element->id;
    }
}
