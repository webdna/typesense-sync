<?php

namespace webdna\typesensesync\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Everything the plugin knows about Craft Commerce, in one place.
 *
 * The plugin has no dependency on Commerce (BR-5), so Commerce's classes are named here as
 * strings and never imported: nothing is autoloaded, and nothing fails, on a site without it.
 *
 * @since 1.0.0
 */
final class Commerce
{
    /**
     * The plugin handle Commerce installs under.
     */
    public const HANDLE = 'commerce';

    /**
     * Commerce's product element.
     */
    public const PRODUCT_CLASS = 'craft\\commerce\\elements\\Product';

    /**
     * Whether Commerce is installed and enabled. A product type source counts only while it is.
     */
    public static function isInstalled(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled(self::HANDLE) && class_exists(self::PRODUCT_CLASS);
    }

    /**
     * Commerce's product class, or null when it cannot be loaded.
     *
     * @return class-string<ElementInterface>|null
     */
    public static function productClass(): ?string
    {
        $class = self::PRODUCT_CLASS;

        return is_subclass_of($class, ElementInterface::class) ? $class : null;
    }

    public static function isProduct(ElementInterface $element): bool
    {
        return is_a($element, self::PRODUCT_CLASS);
    }

    /**
     * A product's type handle and name, or null for anything that is not a product.
     *
     * @return array{handle: string, name: string}|null
     */
    public static function productTypeOf(ElementInterface $element): ?array
    {
        if (!self::isProduct($element) || !method_exists($element, 'getType')) {
            return null;
        }

        $type = $element->getType();

        return ['handle' => (string)$type->handle, 'name' => (string)$type->name];
    }

    /**
     * A query for every product of one type, or null when Commerce is not installed.
     */
    public static function productQuery(string $productType): ?ElementQueryInterface
    {
        $class = self::productClass();

        if ($class === null || !self::isInstalled()) {
            return null;
        }

        $query = $class::find();

        return method_exists($query, 'type') ? $query->type($productType) : null;
    }
}
