<?php

namespace webdna\typesensesync\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Queue;
use craft\web\Controller;
use Throwable;
use webdna\typesensesync\jobs\Recreate;
use webdna\typesensesync\jobs\Reindex;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\TypesenseSync;
use webdna\typesensesync\utilities\Utility;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The actions behind the Typesense Sync utility, the element action's single-element sibling and
 * the edit-screen menu item.
 *
 * Every action is CP-only, POST, CSRF-checked and needs the utility's permission (BR-22, BR-23).
 * Craft's own `beforeAction()` runs first, so a missing CSRF token is refused before anything
 * else and an anonymous caller is sent to log in.
 *
 * A refusal answers through `asFailure()`, which re-renders the page that posted with the reason
 * as an error notice; nothing has changed when it does.
 *
 * @since 1.0.0
 */
class UtilityController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Utility::PERMISSION);
        $this->requirePostRequest();

        return true;
    }

    /**
     * Queue a sync of one element, from its edit screen or by id.
     */
    public function actionSyncElement(): ?Response
    {
        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $siteId = $this->request->getBodyParam('siteId');
        $element = Craft::$app->getElements()->getElementById(
            $elementId,
            null,
            is_numeric($siteId) ? (int)$siteId : null,
            ['status' => null],
        );

        if (!$element instanceof ElementInterface) {
            throw new BadRequestHttpException(Craft::t('typesense-sync', 'No element exists with the ID {id}.', ['id' => $elementId]));
        }

        $plugin = self::plugin();
        $name = self::describe($element);

        // Refused rather than queued: a job that resolves to nothing would be a silent no-op.
        if ($plugin->targets->resolveTargetFor($element) === null) {
            return $this->asFailure(Craft::t('typesense-sync', '“{element}” is not declared for search, so it is not synced.', [
                'element' => $name,
            ]));
        }

        if (!$plugin->targets->isSyncable($element)) {
            return $this->asFailure(Craft::t('typesense-sync', '“{element}” is a draft or revision; only the published element is synced.', [
                'element' => $name,
            ]));
        }

        if (!$plugin->client->getClient()) {
            return $this->asFailure(Craft::t('typesense-sync', 'Typesense Sync is not connected. Enter the server details in its settings.'));
        }

        // False also when a sync is already pending (BR-9), which is the result asked for.
        $plugin->sync->queueElement($element);

        return $this->asSuccess(Craft::t('typesense-sync', 'Syncing “{element}” to Typesense.', ['element' => $name]));
    }

    /**
     * Queue a reindex of one collection, optionally pruning what the run does not build (BR-12, BR-13).
     */
    public function actionReindex(): ?Response
    {
        $handle = $this->requireCollection();
        $prune = (bool)$this->request->getBodyParam('prune');

        if (($problem = self::plugin()->client->serverProblem()) !== null) {
            return $this->asFailure($problem);
        }

        // A reindex into a collection that does not exist fails document by document.
        if (($failure = $this->requireLive($handle)) !== null) {
            return $failure;
        }

        Queue::push(new Reindex(['collection' => $handle, 'prune' => $prune]), self::plugin()->targets->getSettings()->queuePriority);

        return $this->asSuccess($prune
            ? Craft::t('typesense-sync', 'Queued a reindex of “{collection}”; documents no element builds will then be removed.', ['collection' => $handle])
            : Craft::t('typesense-sync', 'Queued a reindex of “{collection}”.', ['collection' => $handle]));
    }

    /**
     * Create the collection, or alter it in place to match the declared schema (BR-16). Runs in
     * the request: it is one call to the server, and never reindexes.
     */
    public function actionApply(): ?Response
    {
        $handle = $this->requireCollection();

        if (($problem = self::plugin()->client->serverProblem()) !== null) {
            return $this->asFailure($problem);
        }

        try {
            $result = self::plugin()->collections->apply($handle);
        } catch (Throwable $e) {
            Craft::error(sprintf('Apply of "%s" failed: %s', $handle, $e->getMessage()), TypesenseSync::HANDLE);

            return $this->asFailure(Craft::t('typesense-sync', 'Could not apply “{collection}”: {message}', [
                'collection' => $handle,
                'message' => $e->getMessage(),
            ]));
        }

        $params = ['collection' => $handle, 'name' => (string)$result['collection']];

        return match ($result['action']) {
            Collections::ACTION_CREATE => $this->asSuccess(Craft::t('typesense-sync', 'Created {name} for “{collection}”. Reindex it to fill it.', $params)),
            Collections::ACTION_ALTER => $this->asSuccess($result['reindex']
                ? Craft::t('typesense-sync', 'Altered {name}. Reindex “{collection}” so every document has the new fields.', $params)
                : Craft::t('typesense-sync', 'Altered {name}.', $params)),
            Collections::ACTION_NONE => $this->asSuccess(Craft::t('typesense-sync', '“{collection}” is already up to date.', $params)),
            Collections::ACTION_SKIP => $this->asFailure(Craft::t('typesense-sync', 'Nothing enabled is declared to feed “{collection}”, so there is no schema to apply.', $params)),
            Collections::ACTION_NEEDS_RECREATE => $this->asFailure(Craft::t('typesense-sync', '“{collection}” cannot be altered in place. Recreate it instead.', $params)),
            default => $this->asFailure(Craft::t('typesense-sync', 'Could not apply “{collection}”: {message}', [
                'collection' => $handle,
                'message' => $result['message'],
            ])),
        };
    }

    /**
     * Queue a recreate of one collection and its dependants (BR-15). The handle must be typed to
     * confirm (BR-22); anything else is refused before the server is asked.
     */
    public function actionRecreate(): ?Response
    {
        $handle = $this->requireCollection();

        if ((string)$this->request->getBodyParam('confirm') !== $handle) {
            return $this->asFailure(Craft::t('typesense-sync', 'Type “{collection}” to confirm the recreate. Nothing was changed.', [
                'collection' => $handle,
            ]));
        }

        $plugin = self::plugin();

        // Recreate refuses these itself, but only once the job runs; refusing now tells the person why.
        if ($plugin->targets->getSettings()->getProblems() !== []) {
            return $this->asFailure(Craft::t('typesense-sync', 'The configuration has problems, listed on this page. Nothing was changed.'));
        }

        if (($problem = $plugin->client->serverProblem()) !== null) {
            return $this->asFailure($problem);
        }

        Queue::push(new Recreate(['collection' => $handle]), $plugin->targets->getSettings()->queuePriority, null, Recreate::TTR);

        return $this->asSuccess(Craft::t('typesense-sync', 'Queued a recreate of “{collection}”. Search keeps answering from the current version until the new one is complete.', [
            'collection' => $handle,
        ]));
    }

    /**
     * The posted collection handle, which must be declared.
     *
     * @throws BadRequestHttpException
     */
    private function requireCollection(): string
    {
        $handle = (string)$this->request->getRequiredBodyParam('collection');

        if (self::plugin()->targets->getSettings()->getCollectionConfig($handle) === null) {
            throw new BadRequestHttpException(Craft::t('typesense-sync', 'No collection “{collection}” is declared in config/typesense-sync.php.', ['collection' => $handle]));
        }

        return $handle;
    }

    private function requireLive(string $handle): ?Response
    {
        try {
            $live = self::plugin()->collections->getActiveCollectionName($handle);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        if ($live === null) {
            return $this->asFailure(Craft::t('typesense-sync', '“{collection}” does not exist on the server yet. Apply it first.', [
                'collection' => $handle,
            ]));
        }

        return null;
    }

    private static function plugin(): TypesenseSync
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        return $plugin;
    }

    private static function describe(ElementInterface $element): string
    {
        $title = (string)$element;

        return $title !== '' ? $title : (string)$element->id;
    }
}
