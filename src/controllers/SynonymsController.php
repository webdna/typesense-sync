<?php

namespace webdna\typesensesync\controllers;

use Craft;
use webdna\typesensesync\models\SynonymGroup;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\web\SectionController;
use yii\web\Response;

/**
 * A collection's Synonyms tab and group edit screen, and the synonym actions: save, delete,
 * reorder and switch on or off (BR-4, BR-13, BR-19).
 *
 * @since 1.1.0
 */
class SynonymsController extends SectionController
{
    /**
     * The posted `type` for a one-way group; anything else is all equal.
     */
    public const TYPE_ONE_WAY = 'oneWay';

    protected function kind(): string
    {
        return Rules::KIND_SYNONYMS;
    }

    /**
     * A collection's Synonyms tab, from Craft's rows whatever Typesense's state (BR-19).
     */
    public function actionIndex(string $collection): Response
    {
        if (!self::plugin()->targets->getSettings()->isConfigured()) {
            return $this->renderUnconfigured();
        }

        $collection = $this->requireCollection($collection);

        $groups = self::plugin()->rules->getSynonymGroups($collection);

        return $this->renderTemplate('typesense-sync/synonyms/_index.twig', [
            'groups' => $groups,
            'tableData' => array_map(fn(SynonymGroup $group) => [
                'id' => $group->id,
                'title' => $group->isOneWay() ? (string)$group->root : implode(', ', $group->words),
                'url' => RulesController::tabUrl($group->collection, Rules::KIND_SYNONYMS) . '/' . $group->id,
                'type' => $group->isOneWay() ? Craft::t('typesense-sync', 'One way') : Craft::t('typesense-sync', 'All equal'),
                'words' => $group->isOneWay() ? implode(', ', $group->words) : '',
                'status' => $group->enabled ? 'on' : 'off',
                'statusLabel' => $group->enabled ? Craft::t('typesense-sync', 'On') : Craft::t('typesense-sync', 'Off'),
            ], $groups),
        ] + $this->tabVariables($collection));
    }

    /**
     * New group, or edit one. A failed save comes back here with its `group` and errors.
     */
    public function actionEdit(string $collection, ?int $groupId = null, ?SynonymGroup $group = null): Response
    {
        if (!self::plugin()->targets->getSettings()->isConfigured()) {
            return $this->renderUnconfigured();
        }

        $collection = $this->requireCollection($collection);

        if ($group === null) {
            $group = $groupId !== null ? $this->requireRow($groupId) : new SynonymGroup(['collection' => $collection]);
        }

        return $this->renderTemplate('typesense-sync/synonyms/_edit.twig', [
            'group' => $group,
        ] + $this->tabVariables($collection));
    }

    /**
     * Save a group, then push (BR-4). Refused, with the form shown again, when invalid (BR-10).
     * `words` may be a list, editable-table rows (`[{word: …}]`) or one word per line.
     */
    public function actionSave(): ?Response
    {
        $groupId = $this->request->getBodyParam('groupId');

        if (is_numeric($groupId)) {
            $group = $this->requireRow((int)$groupId);
            assert($group instanceof SynonymGroup);
        } else {
            $group = new SynonymGroup(['collection' => $this->requireCollection((string)$this->request->getRequiredBodyParam('collection'))]);
        }

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        $type = $this->request->getBodyParam('type', $group->isOneWay() ? self::TYPE_ONE_WAY : null);
        $root = trim((string)$this->request->getBodyParam('root', $group->root));
        // A blank main term makes the group all equal, as the model does.
        $group->root = $type === self::TYPE_ONE_WAY && $root !== '' ? $root : null;
        $group->words = SynonymGroup::normaliseWords(self::words($this->request->getBodyParam('words', $group->words)));
        $group->enabled = (bool)$this->request->getBodyParam('enabled', $group->enabled);

        // One way chosen with no main term is refused, not quietly saved as all equal (BR-10). An
        // empty root keeps the form on One way.
        if ($type === self::TYPE_ONE_WAY && $root === '') {
            $group->root = '';
            $group->addError('root', Craft::t('typesense-sync', 'Enter the main term, or choose All equal.'));

            return $this->asModelFailure($group, Craft::t('typesense-sync', 'Couldn’t save the synonym group.'), 'group');
        }

        if (!self::plugin()->rules->saveSynonymGroup($group)) {
            return $this->asModelFailure($group, Craft::t('typesense-sync', 'Couldn’t save the synonym group.'), 'group');
        }

        return $this->goLive($group->collection, Craft::t('typesense-sync', 'Synonym group saved.'), ['id' => $group->id], RulesController::tabUrl($group->collection, Rules::KIND_SYNONYMS));
    }

    /**
     * @return string[]
     */
    private static function words(mixed $posted): array
    {
        if (is_string($posted)) {
            return preg_split('/\R/', $posted) ?: [];
        }

        return array_map(
            static fn(mixed $row) => is_array($row) ? (string)($row['word'] ?? '') : (string)$row,
            (array)$posted,
        );
    }
}
