<?php

/**
 * Snippet: a category group in its own collection. Add the collection and the source to your
 * `config/typesense-sync.php`, and copy `examples/formatters/CategoryFormatter.php` into your site.
 *
 * Categories can share a collection with entries instead: point the source's `collection` at
 * `content`. The formatters' fields are then merged into one schema, and a field two formatters
 * both declare must be declared the same way.
 */

use modules\search\formatters\CategoryFormatter;

return [
    'collections' => [
        'topics' => [
            'defaultSortingField' => 'priority',
        ],
    ],
    'sources' => [
        [
            'kind' => 'categoryGroup',
            'handle' => 'topics',
            'collection' => 'topics',
            'formatter' => CategoryFormatter::class,
        ],
    ],
];
