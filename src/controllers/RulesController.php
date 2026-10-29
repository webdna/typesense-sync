<?php

namespace webdna\typesensesync\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use Throwable;
use webdna\typesensesync\models\Rule;
use webdna\typesensesync\services\Rules;
use webdna\typesensesync\TypesenseSync;
use webdna\typesensesync\web\SectionController;
use yii\web\Response;

/**
 * The Typesense section's landing page, a collection's Rules tab and rule edit screen, and the
 * rule actions: save, delete, reorder, switch on or off, and push now (BR-4, BR-13, BR-19).
 *
 * @since 1.1.0
 */
class RulesController extends SectionController
{
    protected function kind(): string
    {
        return Rules::KIND_RULES;
    }

    /**
     * `admin/typesense`: the declared collections with their counts and state, or straight to the
     * only one. Undeclared collections are not listed (BR-21).
     */
    public function actionCollections(): Response
    {
        $settings = self::plugin()->targets->getSettings();

        if (!$settings->isConfigured()) {
            return $this->renderUnconfigured();
        }

        $handles = array_keys($settings->getCollectionConfigs());

        if (count($handles) === 1) {
            return $this->redirect(self::tabUrl($handles[0], Rules::KIND_RULES));
        }

        $rules = self::plugin()->rules;
        $collections = [];

        foreach ($handles as $handle) {
            $collections[] = [
                'handle' => $handle,
                'url' => self::tabUrl($handle, Rules::KIND_RULES),
                'rules' => count($rules->getRules($handle)),
                'synonyms' => count($rules->getSynonymGroups($handle)),
            ] + $this->tabVariables($handle);
        }

        return $this->renderTemplate('typesense-sync/_section/index.twig', [
            'collections' => $collections,
            'utilityUrl' => 'utilities/' . TypesenseSync::HANDLE,
        ]);
    }

    /**
     * `admin/typesense/<collection>`: its Rules tab.
     */
    public function actionCollection(string $collection): Response
    {
        return $this->redirect(self::tabUrl($this->requireCollection($collection), Rules::KIND_RULES));
    }

    /**
     * A collection's Rules tab, from Craft's rows whatever Typesense's state (BR-19).
     */
    public function actionIndex(string $collection): Response
    {
        if (!self::plugin()->targets->getSettings()->isConfigured()) {
            return $this->renderUnconfigured();
        }

        $collection = $this->requireCollection($collection);
        $rules = self::plugin()->rules->getRules($collection);

        return $this->renderTemplate('typesense-sync/rules/_index.twig', [
            'rules' => $rules,
            'tableData' => array_map(fn(Rule $rule) => self::tableRow($rule), $rules),
        ] + $this->tabVariables($collection));
    }

    /**
     * New rule, or edit one. A failed save comes back here with its `rule` and errors.
     */
    public function actionEdit(string $collection, ?int $ruleId = null, ?Rule $rule = null): Response
    {
        if (!self::plugin()->targets->getSettings()->isConfigured()) {
            return $this->renderUnconfigured();
        }

        $collection = $this->requireCollection($collection);

        if ($rule === null) {
            $rule = $ruleId !== null ? $this->requireRow($ruleId) : new Rule(['collection' => $collection]);
        }

        return $this->renderTemplate('typesense-sync/rules/_edit.twig', [
            'rule' => $rule,
            'pickers' => $this->pickers($rule),
            'statusLabel' => self::statusLabel($rule->getStatus()),
        ] + $this->tabVariables($collection));
    }

    /**
     * Save a rule, then push (BR-4). Refused, with the form shown again, when it is invalid or
     * names an element that does not feed the collection (BR-6, BR-7, BR-8, BR-11).
     */
    public function actionSave(): ?Response
    {
        $ruleId = $this->request->getBodyParam('ruleId');

        if (is_numeric($ruleId)) {
            $rule = $this->requireRow((int)$ruleId);
            assert($rule instanceof Rule);
        } else {
            $rule = new Rule(['collection' => $this->requireCollection((string)$this->request->getRequiredBodyParam('collection'))]);
        }

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        $rule->query = trim((string)$this->request->getBodyParam('query', $rule->query));
        $rule->matchType = (string)$this->request->getBodyParam('matchType', $rule->matchType);
        $rule->pins = Rule::normaliseIds(self::ids($this->request->getBodyParam('pins', $rule->pins)));
        $rule->hides = Rule::normaliseIds(self::ids($this->request->getBodyParam('hides', $rule->hides)));
        $rule->effectiveFrom = self::date($this->request->getBodyParam('effectiveFrom'), $rule->effectiveFrom);
        $rule->effectiveTo = self::date($this->request->getBodyParam('effectiveTo'), $rule->effectiveTo);
        $rule->enabled = (bool)$this->request->getBodyParam('enabled', $rule->enabled);

        if (!self::plugin()->rules->saveRule($rule)) {
            return $this->asModelFailure($rule, Craft::t('typesense-sync', 'Couldn’t save the rule.'), 'rule');
        }

        return $this->goLive($rule->collection, Craft::t('typesense-sync', 'Rule saved.'), ['id' => $rule->id], self::tabUrl($rule->collection, Rules::KIND_RULES));
    }

