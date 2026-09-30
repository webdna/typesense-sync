<?php

namespace webdna\typesensesync;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;

/**
 * Typesense Sync: keeps declared Craft content in Typesense collections and hands search pages
 * scoped, filtered keys.
 *
 * The plugin owns the machinery (queueing, batching, safe rebuilds, keys); the site owns the
 * meaning (which sources feed which collection, and a formatter per kind of result).
 *
 * @author webdna
 * @since 1.0.0
 *
 * @method Settings getSettings()
 * @property-read Client $client
 */
class TypesenseSync extends Plugin
{
    /**
     * The handle, as Craft knows the plugin and as the translation and log categories are named.
     */
    public const HANDLE = 'typesense-sync';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'client' => Client::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->applyFileOnlySettings();
    }

    /**
     * Keeps the saved admin key when its field is left blank: a literal key is never rendered
     * back into the form (BR-17), so a blank field means "unchanged", not "remove".
     */
    public function beforeSaveSettings(): bool
    {
        $settings = $this->getSettings();

        if ($settings->apiKey === '') {
            $saved = Craft::$app->getProjectConfig()->get(sprintf('plugins.%s.settings.apiKey', self::HANDLE));
            $settings->apiKey = is_string($saved) ? $saved : '';
        }

        return parent::beforeSaveSettings();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $isReference = str_starts_with($settings->apiKey, '$');

        return Craft::$app->getView()->renderTemplate('typesense-sync/settings.twig', [
            'settings' => $settings,
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile(self::HANDLE)),
            // An env reference is safe to show; a literal key is shown only by its last four.
            'apiKeyValue' => $isReference ? $settings->apiKey : '',
            'maskedApiKey' => $isReference ? '' : $settings->getMaskedApiKey(),
            'problems' => $settings->getProblems(),
            'warnings' => $settings->getWarnings(),
        ]);
    }

    /**
     * Collections, sources and analytics come only from `config/typesense-sync.php` (BR-2).
     * Craft would otherwise also take them from project config, where a hand edit could leave a
     * copy the file does not know about.
     */
    private function applyFileOnlySettings(): void
    {
        $file = Craft::$app->getConfig()->getConfigFromFile(self::HANDLE);
        $settings = $this->getSettings();

        foreach (Settings::FILE_ONLY as $key) {
            $settings->$key = is_array($file[$key] ?? null) ? $file[$key] : [];
        }
    }
}
