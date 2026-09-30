<?php

namespace webdna\typesensesync\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\typesensesync\TypesenseSync;
use yii\queue\RetryableJobInterface;

/**
 * Write or remove one element's document.
 *
 * Everything is re-derived when the job runs (BR-8), so a config change or a later save takes
 * effect on a job already waiting. The collection and document id captured at queue time are
 * used only when the element has gone by then (TN-2).
 *
 * Retried when Typesense cannot be reached (BR-10); a retry is picked up once the job's TTR has
 * passed.
 *
 * @since 1.0.0
 */
class SyncElement extends BaseJob implements RetryableJobInterface
{
    /**
     * Attempts in all, the first included.
     */
    public const ATTEMPTS = 3;

    public ?int $elementId = null;

    public ?int $siteId = null;

    /**
     * Collection handle the element was queued for.
     */
    public ?string $collection = null;

    /**
     * Document id the element was queued with.
     */
    public ?string $documentId = null;

    public function execute($queue): void
    {
        if ($this->elementId === null || $this->siteId === null) {
            return;
        }

        TypesenseSync::getInstance()->sync->syncById($this->elementId, $this->siteId, $this->collection, $this->documentId);
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
        return Craft::t('typesense-sync', 'Syncing to Typesense');
    }
}
