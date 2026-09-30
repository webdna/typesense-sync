<?php

namespace webdna\typesensesync\models;

use craft\base\Model;

/**
 * One kind of Craft content declared as feeding a collection: a section, a category group, a
 * Commerce product type, or users.
 *
 * A section may carry per-entry-type overrides, each able to change the formatter, the
 * collection, the priority and whether that type indexes at all — so one section can feed two
 * collections by configuration alone.
 *
 * @since 1.0.0
 */
class SourceConfig extends Model
{
    public const KIND_SECTION = 'section';
    public const KIND_CATEGORY_GROUP = 'categoryGroup';
    public const KIND_PRODUCT_TYPE = 'productType';

    /**
     * Craft users. There is one users source and it has no handle; which users qualify is its
     * formatter's decision.
     */
    public const KIND_USERS = 'users';

    public const KINDS = [
        self::KIND_SECTION,
        self::KIND_CATEGORY_GROUP,
        self::KIND_PRODUCT_TYPE,
        self::KIND_USERS,
    ];

    /**
     * Keys a source may carry in `config/typesense-sync.php`.
     */
    public const KEYS = ['kind', 'handle', 'enabled', 'collection', 'formatter', 'priority', 'site', 'entryTypes'];

    /**
     * Keys an entry-type override may carry.
     */
    public const OVERRIDE_KEYS = ['enabled', 'collection', 'formatter', 'priority'];

    public string $kind = self::KIND_SECTION;

    /**
     * Section, category group or product type handle; empty for users.
     */
    public string $handle = '';

    /**
     * Declaring a source is the intent to index it, so it is on unless switched off. The config
     * file is PHP, so this may be any expression.
     */
    public bool $enabled = true;

    /**
     * Collection handle.
     */
    public ?string $collection = null;

    /**
     * Formatter class name.
     */
    public ?string $formatter = null;

    public int $priority = 100;

    /**
     * Site handle to index; null means the primary site.
     */
    public ?string $site = null;

    /**
     * Per-entry-type overrides keyed by entry type handle; keys per OVERRIDE_KEYS.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $entryTypes = [];

    /**
     * The effective target for an entry type, applying its override over this source's defaults.
     * Pass null for the source default, which is also what a source with no sub-type resolves to.
     */
    public function resolveFor(?string $entryType = null): ResolvedTarget
    {
        $override = ($entryType !== null && isset($this->entryTypes[$entryType]))
            ? $this->entryTypes[$entryType]
            : [];

        return new ResolvedTarget([
            'source' => $this,
            'entryType' => $entryType,
            'enabled' => $this->enabled && (bool)($override['enabled'] ?? true),
            'collection' => $override['collection'] ?? $this->collection,
            'formatter' => $override['formatter'] ?? $this->formatter,
            'priority' => (int)($override['priority'] ?? $this->priority),
            'site' => $this->site,
        ]);
    }

    /**
     * Every target the source can produce: the default, then one per entry-type override.
     *
     * @return ResolvedTarget[]
     */
    public function resolveAll(): array
    {
        $targets = [$this->resolveFor()];

        foreach (array_keys($this->entryTypes) as $entryType) {
            $targets[] = $this->resolveFor((string)$entryType);
        }

        return $targets;
    }

    /**
     * An identifier for problems and logs, e.g. `section:news` or `users`.
     */
    public function getDescription(): string
    {
        return $this->kind === self::KIND_USERS ? self::KIND_USERS : $this->kind . ':' . $this->handle;
    }
}
