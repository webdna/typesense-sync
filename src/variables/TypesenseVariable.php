<?php

namespace webdna\typesensesync\variables;

use Craft;
use Throwable;
use webdna\typesensesync\TypesenseSync;

/**
 * `craft.typesense` — what a search page asks for.
 *
 * Every method returns null when the plugin is unconfigured, the collection is unknown or the key
 * cannot be made, and never throws: a search page built before setup renders its own "search
 * unavailable" state instead of an error page (BR-20). The admin key is never reachable from
 * here (BR-17).
 *
 * @since 1.0.0
 */
class TypesenseVariable
{
    /**
     * `{host, port, protocol, collection, apiKey, expiresAt}` for one collection, the key scoped
     * to it.
     *
     * @param array<string, mixed> $params `filter`, `ttl`, `excludeFields`, or any other search
     *        parameter to embed in the key.
     * @return array<string, mixed>|null
     */
    public function searchConfig(string $handle, array $params = []): ?array
    {
        return $this->guard(fn(TypesenseSync $plugin) => $plugin->search->searchConfig($handle, $params));
    }

    /**
     * A scoped key alone, for a page that already has the rest.
     *
     * @param array<string, mixed> $params As for searchConfig().
     */
    public function scopedKey(string $handle, array $params = []): ?string
    {
        return $this->guard(fn(TypesenseSync $plugin) => $plugin->search->scopedKey($handle, $params));
    }

    /**
     * The collection's live (alias) name.
     */
    public function collectionName(string $handle): ?string
    {
        return $this->guard(fn(TypesenseSync $plugin) => $plugin->search->collectionName($handle));
    }

    /**
     * @template T
     * @param callable(TypesenseSync): T $call
     * @return T|null
     */
    private function guard(callable $call): mixed
    {
        $plugin = TypesenseSync::getInstance();

        if ($plugin === null) {
            return null;
        }

        try {
            return $call($plugin);
        } catch (Throwable $e) {
            Craft::error('craft.typesense failed: ' . $e->getMessage(), TypesenseSync::HANDLE);

            return null;
        }
    }
}
