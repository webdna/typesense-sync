<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use InvalidArgumentException;
use Throwable;
use webdna\typesensesync\console\Controller;
use webdna\typesensesync\errors\SyncException;
use webdna\typesensesync\services\Rules;
use yii\console\ExitCode;

/**
 * Moves a collection's rules and synonyms between environments, adopts a set made by hand in
 * Typesense, and prunes rows kept for a collection no longer declared.
 *
 * @since 1.1.0
 */
class RulesController extends Controller
{
    /**
     * @var string|null Where export writes the file. Without it, the export is printed.
     */
    public ?string $file = null;

    /**
     * @var string|null The curation set adopt copies from.
     */
    public ?string $curationSet = null;

    /**
     * @var string|null The synonym set adopt copies from.
     */
    public ?string $synonymSet = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'export' => ['file'],
            'import', 'prune' => ['confirm'],
            'adopt' => ['curationSet', 'synonymSet'],
            default => [],
        });
    }

    /**
     * Write one collection's rules and synonyms as JSON, with each entry named so another
     * environment can find it.
     *
     * @param string $handle The collection handle.
     */
    public function actionExport(string $handle): int
    {
        // BR-16.
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->checkDeclared($handle)) {
            return ExitCode::USAGE;
        }

        $export = self::plugin()->rulesTransfer->export($handle);
        $json = Json::encode($export['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $this->printLeftOut($export['leftOut']);

        if ($this->file === null) {
            $this->stdout($json);

            return ExitCode::OK;
        }

        try {
            FileHelper::writeToFile($this->file, $json);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Craft::t('typesense-sync', 'Exported {rules, plural, =1{1 rule} other{# rules}} and {synonyms, plural, =1{1 synonym group} other{# synonym groups}} from {collection} to {file}.', [
            'rules' => count($export['data']['rules']),
            'synonyms' => count($export['data']['synonyms']),
            'collection' => $handle,
            'file' => $this->file,
        ]) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Replace a collection's rules and synonyms with an export's, then make them live.
     *
     * The file names its collection; that handle must be typed to confirm, or given with
     * --confirm. Entries that cannot be found here are left out and listed.
     *
     * @param string $file The export file.
     */
    public function actionImport(string $file): int
    {
        // BR-17.
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        $transfer = self::plugin()->rulesTransfer;
        $json = is_file($file) ? @file_get_contents($file) : false;

        if ($json === false) {
            $this->stderr(Craft::t('typesense-sync', 'Cannot read {file}.', ['file' => $file]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $data = $transfer->read($json);
        } catch (InvalidArgumentException $e) {
            $this->stderr(Craft::t('typesense-sync', 'Nothing was imported: {reason}', ['reason' => $e->getMessage()]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $handle = $data['collection'];

        if (!$this->checkDeclared($handle) || !$this->confirmByHandle($handle, Craft::t('typesense-sync', 'This replaces every rule and synonym group on {collection}.', ['collection' => $handle]))) {
            return ExitCode::USAGE;
        }

        try {
            $result = $transfer->import($data);
        } catch (InvalidArgumentException $e) {
            $this->stderr(Craft::t('typesense-sync', 'Nothing was imported: {reason}', ['reason' => $e->getMessage()]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $this->stdout(Craft::t('typesense-sync', 'Imported {rules, plural, =1{1 rule} other{# rules}} and {synonyms, plural, =1{1 synonym group} other{# synonym groups}} into {collection}.', [
            'rules' => $result['rules'],
            'synonyms' => $result['synonyms'],
            'collection' => $handle,
        ]) . "\n", Console::FG_GREEN);
        $this->printLeftOut($result['leftOut']);

        // BR-4: the rows are saved whatever happens to the push.
        $notLive = self::plugin()->rules->pushOrQueue($handle);

        if ($notLive !== null) {
            $this->stderr(Craft::t('typesense-sync', 'Saved, not live yet: {reason}', ['reason' => $notLive]) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Craft::t('typesense-sync', 'Rules and synonyms are live.') . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Copy a curation or synonym set made by hand in Typesense into a collection's rules or
     * synonyms, make them live, and stop the collection using the original. The original set is
     * kept. Items using options the CP cannot show are skipped and listed.
     *
     * @param string $handle The collection handle.
     */
    public function actionAdopt(string $handle): int
    {
        // BR-18.
        if (!$this->checkConfig()) {
            return ExitCode::CONFIG;
        }

        if (!$this->checkDeclared($handle)) {
            return ExitCode::USAGE;
        }

        if (($this->curationSet === null) === ($this->synonymSet === null)) {
            $this->stderr(Craft::t('typesense-sync', 'Name one set to adopt: --curation-set or --synonym-set.') . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!$this->checkServer()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [$kind, $name] = $this->curationSet !== null
            ? [Rules::KIND_RULES, $this->curationSet]
            : [Rules::KIND_SYNONYMS, (string)$this->synonymSet];

        try {
            $result = self::plugin()->rulesTransfer->adopt($handle, $kind, $name);
        } catch (InvalidArgumentException $e) {
            $this->stderr(Craft::t('typesense-sync', 'Nothing was adopted: {reason}', ['reason' => $e->getMessage()]) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        } catch (SyncException $e) {
            $this->stderr(Craft::t('typesense-sync', 'Nothing was adopted: {reason}', ['reason' => $e->getMessage()]) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(($kind === Rules::KIND_RULES
            ? Craft::t('typesense-sync', 'Adopted {count, plural, =1{1 rule} other{# rules}} from {name} into {collection}.', ['count' => $result['rules'], 'name' => $name, 'collection' => $handle])
            : Craft::t('typesense-sync', 'Adopted {count, plural, =1{1 synonym group} other{# synonym groups}} from {name} into {collection}.', ['count' => $result['synonyms'], 'name' => $name, 'collection' => $handle])) . "\n", Console::FG_GREEN);

        if ($result['skipped'] !== []) {
            $this->stderr(Craft::t('typesense-sync', 'Not adopted:') . "\n", Console::FG_YELLOW);

            foreach ($result['skipped'] as $line) {
                $this->stderr('  - ' . $line . "\n", Console::FG_YELLOW);
            }
        }

        if ($result['warning'] !== null) {
            $this->stderr($result['warning'] . "\n", Console::FG_YELLOW);
        }

        if ($result['problem'] !== null) {
            $this->stderr(Craft::t('typesense-sync', 'Saved, not live yet: {reason}', ['reason' => $result['problem']]) . "\n", Console::FG_RED);
            $this->stderr(Craft::t('typesense-sync', '{name} is still linked to {collection}. Run this command again once Typesense answers; what was adopted is not adopted twice.', ['name' => $name, 'collection' => $handle]) . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(($result['unlinked']
            ? Craft::t('typesense-sync', 'Live. {collection} no longer uses {name}; the set itself is kept in Typesense.', ['name' => $name, 'collection' => $handle])
            : Craft::t('typesense-sync', 'Live. {collection} did not use {name}; the set itself is kept in Typesense.', ['name' => $name, 'collection' => $handle])) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete the rules and synonyms kept for a collection no longer declared in
     * config/typesense-sync.php. The collection's handle must be typed to confirm, or given with
     * --confirm. Typesense is not touched.
     *
     * @param string $handle The collection handle the rows were kept under.
     */
    public function actionPrune(string $handle): int
    {
        // BR-21.
        $rules = self::plugin()->rules;
        $orphans = $rules->orphans();

        if (!isset($orphans[$handle])) {
            $this->stderr((self::settings()->getCollectionConfig($handle) !== null
                ? Craft::t('typesense-sync', '“{collection}” is still declared; delete its rules and synonyms in the Typesense section instead.', ['collection' => $handle])
                : Craft::t('typesense-sync', 'No rows are kept for “{collection}”. Orphaned: {orphans}.', [
                    'collection' => $handle,
                    'orphans' => $orphans === [] ? Craft::t('typesense-sync', '(none)') : implode(', ', array_keys($orphans)),
                ])) . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!$this->confirmByHandle($handle, Craft::t('typesense-sync', 'This deletes {rules, plural, =1{1 rule} other{# rules}} and {synonyms, plural, =1{1 synonym group} other{# synonym groups}} kept for {collection}.', [
            'rules' => $orphans[$handle][Rules::KIND_RULES],
            'synonyms' => $orphans[$handle][Rules::KIND_SYNONYMS],
            'collection' => $handle,
        ]))) {
            return ExitCode::USAGE;
        }

        $deleted = $rules->prune($handle);
        $this->stdout(Craft::t('typesense-sync', 'Deleted {rules, plural, =1{1 rule} other{# rules}} and {synonyms, plural, =1{1 synonym group} other{# synonym groups}} kept for {collection}.', [
            'rules' => $deleted[Rules::KIND_RULES],
            'synonyms' => $deleted[Rules::KIND_SYNONYMS],
            'collection' => $handle,
        ]) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @param list<string> $leftOut
     */
    private function printLeftOut(array $leftOut): void
    {
        if ($leftOut === []) {
            return;
        }

        $this->stderr(Craft::t('typesense-sync', 'Left out:') . "\n", Console::FG_YELLOW);

        foreach ($leftOut as $line) {
            $this->stderr('  - ' . $line . "\n", Console::FG_YELLOW);
        }
    }
}
