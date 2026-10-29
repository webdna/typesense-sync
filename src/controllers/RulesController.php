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

        return $this->renderTemplate('typesense-sync/rules/_index.twig', [
            'rules' => self::plugin()->rules->getRules($collection),
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
        $rule->pins = Rule::normaliseIds($this->request->getBodyParam('pins', $rule->pins));
        $rule->hides = Rule::normaliseIds($this->request->getBodyParam('hides', $rule->hides));
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
