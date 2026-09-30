<?php

namespace webdna\typesensesync;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineMenuItemsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use Throwable;
use webdna\typesensesync\elements\actions\Sync as SyncAction;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\services\Search;
use webdna\typesensesync\services\Sync;
use webdna\typesensesync\services\Targets;
use webdna\typesensesync\utilities\Utility;
use webdna\typesensesync\variables\TypesenseVariable;
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
 * @property-read Collections $collections
 * @property-read Search $search
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
                'collections' => Collections::class,
                'search' => Search::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->applyFileOnlySettings();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event): void {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('typesense', TypesenseVariable::class);
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event): void {
            $event->types[] = Utility::class;
        });

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

            Event::on($type, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event): void {
                if (self::canSync()) {
                    $event->actions[] = SyncAction::class;
                }
            });
            Event::on($type, Element::EVENT_DEFINE_ACTION_MENU_ITEMS, function(DefineMenuItemsEvent $event): void {
                $element = $event->sender;

                if ($element instanceof ElementInterface && ($item = $this->syncMenuItem($element)) !== null) {
                    $event->items[] = $item;
                }
            });
        }
    }

    /**
     * The edit screen's "Sync to Typesense" item, or null where it would do nothing: for someone
     * without the permission (BR-22), for an element no declared source routes anywhere, or for
     * an element never published. A provisional draft syncs its canonical element, which is what
     * is in search. Never throws; the edit screen must render.
     *
     * @return array<string, mixed>|null
     */
    public function syncMenuItem(ElementInterface $element): ?array
    {
        try {
            if (!self::canSync() || $element->getIsUnpublishedDraft() || $element->getCanonicalId() === null) {
                return null;
            }

            $canonical = $element->getCanonical();

            if (!$this->targets->shouldQueue($canonical)) {
                return null;
            }

            $params = ['elementId' => $canonical->id, 'siteId' => $canonical->siteId];

            if (($url = $canonical->getCpEditUrl()) !== null) {
                $params['redirect'] = Craft::$app->getSecurity()->hashData($url);
            }

            return [
                'label' => Craft::t('typesense-sync', 'Sync to Typesense'),
                'icon' => 'magnifying-glass',
                'action' => 'typesense-sync/utility/sync-element',
                'params' => $params,
                'attributes' => ['data-ts-action' => 'sync-element'],
            ];
        } catch (Throwable $e) {
            Craft::error(sprintf('Could not build the Typesense menu item for element %s: %s', $element->id, $e->getMessage()), self::HANDLE);

            return null;
        }
    }

    private static function canSync(): bool
    {
        $user = Craft::$app->getUser();

        return !$user->getIsGuest() && $user->checkPermission(Utility::PERMISSION);
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
