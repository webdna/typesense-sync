<?php

namespace webdna\typesensesync\web;

use Craft;
use craft\web\Controller;
use Throwable;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\TypesenseSync;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * What the Typesense section's two controllers share: who may ask, which collection is meant, and
 * how a write goes live.
 *
 * Every request is CP-only and needs *Manage Typesense rules* (BR-13); without it Craft answers
 * 403 before an action runs. Every action but the GET screens is POST and CSRF-checked. Only
 * declared collections are reachable, so rows kept for an undeclared one are neither shown nor
 * edited here (BR-21).
 *
 * A write saves the row(s) first and then pushes the collection's whole sets; a failed push keeps
 * the save, says "not live yet" and queues a retry (BR-4). A screen reads Typesense only for the
 * state, never for the rows, so an unreachable cluster never blocks editing (BR-19).
 *
 * Lives outside `controllers/` so it is never itself a route.
 *
 * @since 1.1.0
 */
abstract class SectionController extends Controller
{
    /**
     * The actions that render a screen; every other one is POST only.
     */
    protected const SCREENS = ['collections', 'collection', 'index', 'edit'];

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * `Rules::KIND_RULES` or `Rules::KIND_SYNONYMS`.
     */
    abstract protected function kind(): string;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(TypesenseSync::PERMISSION_RULES);

        if (!in_array($action->id, static::SCREENS, true)) {
            $this->requirePostRequest();
        }

