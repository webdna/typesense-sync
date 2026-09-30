<?php

namespace webdna\typesensesync;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use Throwable;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\services\Targets;
use yii\base\Event;

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
 * @property-read Targets $targets
 * @property-read Sync $sync
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
                'targets' => Targets::class,
                'sync' => Sync::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->applyFileOnlySettings();

        // Once every plugin has loaded, so their EVENT_REGISTER_ELEMENT_TYPES handlers count.
        Craft::$app->onInit(fn() => $this->registerElementEvents());
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
     * Saves, deletes and restores of the followed element types queue work (BR-6). Which
     * elements actually queue anything is `sync`'s question; this only listens.
     *
     * A handler never lets an exception reach the save that fired it (BR-10): queueing touches
     * only the cache and the queue table, and a failure there is logged, not thrown at an editor.
     */
    private function registerElementEvents(): void
    {
        $queue = function(string $action, Event $event): void {
            $element = $event->sender;

            if (!$element instanceof ElementInterface) {
                return;
            }

            try {
                $action === 'delete' ? $this->sync->queueDelete($element) : $this->sync->queueElement($element);
            } catch (Throwable $e) {
                Craft::error(sprintf('Could not queue a Typesense %s for element %s: %s', $action, $element->id, $e->getMessage()), self::HANDLE);
            }
        };

        foreach ($this->targets->getElementTypes() as $type) {
            Event::on($type, Element::EVENT_AFTER_SAVE, fn(Event $event) => $queue('sync', $event));
            // Craft hard-deletes a provisional draft on every CP save, which fires this too; the
            // draft guard in Targets::isSyncable() is what keeps that from deleting the document.
            Event::on($type, Element::EVENT_AFTER_DELETE, fn(Event $event) => $queue('delete', $event));
            // Soft-deleted elements come back, and so must their documents.
            Event::on($type, Element::EVENT_AFTER_RESTORE, fn(Event $event) => $queue('sync', $event));
        }
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
