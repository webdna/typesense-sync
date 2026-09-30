<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * Example formatter for Craft Commerce products (`examples/config/products.php`). Needs Commerce;
 * the plugin itself does not.
 *
 * One document per product, not per variant: the price is the product's default price, and the
 * SKU and availability are its default variant's. A variant saved on its own (a price or stock
 * change) is not followed, so anything read from a variant is as fresh as the product's last
 * save or the last reindex. Schedule `craft typesense-sync/sync --collection=products` if stock
 * must stay current in search.
 */
class ProductFormatter extends BaseFormatter
{
    protected const SUMMARY_FIELDS = ['summary', 'description'];

    protected const IMAGE_FIELDS = ['image', 'images'];

    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            ['name' => 'summary', 'type' => 'string', 'optional' => true],
            ['name' => 'image', 'type' => 'string', 'index' => false, 'optional' => true],
            ['name' => 'sku', 'type' => 'string', 'optional' => true],
            // A facet as well as sortable, so a price-range widget can read its bounds.
            self::metricFacet('price'),
            self::facet('available', 'bool'),
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        if (!$element instanceof Product) {
            return [];
        }

        $variant = $element->getDefaultVariant();

        return [
            'summary' => $this->firstPlainText($element, static::SUMMARY_FIELDS),
            'image' => $this->firstAssetUrl($element, static::IMAGE_FIELDS),
            'sku' => $variant?->getSku(),
            'price' => $element->getDefaultPrice(),
            'available' => $variant?->getIsAvailable(),
        ];
    }
}
