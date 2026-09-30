<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\elements\Entry;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * Example formatter for entries that join to their author in a `people` collection
 * (`examples/config/joins.php`).
 *
 * A search of `content` can then ask for the author's fields in the same request:
 * `include_fields: '$people(title, photo)'`. The author is stored once, in `people`, so renaming
 * a person changes every result that names them without reindexing their entries.
 */
class ArticleFormatter extends ContentFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            [
                'name' => 'authorId',
                'type' => 'string',
                'optional' => true,
                // Asked of the context, never written out: the joined collection's live name
                // differs per environment, and the context resolves it.
                'reference' => $context->reference('people'),
                // Typesense's defaults suit neither side of a join between two indexes that
                // change independently. Without async_reference an entry is rejected while its
                // author is not in `people` (not yet synced, or not listed at all); with
                // cascade_delete left on, removing a person from search deletes every entry
                // they wrote from search too.
                'async_reference' => true,
                'cascade_delete' => false,
            ],
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        $authorId = $element instanceof Entry ? $element->getAuthorId() : null;

        return [
            ...parent::fields($element),
            // The people collection's document id, which is the user's id (BR-11).
            'authorId' => $authorId !== null ? (string)$authorId : null,
        ];
    }
}
