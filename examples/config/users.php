<?php

/**
 * Snippet: a people directory built from Craft users. Add the collection to `collections` and
 * the source to `sources` in your `config/typesense-sync.php`, and copy
 * `examples/formatters/UserFormatter.php` into your site.
 *
 * Declaring a users source is also what makes the plugin follow account changes that save
 * nothing: activating, deactivating, suspending, unsuspending, locking, unlocking and group
 * assignment each queue a sync of that user (BR-7). Which users are listed is the formatter's
 * decision. The example lists active users with a name.
 *
 * There is one users source at most, with no `handle`.
 */

use modules\search\formatters\UserFormatter;

return [
    'collections' => [
        'people' => [
            'defaultSortingField' => 'priority',
            // Users have no publication dates. The base document gives them an always-open
            // window, so the default filter lets every indexed user through.
        ],
    ],
    'sources' => [
        [
            'kind' => 'users',
            'collection' => 'people',
            'formatter' => UserFormatter::class,
        ],
    ],
];
