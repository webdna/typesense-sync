<?php

namespace webdna\typesensesync\errors;

use yii\base\Exception;

/**
 * Typesense could not be written to or read from. A queue job that throws it is retried (BR-10);
 * the message names the collection and the document ids involved.
 *
 * @since 1.0.0
 */
class SyncException extends Exception
{
    public function getName(): string
    {
        return 'Typesense sync failed';
    }
}
