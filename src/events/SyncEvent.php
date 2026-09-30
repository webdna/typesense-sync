<?php

namespace webdna\typesensesync\events;

use craft\base\ElementInterface;
use webdna\typesensesync\models\ResolvedTarget;
use yii\base\Event;

/**
 * Raised by `sync` after one element has been synced — its document written or removed. Not
 * raised per element during a reindex.
 *
 * @since 1.0.0
 */
class SyncEvent extends Event
{
    public ElementInterface $element;

    public ResolvedTarget $target;

    /**
     * `Sync::OUTCOME_INDEXED` or `Sync::OUTCOME_REMOVED`.
     */
    public string $outcome;

    /**
     * The document written, or null when it was removed.
     *
     * @var array<string, mixed>|null
     */
    public ?array $document = null;
}
