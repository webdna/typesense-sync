<?php

namespace webdna\typesensesync\formatters;

use webdna\typesensesync\models\CollectionConfig;

/**
 * What a formatter's `schema()` may ask about the collections around it.
 *
 * A reference field has to name the live (aliased) name of the collection it joins to, which
 * differs per environment. A formatter names the collection by its handle and asks for the
 * reference here, so the join follows whatever name the environment resolves.
 *
 * @since 1.0.0
 */
class SchemaContext
{
    /**
     * @param string $collection Handle of the collection the schema is for.
     * @param array<string, string> $liveNames Live name of every declared collection, by handle.
     * @param string $typeField The collection's `typeField`: where the document type is written.
     */
    public function __construct(
        private readonly string $collection,
        private readonly array $liveNames,
        private readonly string $typeField = CollectionConfig::DEFAULT_TYPE_FIELD,
    ) {
    }

    /**
     * The field the collection's documents carry their type in: `type` unless the collection
     * names another with `typeField`.
     */
    public function getTypeField(): string
    {
        return $this->typeField;
    }

    /**
     * Handle of the collection the schema is being built for.
     */
    public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * The value of a field's `reference` key for a join into another collection, e.g.
     * `['name' => 'authorId', 'type' => 'string', 'reference' => $context->reference('people')]`.
     *
     * An undeclared handle is returned unresolved, so validation can name it as a problem.
     */
    public function reference(string $collection, string $field = 'id'): string
    {
        return ($this->liveNames[$collection] ?? $collection) . '.' . $field;
    }
}
