<?php

namespace webdna\typesensesync;

use craft\base\Plugin;

/**
 * Typesense Sync: keeps declared Craft content in Typesense collections and hands search pages
 * scoped, filtered keys.
 *
 * The plugin owns the machinery (queueing, batching, safe rebuilds, keys); the site owns the
 * meaning (which sources feed which collection, and a formatter per kind of result).
 *
 * @author webdna
 * @since 1.0.0
 */
class TypesenseSync extends Plugin
{
    /**
     * The handle, as Craft knows the plugin and as the translation and log categories are named.
     */
    public const HANDLE = 'typesense-sync';

    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
    }
}