    /**
     * *Push now*: put both of a collection's sets into Typesense as Craft holds them (BR-14).
     */
    public function actionPush(): ?Response
    {
        $collection = $this->requireCollection((string)$this->request->getRequiredBodyParam('collection'));

        if (($refusal = $this->refuseUnconfigured()) !== null) {
            return $refusal;
        }

        try {
            self::plugin()->rules->push($collection);
        } catch (Throwable $e) {
            Craft::error(sprintf('Pushing the rules of "%s" failed: %s', $collection, $e->getMessage()), TypesenseSync::HANDLE);

            return $this->asFailure(Craft::t('typesense-sync', 'Could not push the rules and synonyms of “{collection}”: {message}', [
                'collection' => $collection,
                'message' => $e->getMessage(),
            ]));
        }

        return $this->asSuccess(Craft::t('typesense-sync', 'Rules and synonyms of “{collection}” are live.', ['collection' => $collection]));
    }

    /**
     * A tab's CP URL.
     */
    public static function tabUrl(string $collection, string $kind): string
    {
        return UrlHelper::cpUrl(sprintf('%s/%s/%s', TypesenseSync::SECTION_URI, $collection, $kind));
    }

    /**
     * The words a rule's status is shown as (BR-11).
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Rule::STATUS_OFF => Craft::t('typesense-sync', 'Off'),
            Rule::STATUS_SCHEDULED => Craft::t('typesense-sync', 'Scheduled'),
            Rule::STATUS_ENDED => Craft::t('typesense-sync', 'Ended'),
            default => Craft::t('typesense-sync', 'On'),
        };
    }

    /**
     * One row of the Rules tab's admin table.
     *
     * @return array<string, mixed>
     */
    private static function tableRow(Rule $rule): array
    {
        $status = $rule->getStatus();
        $formatter = Craft::$app->getFormatter();
        $dates = array_filter([
            $rule->effectiveFrom !== null ? Craft::t('typesense-sync', 'from {date}', ['date' => $formatter->asDatetime($rule->effectiveFrom, 'short')]) : null,
            $rule->effectiveTo !== null ? Craft::t('typesense-sync', 'until {date}', ['date' => $formatter->asDatetime($rule->effectiveTo, 'short')]) : null,
        ]);

        return [
            'id' => $rule->id,
            'title' => $rule->query,
            'url' => self::tabUrl($rule->collection, Rules::KIND_RULES) . '/' . $rule->id,
            'match' => $rule->matchType === Rule::MATCH_CONTAINS ? Craft::t('typesense-sync', 'Contains') : Craft::t('typesense-sync', 'Exact'),
            'pins' => count($rule->pins),
            'hides' => count($rule->hides),
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'dates' => implode(', ', $dates),
        ];
    }

    /**
     * The collection's pickers (BR-8), each with the rule's pinned and hidden elements of its type,
     * in the rule's order. An element no longer there is left out, so the next save drops it.
     *
     * @return list<array<string, mixed>>
     */
    private function pickers(Rule $rule): array
    {
        $pickers = self::plugin()->rules->pickers($rule->collection);

        foreach ($pickers as $index => $picker) {
            $ids = array_merge($rule->pins, $rule->hides);
            $found = $ids === [] ? [] : $picker['elementType']::find()
                ->id($ids)
                ->siteId($picker['siteId'])
                ->status(null)
                ->indexBy('id')
                ->all();
            $pick = static fn(array $ids) => array_values(array_filter(array_map(fn(int $id) => $found[$id] ?? null, $ids)));
            $pickers[$index] += ['pinned' => $pick($rule->pins), 'hidden' => $pick($rule->hides)];
        }

        return $pickers;
    }

    /**
     * Posted element ids as one list: a flat list, or one list per picker (`pins[0][]`), each
     * picker's empty value included.
     *
     * @return array<int|string, mixed>
     */
    private static function ids(mixed $posted): array
    {
        if (!is_array($posted)) {
            return (array)$posted;
        }

        $ids = [];
        array_walk_recursive($posted, function(mixed $id) use (&$ids) {
            $ids[] = $id;
        });

        return $ids;
    }

    /**
     * A posted date field (Craft's date picker posts `{date, timezone}`), the current value when
     * not posted, null when posted empty.
     */
    private static function date(mixed $posted, ?DateTime $current): ?DateTime
    {
        if ($posted === null) {
            return $current;
        }

        $date = DateTimeHelper::toDateTime($posted);

        return $date instanceof DateTime ? $date : null;
    }
}
