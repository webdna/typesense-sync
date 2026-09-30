<?php

namespace webdna\typesensesync\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\typesensesync\TypesenseSync;
use yii\queue\RetryableJobInterface;

/**
 * Remove one document. The collection and document id are captured when the element is deleted,
 * because by the time this runs the element may be gone (BR-6).
 *
 * Retried when Typesense cannot be reached (BR-10).
 *
 * @since 1.0.0
 */
class DeleteElement extends BaseJob implements RetryableJobInterface
{
    /**
     * Attempts in all, the first included.
     */
    public const ATTEMPTS = 3;

    /**
     * Collection handle.
     */
    public ?string $collection = null;

    public ?string $documentId = null;

    public function execute($queue): void
    {
        if ($this->collection === null || $this->documentId === null) {
            return;
        }

        TypesenseSync::getInstance()->sync->deleteDocument($this->documentId, $this->collection);
    }

    public function getTtr(): int
    {
        return 60;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::ATTEMPTS;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense-sync', 'Removing from Typesense');
    }
}
