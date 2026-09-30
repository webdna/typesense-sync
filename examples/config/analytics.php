<?php

/**
 * Snippet: search analytics for the `content` collection. Add the `analytics` key to your
 * `config/typesense-sync.php`, then run
 *
 *     php craft typesense-sync/analytics/create-events-key   # once; prints the key to put in .env
 *     php craft typesense-sync/analytics/apply
 *     php craft typesense-sync/analytics/report --limit=20
 *
 * The server must have analytics switched on. A self-hosted Typesense is started with
 * `--enable-search-analytics=true --analytics-dir=…`; Typesense Cloud switches it on per cluster.
 * Otherwise every rule is refused.
 *
 * Each rule's name on the server is the collection's live name plus `_<handle>`. `remove` deletes
 * only the rules declared here, never one made by hand.
 */

return [
    'analytics' => [
        // Switch analytics off on an environment without touching the rules:
        // 'enabled' => App::env('SEARCH_ANALYTICS') ?? true,

        // An events-only key, needed by counter rules: the page posts view events with it.
        // It can do nothing else, and must never be the admin key.
        'eventsKey' => '$TYPESENSE_EVENTS_KEY',

        // Queries never worth recording (the defaults: a match-all search and an empty one).
        // 'ignoreQueries' => ['*', ''],

        'rules' => [
            // The most searched-for queries, kept in a collection of their own.
            'popular' => [
                'type' => 'popular_queries',
                'collection' => 'content',
                'limit' => 1000,
            ],
            // Queries that found nothing: what visitors expect and the site does not have.
            'noResults' => [
                'type' => 'nohits_queries',
                'collection' => 'content',
            ],
            // A view counter written into each document. The field is added to the schema, and
            // its values survive every reindex (BR-14). `examples/alpine/view-counter.js` posts
            // the events; sort a search by `popularity:desc` to use it.
            'views' => [
                'type' => 'counter',
                'collection' => 'content',
                'counterField' => 'popularity',
                'eventType' => 'click',
                'weight' => 1,
            ],
        ],
    ],
];
