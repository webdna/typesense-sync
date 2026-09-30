<?php

namespace webdna\typesensesync\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ElementHelper;
use craft\models\Site;
use webdna\typesensesync\events\RegisterElementTypesEvent;
use webdna\typesensesync\events\ResolveTargetEvent;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\models\ResolvedTarget;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\models\SourceConfig;
use webdna\typesensesync\TypesenseSync;

/**
 * Which declared target, if any, an element belongs to — the one answer every save, delete,
 * restore, sync and reindex asks (BR-1).
 *
 * Nothing here reaches Typesense: an element event asks this service and queues work, and the
 * network call happens in the job (BR-10).
 *
 * @since 1.0.0
 */
class Targets extends Component
{
    /**
     * Raised after an element is resolved against the declared sources, so a site can reroute it,
     * exclude it, or give a target to an element type the plugin does not know.
     *
     * @see ResolveTargetEvent
     */
    public const EVENT_RESOLVE_TARGET = 'resolveTarget';

    /**
     * Raised when listing the element types whose saves, deletes and restores are followed.
     *
     * @see RegisterElementTypesEvent
     */
    public const EVENT_REGISTER_ELEMENT_TYPES = 'registerElementTypes';

    private ?Settings $settings = null;

    /**
     * Settings to resolve against; the plugin's own when not given. Tests pass their own.
     */
    public function setSettings(?Settings $settings): void
    {
        $this->settings = $settings;
    }

    public function getSettings(): Settings
    {
        return $this->settings ?? TypesenseSync::getInstance()->getSettings();
    }

    /**
     * Whether a save, delete or restore of this element may queue work at all (BR-6), before
     * asking what it resolves to.
     *
     * Drafts and revisions never do: a CP save hard-deletes its provisional draft, and that
     * fires the same delete event as a real one. `isDraftOrRevision()` also defers to the
     * owner, so a nested entry in a draft is excluded here. A bulk resave and multi-site
     * propagation fan out into saves of content that has not changed; a reindex is the tool
     * for those.
     */
    public function isSyncable(ElementInterface $element): bool
    {
        return $element->id !== null
            && !ElementHelper::isDraftOrRevision($element)
            && !$element->resaving
            && !$element->propagating;
    }

    /**
     * The indexable target for an element, or null to leave it alone: its kind is undeclared,
     * its source or entry type is switched off, its target is invalid, or it is a nested entry.
     *
     * This does not check syncability; ask isSyncable() first on an element event.
     */
    public function resolveTargetFor(ElementInterface $element): ?ResolvedTarget
    {
        $target = $this->declaredTargetFor($element);

        if ($this->hasEventHandlers(self::EVENT_RESOLVE_TARGET)) {
            $event = new ResolveTargetEvent(['element' => $element, 'target' => $target]);
            $this->trigger(self::EVENT_RESOLVE_TARGET, $event);

            // A handler may have replaced the target or changed it in place; either way it is
            // checked again, so a handler cannot route into an undeclared collection.
            $target = $event->target !== null ? $this->getSettings()->markValidity($event->target) : null;

            if ($target !== null && !$target->valid) {
                Craft::warning(sprintf(
                    'A %s handler gave element %s the target %s, which names an undeclared collection or no formatter; it is not indexed.',
                    self::EVENT_RESOLVE_TARGET,
                    $element->id,
                    $target->getDescription(),
                ), TypesenseSync::HANDLE);
            }
        }

        return $target?->isIndexable() ? $target : null;
    }

    /**
     * Whether an element event should queue a sync or delete for this element (BR-1, BR-6).
     */
    public function shouldQueue(ElementInterface $element): bool
    {
        return $this->isSyncable($element) && $this->resolveTargetFor($element) !== null;
    }

    /**
     * A formatter for a target. A BaseFormatter is told its target, which is where its documents'
     * priority comes from.
     */
    public function formatterFor(ResolvedTarget $target): FormatterInterface
    {
        /** @var class-string<FormatterInterface> $class */
        $class = (string)$target->formatter;
        $formatter = Craft::createObject($class);

        if ($formatter instanceof BaseFormatter) {
            $formatter->setTarget($target);
        }

        return $formatter;
    }

    /**
     * The site a target indexes: the one it names, else the primary site. Null when it names a
     * site that does not exist.
     */
    public function siteFor(ResolvedTarget $target): ?Site
    {
        $sites = Craft::$app->getSites();

        return $target->site !== null ? $sites->getSiteByHandle($target->site) : $sites->getPrimarySite();
    }

    /**
     * Element types whose saves, deletes and restores are followed: entries, and categories and
     * users only while a source of that kind is declared, plus any a handler registers.
     *
     * @return array<int, class-string<ElementInterface>>
     */
    public function getElementTypes(): array
    {
        $types = [Entry::class];
        $kinds = array_map(static fn(SourceConfig $source) => $source->kind, $this->getSettings()->getSourceConfigs());

        if (in_array(SourceConfig::KIND_CATEGORY_GROUP, $kinds, true)) {
            $types[] = Category::class;
        }

        if (in_array(SourceConfig::KIND_USERS, $kinds, true)) {
            $types[] = User::class;
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_ELEMENT_TYPES)) {
            $event = new RegisterElementTypesEvent(['types' => $types]);
            $this->trigger(self::EVENT_REGISTER_ELEMENT_TYPES, $event);
            $types = $event->types;
        }

        return array_values(array_unique($types));
    }

    /**
     * The target the config declares for an element, before any handler has had a say.
     */
    private function declaredTargetFor(ElementInterface $element): ?ResolvedTarget
    {
        $settings = $this->getSettings();

        if ($element instanceof Entry) {
            // Craft 5 models Matrix blocks as entries with no section. They are never documents in
            // their own right; their text reaches search through the owner's keywords.
            $section = $element->getSection();

            if ($section === null) {
                return null;
            }

            return $settings->resolveTarget(SourceConfig::KIND_SECTION, (string)$section->handle, (string)$element->getType()->handle);
        }

        if ($element instanceof Category) {
            return $settings->resolveTarget(SourceConfig::KIND_CATEGORY_GROUP, (string)$element->getGroup()->handle);
        }

        if ($element instanceof User) {
            // Every user resolves to the one users source. Which users belong in search is the
            // formatter's shouldIndex(), so a user who stops qualifying is synced — and deleted —
            // rather than skipped.
            return $settings->resolveTarget(SourceConfig::KIND_USERS);
        }

        return null;
    }
}
