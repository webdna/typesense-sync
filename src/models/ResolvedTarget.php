<?php

namespace webdna\typesensesync\models;

use craft\base\Model;

/**
 * The effective indexing target for one concrete kind of element — a section plus entry type, a
 * category group, a product type, or users — after entry-type overrides are applied over the
 * source's defaults.
 *
 * Produced by SourceConfig::resolveFor(). The sync decides from it whether an element is indexed
 * at all, which formatter builds its document, and which collection it lands in.
 *
 * @since 1.0.0
 */
class ResolvedTarget extends Model
{
    public ?SourceConfig $source = null;

    /**
     * Entry type handle the target was resolved for, or null for the source default.
     */
    public ?string $entryType = null;

    public bool $enabled = true;

    /**
     * Collection handle.
     */
    public ?string $collection = null;

    /**
     * Formatter class name.
     */
    public ?string $formatter = null;

    /**
     * Ranking weight a formatter may write into the document; lower sorts first.
     */
    public int $priority = 100;

    /**
     * Site handle the target indexes, or null for the primary site.
     */
    public ?string $site = null;

    /**
     * False when validation found the target unusable — its collection is undeclared or its
     * formatter is missing — so it indexes nothing even though it is enabled (TN-3).
     */
    public bool $valid = true;

    /**
     * Whether elements of this target are sent to Typesense.
     */
    public function isIndexable(): bool
    {
        return $this->enabled
            && $this->valid
            && $this->collection !== null
            && $this->formatter !== null;
    }

    /**
     * An identifier for problems and logs, e.g. `section:news/article`.
     */
    public function getDescription(): string
    {
        $description = $this->source?->getDescription() ?? 'unknown';

        return $this->entryType !== null ? $description . '/' . $this->entryType : $description;
    }
}
