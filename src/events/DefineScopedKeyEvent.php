<?php

namespace webdna\typesensesync\events;

use craft\events\CancelableEvent;
use webdna\typesensesync\models\CollectionConfig;

/**
 * Raised by `search` while it builds a scoped key, before the key is signed.
 *
 * A handler can only narrow what the key reaches: every entry in `$filters` is ANDed onto the
 * collection's default filter (the publication window) and the caller's filter, and every field
 * in `$excludeFields` is added to those the collection's config already excludes. Neither the
 * default filter nor the config's excluded fields can be removed from here (BR-18).
 *
 * Setting `$isValid` to false refuses the key: none is made, and `scopedKey()`, `searchConfig()`
 * and `scopedKeyParams()` return null, so the page shows search as unavailable. A handler that
 * cannot build the filter it must add refuses rather than letting a wider key through.
 *
 * @since 1.0.0
 */
class DefineScopedKeyEvent extends CancelableEvent
{
    /**
     * The collection the key is for.
     */
    public CollectionConfig $collection;

    /**
     * The parameters the caller passed (`filter`, `ttl`, and any other embedded search parameter).
     *
     * @var array<string, mixed>
     */
    public array $params = [];

    /**
     * The time the key is being made at, as `search` sees it (Unix seconds).
     */
    public int $now = 0;

    /**
     * Typesense filter clauses to AND in, e.g. `status:=active`.
     *
     * @var string[]
     */
    public array $filters = [];

    /**
     * Fields to exclude on top of the collection's `search.excludeFields`.
     *
     * @var string[]
     */
    public array $excludeFields = [];
}
