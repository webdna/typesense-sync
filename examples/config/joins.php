<?php

/**
 * A complete config in which content joins to its authors. Copy it over
 * `config/typesense-sync.php`, or take the parts you need, and copy
 * `examples/formatters/ArticleFormatter.php`, `ContentFormatter.php` and `UserFormatter.php`
 * into your site.
 *
 * `ArticleFormatter` declares `authorId` as a reference into `people`. A search of `content` can
 * then return each author's fields in the same request:
 *
 *     include_fields: '$people(title, photo)'
 *
 * Typesense binds a reference to the versioned collection behind an alias, not to the alias.
 * `collections/recreate --collection=people` therefore rebuilds `content` in the same run, and
 * moves both aliases only once both are built, so the join keeps working throughout (BR-15).
 * Collections referencing each other in a cycle are refused.
 */

use modules\search\formatters\ArticleFormatter;
use modules\search\formatters\UserFormatter;

return [
    'collections' => [
        // Declared first: `setup` creates collections in this order, and a reference names a
        // collection that must already be there.
        'people' => [
            'defaultSortingField' => 'priority',
        ],
        'content' => [
            'defaultSortingField' => 'priority',
        ],
    ],
    'sources' => [
        [
            'kind' => 'users',
            'collection' => 'people',
            'formatter' => UserFormatter::class,
        ],
        [
            'kind' => 'section',
            'handle' => 'news',
            'collection' => 'content',
            'formatter' => ArticleFormatter::class,
        ],
    ],
];
