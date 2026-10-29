<?php

namespace webdna\typesensesync\tests\Support;

use Typesense\Client as TypesenseClient;

/**
 * Clean-up shared by the integration tests: each writes under its own collection prefix.
 */
final class TestCollections
{
    /**
     * Delete every alias, collection, curation set and synonym set whose name starts with the
     * prefix. A set is never deleted while linked (BR-12): the prefix's collections go first, and
     * any other collection still linking one of its sets is unlinked before the set is deleted.
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

        $ours = static fn(mixed $name): bool => str_starts_with((string)$name, $prefix);

        foreach ((array)$admin->collections->retrieve() as $collection) {
            $patch = [];

            foreach (['curation_sets', 'synonym_sets'] as $key) {
                $linked = (array)($collection[$key] ?? []);
                if (array_filter($linked, $ours) !== []) {
                    $patch[$key] = array_values(array_filter($linked, fn($name) => !$ours($name)));
                }
            }

            if ($patch !== []) {
                $admin->collections[(string)$collection['name']]->update($patch);
            }
        }

        foreach ((array)$admin->curationSets->retrieve() as $set) {
            if ($ours($set['name'] ?? '')) {
                $admin->curationSets[(string)$set['name']]->delete();
            }
        }

        foreach ((array)$admin->synonymSets->retrieve() as $set) {
            if ($ours($set['name'] ?? '')) {
                $admin->synonymSets[(string)$set['name']]->delete();
            }
        }
    }
}
