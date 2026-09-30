<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\elements\Entry;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * Example formatter for entries: the base document plus a summary, an image and the section.
 *
 * Copy to `modules/search/formatters/ContentFormatter.php` (or wherever your site's modules
 * autoload from, changing the namespace to match) and name it in `config/typesense-sync.php`.
 *
 * BaseFormatter already gives every document `title`, `type` (the entry type's name), a
 * root-relative `url`, `priority`, `postDate`, `expiryDate` and `keywords` (every field the
 * layout marks searchable). Add only what a result needs beyond that.
 */
class ContentFormatter extends BaseFormatter
{
    /**
     * Tried in order; the first with text wins. Entry types often name the same idea
     * differently, and a handle missing from a layout simply reads as empty.
     */
    protected const SUMMARY_FIELDS = ['summary', 'intro', 'description', 'body'];

    protected const IMAGE_FIELDS = ['featuredImage', 'image', 'images'];

    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            // Optional: several formatters can share a collection, and not every entry has one.
            ['name' => 'summary', 'type' => 'string', 'optional' => true],
            // Shown, never searched.
            ['name' => 'image', 'type' => 'string', 'index' => false, 'optional' => true],
            self::facet('section'),
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        return [
            'summary' => $this->firstPlainText($element, static::SUMMARY_FIELDS),
            'image' => $this->firstAssetUrl($element, static::IMAGE_FIELDS),
            'section' => $element instanceof Entry ? $element->getSection()?->name : null,
        ];
    }
}