        return true;
    }

    /**
     * Delete one row, then push.
     */
    public function actionDelete(): ?Response
    {
        $row = $this->requireRow((int)$this->request->getRequiredBodyParam('id'));

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        if (!self::plugin()->rules->deleteRow($this->kind(), (int)$row->id)) {
            return $this->asFailure(Craft::t('typesense-sync', 'Could not delete it.'));
        }

        return $this->goLive($row->collection, $this->kind() === Rules::KIND_RULES
            ? Craft::t('typesense-sync', 'Rule deleted.')
            : Craft::t('typesense-sync', 'Synonym group deleted.'));
    }

    /**
     * Put a collection's rows in the order given, then push. Takes `ids` as a list or as the JSON
     * Craft's admin table posts; the table posts no `collection`, so without one it is the first
     * row's.
     */
    public function actionReorder(): ?Response
    {
        $ids = $this->request->getRequiredBodyParam('ids');
        $ids = is_string($ids) ? json_decode($ids, true) : $ids;

        if (!is_array($ids)) {
            throw new BadRequestHttpException(Craft::t('typesense-sync', 'The new order could not be read.'));
        }

        $ids = array_map('intval', array_values(array_filter($ids, 'is_numeric')));
        $collection = $this->request->getBodyParam('collection');
        $collection = $collection !== null || $ids === []
            ? $this->requireCollection((string)$collection)
            : $this->requireRow($ids[0])->collection;

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        self::plugin()->rules->reorder($this->kind(), $collection, $ids);

        return $this->goLive($collection, Craft::t('typesense-sync', 'New order saved.'));
    }

    /**
     * Switch rows on or off, then push once. Takes one `id`, or the `ids` of the admin table's
     * Status action, all in one collection; flips one row unless `enabled` is posted.
     */
    public function actionToggle(): ?Response
    {
        $ids = $this->request->getBodyParam('ids');
        $rows = array_map(
            fn(mixed $id) => $this->requireRow((int)$id),
            is_array($ids) ? array_values(array_filter($ids, 'is_numeric')) : [$this->request->getRequiredBodyParam('id')],
        );

        if ($rows === [] || count(array_unique(array_map(fn(Rule|SynonymGroup $row) => $row->collection, $rows))) > 1) {
            throw new BadRequestHttpException(Craft::t('typesense-sync', 'Choose rows from one collection.'));
        }

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        $enabled = $this->request->getBodyParam('enabled');
        $rules = self::plugin()->rules;

        foreach ($rows as $row) {
            $row->enabled = $enabled === null || $enabled === '' ? !$row->enabled : (bool)$enabled;
            $saved = $row instanceof Rule ? $rules->saveRule($row) : $rules->saveSynonymGroup($row);

            if (!$saved) {
                return $this->asFailure(implode(' ', $row->getFirstErrors()) ?: Craft::t('typesense-sync', 'Could not save it.'));
            }
        }

        return $this->goLive($rows[0]->collection, $rows[0]->enabled
            ? Craft::t('typesense-sync', 'Switched on.')
            : Craft::t('typesense-sync', 'Switched off.'), ['enabled' => $rows[0]->enabled]);
    }

    /**
     * Push the collection's sets after a write (BR-4): "saved" when they are live, "saved, not
     * live yet" with the reason when the push failed and a retry is queued. The write stands
     * either way.
     *
     * @param array<string, mixed> $data
     */
    protected function goLive(string $collection, string $message, array $data = [], ?string $redirect = null): ?Response
    {
        $reason = self::plugin()->rules->pushOrQueue($collection);
        $data['live'] = $reason === null;

        if ($reason === null) {
            return $this->asSuccess($message, $data, $redirect);
        }

        return $this->asSuccess(Craft::t('typesense-sync', 'Saved, not live yet: {reason}', ['reason' => $reason]), $data, $redirect, [
            'notificationSettings' => ['type' => 'error'],
        ]);
    }

    /**
     * The handle, when it names a declared collection; 404 otherwise, which also hides one whose
     * rows are orphaned (BR-21).
     *
     * @throws NotFoundHttpException
     */
    protected function requireCollection(string $handle): string
    {
        if (self::plugin()->targets->getSettings()->getCollectionConfig($handle) === null) {
            throw new NotFoundHttpException(Craft::t('typesense-sync', 'No collection “{collection}” is declared in config/typesense-sync.php.', ['collection' => $handle]));
        }

        return $handle;
    }

    /**
     * A row of this controller's kind in a declared collection.
     *
     * @throws NotFoundHttpException
     */
    protected function requireRow(int $id): Rule|SynonymGroup
    {
        $rules = self::plugin()->rules;
        $row = $this->kind() === Rules::KIND_RULES ? $rules->getRuleById($id) : $rules->getSynonymGroupById($id);

        if ($row === null) {
            throw new NotFoundHttpException(Craft::t('typesense-sync', 'Nothing exists with the ID {id}.', ['id' => $id]));
        }

        $this->requireCollection($row->collection);

        return $row;
    }

    /**
     * A write while the connection is not configured is refused: there is nothing to push to,
     * and the section itself shows the unconfigured message (BR-19).
     */
    protected function refuseUnconfigured(): ?Response
    {
        return self::plugin()->targets->getSettings()->isConfigured()
            ? null
            : $this->asFailure(Craft::t('typesense-sync', 'Typesense Sync is not connected. Enter the server details in its settings.'));
    }

    /**
     * The section's message while the connection is not configured (BR-19).
     */
    protected function renderUnconfigured(): Response
    {
        return $this->renderTemplate('typesense-sync/_section/unconfigured.twig', [
            'settingsUrl' => 'settings/plugins/' . TypesenseSync::HANDLE,
        ]);
    }

    /**
     * Everything a collection's tab shows around its rows: the declared collections for the
     * switcher, and the state of both sets for the banner. The state is the only thing read from
     * Typesense, and a failure to read it is a state, never an error (BR-19).
     *
     * @return array<string, mixed>
     */
    protected function tabVariables(string $collection): array
    {
        $rules = self::plugin()->rules;

        try {
            $state = $rules->state($collection);
        } catch (Throwable $e) {
            Craft::warning(sprintf('Could not read the rule state of "%s": %s', $collection, $e->getMessage()), TypesenseSync::HANDLE);
            $state = array_fill_keys(Rules::KINDS, [
                'state' => Rules::STATE_UNKNOWN,
                'name' => '',
                'exists' => false,
                'linked' => false,
                'differs' => 0,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'collection' => $collection,
            'collections' => array_keys(self::plugin()->targets->getSettings()->getCollectionConfigs()),
            'kind' => $this->kind(),
            'state' => $state,
            'banner' => self::banner($state),
            'sectionUri' => TypesenseSync::SECTION_URI,
        ];
    }

    /**
     * The state banner's lines (§4 States), nothing while both sets are in step. `push` is whether
     * *Push now* can help: never while Typesense cannot be asked.
     *
     * @param array<string, array{state: string, exists: bool, linked: bool, differs: int, message: ?string}> $state
     * @return array{lines: list<array{kind: string, state: string, text: string}>, push: bool}
     */
    public static function banner(array $state): array
    {
        $lines = [];
        $unknown = array_filter($state, fn(array $row) => $row['state'] === Rules::STATE_UNKNOWN);

        // The same failure for both sets is one line, not two.
        if ($unknown !== [] && count(array_unique(array_map(fn(array $row) => (string)$row['message'], $unknown))) === 1) {
            $lines[] = ['kind' => '', 'state' => Rules::STATE_UNKNOWN, 'text' => Craft::t('typesense-sync', 'Can’t check whether search is up to date — Typesense is unreachable: {message}', [
                'message' => (string)reset($unknown)['message'],
            ])];
            $state = array_diff_key($state, $unknown);
        }

        foreach ($state as $kind => $row) {
            $what = $kind === Rules::KIND_RULES ? Craft::t('typesense-sync', 'Rules') : Craft::t('typesense-sync', 'Synonyms');
            $text = match ($row['state']) {
                Rules::STATE_UNKNOWN => Craft::t('typesense-sync', 'Can’t check {what} — Typesense is unreachable: {message}', ['what' => mb_strtolower($what), 'message' => (string)$row['message']]),
                Rules::STATE_NOT_LIVE => Craft::t('typesense-sync', '{what} are saved but not live yet ({reason}). They go live on the next save, or now with Push now.', ['what' => $what, 'reason' => Rules::describeState($row)]),
                Rules::STATE_OUT_OF_STEP => Craft::t('typesense-sync', '{what} are out of step: {reason} from what Craft holds, usually after an edit in the Typesense dashboard. The next save, or Push now, puts Craft’s version back.', ['what' => $what, 'reason' => Rules::describeState($row)]),
                default => null,
            };

            if ($text !== null) {
                $lines[] = ['kind' => (string)$kind, 'state' => $row['state'], 'text' => $text];
            }
        }

        return [
            'lines' => $lines,
            'push' => array_filter($lines, fn(array $line) => $line['state'] !== Rules::STATE_UNKNOWN) !== [],
        ];
    }

    protected static function plugin(): TypesenseSync
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        return $plugin;
    }
}
