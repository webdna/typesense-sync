<?php

namespace webdna\typesensesync\console;

use Craft;
use craft\console\Controller as CraftController;
use craft\helpers\Console;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\TypesenseSync;
use yii\console\ExitCode;

/**
 * What the plugin's console commands share: the settings, the checks every command makes before
 * it touches the server, and the typed-handle confirmation (BR-22).
 *
 * Every refusal is printed to stderr and answered with a non-zero exit code, so a deploy script
 * stops on it: `ExitCode::CONFIG` for a config problem (BR-4), `ExitCode::USAGE` for an
 * undeclared collection, `ExitCode::UNSPECIFIED_ERROR` for the server or a failed run.
 *
 * Lives outside `console/controllers/` so Craft does not offer it as a command.
 *
 * @since 1.0.0
 */
abstract class Controller extends CraftController
{
    /**
     * @var string|null The collection handle, typed to confirm a destructive command without the
     * prompt — for a script, or with `--interactive=0`.
     */
    public ?string $confirm = null;

    protected static function plugin(): TypesenseSync
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        return $plugin;
    }

    protected static function settings(): Settings
    {
        return self::plugin()->targets->getSettings();
    }

    /**
     * Print the config's problems (BR-4) and warnings (BR-5). False when there is a problem.
     */
    protected function checkConfig(): bool
    {
        $settings = self::settings();

        foreach ($settings->getWarnings() as $warning) {
            $this->stdout('  ! ' . $warning . "\n", Console::FG_YELLOW);
        }

        $problems = $settings->getProblems();

        if ($problems === []) {
            return true;
        }

        $this->stderr(Craft::t('typesense-sync', 'The configuration has problems; nothing was changed:') . "\n", Console::FG_RED);

        foreach ($problems as $problem) {
            $this->stderr('  - ' . $problem . "\n", Console::FG_RED);
        }

        return false;
    }

    /**
     * Refuse an unconfigured, unreachable or pre-30.0 server (BR-24). False when refused.
     */
    protected function checkServer(): bool
    {
        $problem = self::plugin()->client->serverProblem();

        if ($problem === null) {
            return true;
        }

        $this->stderr($problem . "\n", Console::FG_RED);

        return false;
    }

    /**
     * Whether a collection handle is declared; says which are when it is not.
     */
    protected function checkDeclared(string $handle): bool
    {
        if (self::settings()->getCollectionConfig($handle) !== null) {
            return true;
        }

        $declared = array_keys(self::settings()->getCollectionConfigs());

        $this->stderr(Craft::t('typesense-sync', 'No collection “{collection}” is declared in config/typesense-sync.php. Declared: {declared}.', [
            'collection' => $handle,
            'declared' => $declared === [] ? Craft::t('typesense-sync', '(none)') : implode(', ', $declared),
        ]) . "\n", Console::FG_RED);

        return false;
    }

    /**
     * The handles a command runs over: the one asked for, or every declared collection. Null,
     * with the reason printed, when the one asked for is not declared.
     *
     * @return string[]|null
     */
    protected function handles(?string $only): ?array
    {
        if ($only !== null) {
            return $this->checkDeclared($only) ? [$only] : null;
        }

        return array_keys(self::settings()->getCollectionConfigs());
    }

    /**
     * Confirm a destructive command by having the collection handle typed (BR-22), from
     * `--confirm` or the prompt. Anything else refuses; nothing has changed when it does.
     */
    protected function confirmByHandle(string $handle, string $question): bool
    {
        $typed = $this->confirm ?? $this->prompt($question . ' ' . Craft::t('typesense-sync', 'Type “{collection}” to confirm:', [
            'collection' => $handle,
        ]));

        if ($typed === $handle) {
            return true;
        }

        $this->stderr(Craft::t('typesense-sync', 'Confirmation did not match “{collection}”. Nothing was changed.', [
            'collection' => $handle,
        ]) . "\n", Console::FG_RED);

        return false;
    }

    /**
     * Print what a reindex did not write. False when anything was rejected or failed, which is a
     * failed run: the documents are missing from search.
     *
     * @param array{indexed: int, rejected: int, failed: int} $run
     */
    protected function reportRun(array $run): bool
    {
        if ($run['rejected'] > 0) {
            $this->stderr('  ' . Craft::t('typesense-sync', '{count} documents were rejected by Typesense; the typesense-sync log names them.', [
                'count' => $run['rejected'],
            ]) . "\n", Console::FG_RED);
        }

        if ($run['failed'] > 0) {
            $this->stderr('  ' . Craft::t('typesense-sync', '{count} documents could not be written; the typesense-sync log says why.', [
                'count' => $run['failed'],
            ]) . "\n", Console::FG_RED);
        }

        return $run['rejected'] === 0 && $run['failed'] === 0;
    }

    /**
     * @return int An `ExitCode` constant.
     */
    protected static function exit(bool $ok): int
    {
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
