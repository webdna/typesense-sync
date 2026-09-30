<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\elements\Category;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * Example formatter for categories (`examples/config/categories.php`): the base document plus
 * the group and the path of parent titles, so a result can read "Walking › Coastal".
 */
class CategoryFormatter extends BaseFormatter
{
    protected const SUMMARY_FIELDS = ['summary', 'description'];

    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            ['name' => 'summary', 'type' => 'string', 'optional' => true],
            self::facet('group'),
            // Searched, so a query for a parent finds its children too.
            ['name' => 'parents', 'type' => 'string[]', 'optional' => true],
            ['name' => 'level', 'type' => 'int32', 'optional' => true],
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        if (!$element instanceof Category) {
            return [];
        }

        return [
            'summary' => $this->firstPlainText($element, static::SUMMARY_FIELDS),
            'group' => $element->getGroup()->name,
            'parents' => array_values(array_map(
                static fn(ElementInterface $parent) => (string)$parent->title,
                $element->getAncestors()->all(),
            )),
            'level' => $element->level,
        ];
    }
}
