<?php

/**
 * Snippet: Craft Commerce products of one product type. Add the collection and the source to your
 * `config/typesense-sync.php`, and copy `examples/formatters/ProductFormatter.php` into your site.
 *
 * The plugin does not depend on Commerce (BR-5). Without it, a product type source is ignored and
 * the utility and `setup` show a warning, not an error, so this snippet can sit in a config shared
 * by sites with and without a shop. Declare one source per product type to index.
 */

use modules\search\formatters\ProductFormatter;

return [
    'collections' => [
        'products' => [
            'defaultSortingField' => 'priority',
        ],
    ],
    'sources' => [
        [
            'kind' => 'productType',
            'handle' => 'clothing',
            'collection' => 'products',
            'formatter' => ProductFormatter::class,
        ],
    ],
];
