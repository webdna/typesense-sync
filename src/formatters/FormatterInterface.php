<?php

namespace webdna\typesensesync\formatters;

use craft\base\ElementInterface;

/**
 * Turns one element into one Typesense document, and says which fields its collection needs.
 *
 * A site writes one formatter per kind of result and names it in `config/typesense-sync.php`.
 * The plugin decides when a document is written or deleted; the formatter decides only what it
 * holds.
 *
 * @since 1.0.0
 */
interface FormatterInterface
{
    /**
     * Whether the element belongs in search at all. False deletes its document.
     */
    public function shouldIndex(ElementInterface $element): bool;

    /**
     * The document for the element, keyed by field name.
     *
     * @return array<string, mixed>
     */
    public function format(ElementInterface $element): array;

    /**
     * The Typesense field definitions this formatter's documents need.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schema(SchemaContext $context): array;
}
