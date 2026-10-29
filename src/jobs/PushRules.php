<?php

namespace webdna\typesensesync\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\typesensesync\TypesenseSync;
use yii\queue\RetryableJobInterface;

/**
 * Push one collection's curation and synonym sets after a push from the CP failed (BR-4).
 *
 * The sets are rebuilt from the rows when the job runs, so a job queued before later edits still
 * sends what is stored now, and two of them waiting for one collection do no harm. Retried while
 * Typesense cannot be reached; a retry is picked up once the TTR has passed, so the TTR is the
 * backoff.
 *
 * @since 1.1.0
 */
class PushRules extends BaseJob implements RetryableJobInterface
{
    /**
     * Attempts in all, the first included.
     */
    public const ATTEMPTS = 5;

    /**
     * Seconds before a failed attempt is retried.
     */
    public const TTR = 120;

    /**
     * The collection handle.
     */
    public ?string $collection = null;

    public function execute($queue): void
    {
        if ($this->collection === null || TypesenseSync::getInstance()->targets->getSettings()->getCollectionConfig($this->collection) === null) {
            return;
        }

        TypesenseSync::getInstance()->rules->push($this->collection);
    }

    public function getTtr(): int
    {
        return self::TTR;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::ATTEMPTS;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense-sync', 'Pushing search rules to Typesense');
    }
}
