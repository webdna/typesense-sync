<?php

namespace webdna\typesensesync\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use yii\base\InvalidConfigException;

/**
 * One Typesense collection, as declared in `config/typesense-sync.php`.
 *
 * The handle (`content`) is stable and is what sources route to. The live name is the explicit,
 * env-aware `name` when one is given, else the collection prefix plus the handle (BR-3). The live
 * name is always an alias, pointing at a versioned physical collection (`<name>_1`, `_2`, …), so
 * a rebuild can fill the next version while the current one keeps answering.
 *
 * @since 1.0.0
 */
class CollectionConfig extends Model
{
    /**
     * Keys a collection may carry in `config/typesense-sync.php`.
     */
    public const KEYS = ['name', 'typeField', 'schema', 'defaultSortingField', 'enableNestedFields', 'counters', 'search'];

    /**
     * The field a BaseFormatter writes the document type into unless the collection names another.
     */
    public const DEFAULT_TYPE_FIELD = 'type';

    /**
     * The other fields a BaseFormatter writes on every document, which the type cannot share.
     */
    public const BASE_FIELDS = ['id', 'title', 'url', 'priority', 'postDate', 'expiryDate', 'keywords'];

    /**
     * Keys the collection's `search` array may carry.
     */
    public const SEARCH_KEYS = ['publicationWindow', 'excludeFields'];

    public string $handle = '';

    /**
     * Explicit live name, or an `$ENV_VAR` reference to one. Null derives it from the prefix.
     */
    public ?string $name = null;

    /**
     * Collection prefix from the plugin settings, already resolved.
     */
    public string $prefix = '';

    /**
     * The field a BaseFormatter writes the document type into. A collection names another when
     * its documents already use `type` for something else. Not env-aware: it changes the schema.
     */
    public string $typeField = self::DEFAULT_TYPE_FIELD;

    /**
     * Field definitions added on top of the union of the formatters' schemas.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $schema = [];

    /**
     * Typesense `default_sorting_field`. An alter cannot change it; only a recreate can.
     */
    public ?string $defaultSortingField = null;

    /**
     * Typesense `enable_nested_fields`.
     */
    public bool $enableNestedFields = true;

    /**
     * Fields whose live values a reindex carries over rather than resetting (BR-14).
     *
     * @var string[]
     */
    public array $counters = [];

    /**
     * Whether a scoped key limits this collection to its publication window (BR-18).
     */
    public bool $publicationWindow = true;

    /**
     * Fields a scoped key never returns (BR-18).
     *
     * @var string[]
     */
    public array $excludeFields = [];

    /**
     * Whether a live name can be resolved: an explicit name must not resolve to empty.
     */
    public function hasName(): bool
    {
        return $this->name === null || $this->name === '' || $this->resolvedName() !== '';
    }

    /**
     * The live (alias) name that search uses.
     *
     * @throws InvalidConfigException if an explicit name resolves to nothing.
     */
    public function getName(): string
    {
        if ($this->name === null || $this->name === '') {
            return $this->prefix . $this->handle;
        }

        $name = $this->resolvedName();

        if ($name === '') {
            throw new InvalidConfigException(Craft::t('typesense-sync', 'Typesense collection "{collection}" is named "{name}", which resolves to nothing.', [
                'collection' => $this->handle,
                'name' => (string)$this->name,
            ]));
        }

        return $name;
    }

    /**
     * The physical collection behind the alias for a version, e.g. `content_2`.
     */
    public function getVersionedName(int $version): string
    {
        return $this->getName() . '_' . $version;
    }

    /**
     * The version encoded in a physical collection name, or null if the name is not one of this
     * collection's versions.
     */
    public function getVersionFromName(string $name): ?int
    {
        $pattern = '/^' . preg_quote($this->getName(), '/') . '_(\d+)$/';

        return preg_match($pattern, $name, $matches) ? (int)$matches[1] : null;
    }

    private function resolvedName(): string
    {
        return trim((string)App::parseEnv((string)$this->name));
    }
}
