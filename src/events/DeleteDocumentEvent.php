<?php

namespace webdna\typesensesync\events;

use yii\base\Event;

/**
 * Raised by `sync` after a document has been deleted from a collection, or found already absent.
 *
 * @since 1.0.0
 */
class DeleteDocumentEvent extends Event
{
    /**
     * Collection handle.
     */
    public string $collection;

    public string $documentId;
}
