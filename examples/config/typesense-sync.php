<?php

/**
 * Typesense Sync — the base example. Copy to `config/typesense-sync.php`.
 *
 * It declares one collection, `content`, fed by one section, `news`. Change the handle to one of
 * your sections, copy `examples/formatters/ContentFormatter.php` into your site, then run
 *
 *     php craft typesense-sync/setup
 *
 * Nothing is indexed unless it is declared here (BR-1). An entry in any other section is never
 * sent to Typesense, and saving it queues nothing.
 *
 * The connection (host, keys, timeouts, collection prefix) lives in the plugin's settings in the
 * control panel. `collections`, `sources` and `analytics` can only be set in this file, because
 * they name classes. Any connection setting may be overridden here as well, e.g.
 * `'collectionPrefix' => App::env('TYPESENSE_PREFIX')`. An unknown key is reported as a problem
 * by the utility and by `setup`, since Craft would otherwise ignore it without a word.
 *
 * The other files in this folder are snippets. Add their entries to the matching keys below:
 * a collection by its handle, a source to the end of the list.
 */

use modules\search\formatters\ContentFormatter;

return [
    /**
     * The collections, keyed by handle. A collection's live name is the collection prefix plus
     * the handle (`prod_content`), or `name` when set. It is always an alias, pointing at a
     * versioned collection (`prod_content_1`), so a rebuild never takes search down.
     */
    'collections' => [
        'content' => [
            // An explicit, environment-aware name, when one cluster already has names to keep:
            // 'name' => '$TYPESENSE_COLLECTION_CONTENT',

            // Changing this later needs `collections/recreate`, not `collections/apply`.
            'defaultSortingField' => 'priority',

            // Fields added to whatever the formatters declare, e.g. a counter a job writes:
            // 'schema' => [['name' => 'popularity', 'type' => 'int32', 'optional' => true]],
            // 'counters' => ['popularity'],

            // What every search key for this collection is limited to.
            // 'search' => [
            //     'publicationWindow' => true,   // hide pending and expired entries (default)
            //     'excludeFields' => ['keywords'],
            // ],
        ],
    ],

    /**
     * What feeds each collection. `kind` is `section` (the default), `categoryGroup`,
     * `productType` or `users`.
     */
    'sources' => [
        [
            'kind' => 'section',
            'handle' => 'news',
            'collection' => 'content',
            'formatter' => ContentFormatter::class,
            // Lower sorts first when a search sorts by priority.
            'priority' => 100,

            // Optional: switch one entry type off, or route it elsewhere.
            // 'entryTypes' => [
            //     'pressRelease' => ['enabled' => false],
            // ],

            // Optional: switch the whole source per environment.
            // 'enabled' => App::env('SEARCH_NEWS') ?? true,
        ],
    ],
];
