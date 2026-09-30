<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\helpers\Console;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\errors\SyncException;
use yii\console\ExitCode;

/**
 * Search analytics: the declared rules, what they have recorded, and the browser's events key.
 *
 * @since 1.0.0
 */
class AnalyticsController extends Controller
{
    public $defaultAction = 'status';

    /**
     * @var bool Print what would be sent, and send nothing.
     */
    public bool $dryRun = false;

    /**
     * @var int Rows to show for each rule in a report.
     */
    public int $limit = 20;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'apply', 'remove' => ['dryRun'],
            'report' => ['limit'],
            default => [],
        });
    }

    /**
     * Show each declared analytics rule and whether the server has it as declared.
     */
    public function actionStatus(): int
    {
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->declared()) {
            return ExitCode::OK;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $diff = self::plugin()->analytics->diff();
        } catch (SyncException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($diff['rules'] as $row) {
            $this->stdout("\n{$row['handle']} ", Console::BOLD);
            $this->stdout("({$row['name']})\n");
            $this->stdout('  ' . Craft::t('typesense-sync', 'type') . ":        {$row['type']}\n");
            $this->stdout('  ' . Craft::t('typesense-sync', 'destination') . ": {$row['destination']}" . ($row['destinationExists'] ? '' : ' (' . Craft::t('typesense-sync', 'missing') . ')') . "\n");

            match ($row['state']) {
                'ok' => $this->stdout('  ' . Craft::t('typesense-sync', 'up to date') . "\n", Console::FG_GREEN),
                'missing' => $this->stdout('  ' . Craft::t('typesense-sync', 'not on the server; run `craft typesense-sync/analytics/apply`') . "\n", Console::FG_YELLOW),
                default => $this->stdout('  ' . Craft::t('typesense-sync', 'differs from the config; run `craft typesense-sync/analytics/apply`') . "\n", Console::FG_YELLOW),
            };
        }

        if ($diff['unmanaged'] !== []) {
            $this->stdout("\n" . Craft::t('typesense-sync', 'Rules on this server not declared here (left alone): {names}', [
                'names' => implode(', ', $diff['unmanaged']),
            ]) . "\n", Console::FG_GREY);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Create or update the declared analytics rules and their destination collections.
     *
     * Changes only what differs, so it is safe to run on every deploy. Run collections/apply
     * first: a counter needs its field in the live collection.
     */
    public function actionApply(): int
    {
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->declared()) {
            return ExitCode::OK;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return self::exit($this->applyRules($this->dryRun));
    }

    /**
     * The most frequent searches, the searches that found nothing, and the most-counted documents.
     */
    public function actionReport(): int
    {
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->declared()) {
            return ExitCode::OK;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $analytics = self::plugin()->analytics;

        try {
            foreach ($analytics->getRules() as $handle => $rule) {
                $this->stdout("\n$handle ", Console::BOLD);
                $this->stdout("({$rule->type})\n");

                $rows = $rule->isCounter()
                    ? array_map(fn(array $row) => ['count' => $row['count'], 'label' => $row['id'] . (isset($row['document']['title']) ? '  ' . $row['document']['title'] : '')], $analytics->topCounted($handle, $this->limit))
                    : array_map(fn(array $row) => ['count' => $row['count'], 'label' => $row['q']], $analytics->report($handle, $this->limit));

                if ($rows === []) {
                    $this->stdout('  ' . Craft::t('typesense-sync', '(nothing recorded yet)') . "\n", Console::FG_GREY);
                }

                foreach ($rows as $row) {
                    $this->stdout(sprintf("  %-6d %s\n", $row['count'], $row['label']));
                }
            }
        } catch (SyncException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Create an API key that can post analytics events and nothing else, for the browser.
     *
     * Typesense shows a key's value only once, so it is printed here to be copied into the
     * environment. Running this again makes another key; delete the old one on the server when
     * rotating.
     */
    public function actionCreateEventsKey(): int
    {
        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $key = self::plugin()->analytics->createEventsKey();
        } catch (SyncException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n" . Craft::t('typesense-sync', 'Created key {id}, allowing {actions}.', [
            'id' => $key['id'],
            'actions' => implode(', ', $key['actions']),
        ]) . "\n");
        $this->stdout("\n" . Craft::t('typesense-sync', 'Add it to the environment now; Typesense will not show it again:') . "\n\n", Console::FG_YELLOW);
        $this->stdout('TYPESENSE_EVENTS_KEY="' . $key['value'] . "\"\n\n", Console::BOLD);
        $this->stdout(Craft::t('typesense-sync', "Then set `'eventsKey' => '\$TYPESENSE_EVENTS_KEY'` under `analytics` in config/typesense-sync.php.") . "\n");

        return ExitCode::OK;
    }

    /**
     * Delete the analytics rules declared here from the server, and no others.
     *
     * Their destination collections and the counts on documents are kept; apply restores the
     * rules.
     */
    public function actionRemove(): int
    {
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->declared()) {
            return ExitCode::OK;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            foreach (self::plugin()->analytics->remove($this->dryRun) as $result) {
                $this->stdout('  ' . $result['message'] . "\n", $result['removed'] ? Console::FG_GREEN : Console::FG_GREY);
            }
        } catch (SyncException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Whether any rule is declared and enabled; says so when none is. Not a failure: an
     * environment may switch analytics off.
     */
    private function declared(): bool
    {
        if (self::plugin()->analytics->isEnabled()) {
            return true;
        }

        $this->stdout(Craft::t('typesense-sync', 'No analytics rules are declared or enabled in config/typesense-sync.php; nothing to do.') . "\n", Console::FG_GREY);

        return false;
    }
}
