<?php

namespace webdna\typesensesync\tests\fixtures\elements;

use craft\elements\Category;
use craft\models\CategoryGroup;
use craft\models\FieldLayout;

/**
 * A category that names its group without the group existing in the database.
 */
class TestCategory extends Category
{
    public string $groupHandle = 'topics';

    public function getGroup(): CategoryGroup
    {
        return new CategoryGroup(['handle' => $this->groupHandle]);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return null;
    }
}
