<?php

namespace webdna\typesensesync\events;

use craft\base\ElementInterface;
use craft\events\CancelableEvent;
use webdna\typesensesync\models\ResolvedTarget;

/**
 * Raised by `sync` before an element's document is written, whether by a single sync or a
 * reindex.
 *
 * A handler may change `$document`, which is what is written. Setting `$isValid` to false keeps
 * the element out of search: a single sync then deletes any document it already has, exactly as
 * when the formatter's `shouldIndex()` says no, and a reindex skips it (and a prune removes it).
 *
 * @since 1.0.0
 */
class IndexDocumentEvent extends CancelableEvent
{
    public ElementInterface $element;

    public ResolvedTarget $target;

    /**
     * @var array<string, mixed>
     */
    public array $document = [];
}
