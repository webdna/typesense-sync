<?php

namespace webdna\typesensesync\tests\Support;

use Typesense\Client as TypesenseClient;

/**
 * Clean-up shared by the integration tests: each writes under its own collection prefix.
 */
final class TestCollections
{
    /**
     * Delete every alias, then every collection, whose name starts with the prefix.
     */
    public static function deleteAll(TypesenseClient $admin, string $prefix): void
    {
        foreach ((array)($admin->aliases->retrieve()['aliases'] ?? []) as $alias) {
            if (str_starts_with((string)$alias['name'], $prefix)) {
                $admin->aliases[(string)$alias['name']]->delete();
            }
        }

        foreach ((array)$admin->collections->retrieve() as $collection) {
            if (str_starts_with((string)$collection['name'], $prefix)) {
                $admin->collections[(string)$collection['name']]->delete();
            }
        }
    }
}
