<?php

namespace webdna\typesensesync\tests\fixtures\elements;

use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;

/**
 * An entry that names its section and type without either existing in the database. A null
 * section handle makes it a nested entry.
 */
class TestEntry extends Entry
{
    public ?string $sectionHandle = 'news';

    public string $typeHandle = 'article';

    public string $typeName = 'Article';

    public ?string $testUrl = null;

    public function getSection(): ?Section
    {
        return $this->sectionHandle !== null ? new Section(['handle' => $this->sectionHandle]) : null;
    }

    public function getType(): EntryType
    {
        return new EntryType(['handle' => $this->typeHandle, 'name' => $this->typeName]);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return null;
    }

    public function getUrl(): ?string
    {
        return $this->testUrl;
    }
}
