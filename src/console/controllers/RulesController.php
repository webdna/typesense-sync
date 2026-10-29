<?php

namespace webdna\typesensesync\console\controllers;

use Craft;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use InvalidArgumentException;
use Throwable;
use webdna\typesensesync\console\Controller;
use yii\console\ExitCode;

/**
 * Moves a collection's rules and synonyms between environments.
 *
 * @since 1.1.0
 */
class RulesController extends Controller
{
    /**
     * @var string|null Where export writes the file. Without it, the export is printed.
     */
    public ?string $file = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'export' => ['file'],
            'import' => ['confirm'],
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

        $this->stdout(Craft::t('typesense-sync', 'Exported {rules} rules and {synonyms} synonym groups from {collection} to {file}.', [
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

        $this->stdout(Craft::t('typesense-sync', 'Imported {rules} rules and {synonyms} synonym groups into {collection}.', [
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
