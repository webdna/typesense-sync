<?php

namespace webdna\typesensesync\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use webdna\typesensesync\TypesenseSync;
use webdna\typesensesync\utilities\Utility;

/**
 * "Sync to Typesense" on an element index: queues a sync of each selected element that a
 * declared source routes somewhere, and skips the rest.
 *
 * Registered only for the element types the config follows, and only for people with the
 * utility's permission (BR-22); `performAction()` checks the permission again, because the
 * action is addressed by class name in the request.
 *
 * @since 1.0.0
 */
class Sync extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('typesense-sync', 'Sync to Typesense');
    }

    public function getTriggerLabel(): string
    {
        return self::displayName();
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if (!Craft::$app->getUser()->checkPermission(Utility::PERMISSION)) {
            $this->setMessage(Craft::t('typesense-sync', 'You are not allowed to sync to Typesense.'));

            return false;
        }

        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        if (!$plugin->client->getClient()) {
            $this->setMessage(Craft::t('typesense-sync', 'Typesense Sync is not connected. Enter the server details in its settings.'));

            return false;
        }

        $syncable = 0;

        foreach ($query->status(null)->all() as $element) {
            // queueElement() skips what no source declares, and a sync already pending (BR-9).
            if ($plugin->targets->shouldQueue($element)) {
                $plugin->sync->queueElement($element);
                $syncable++;
            }
        }

        if ($syncable === 0) {
            $this->setMessage(Craft::t('typesense-sync', 'None of the selected elements is declared for search.'));

            return false;
        }

        $this->setMessage(Craft::t('typesense-sync', 'Syncing {num, plural, =1{1 element} other{# elements}} to Typesense.', [
            'num' => $syncable,
        ]));

        return true;
    }
}
