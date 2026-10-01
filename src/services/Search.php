<?php

namespace webdna\typesensesync\services;

use Craft;
use Throwable;
use webdna\typesensesync\events\DefineScopedKeyEvent;
use webdna\typesensesync\models\CollectionConfig;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\TypesenseSync;
use yii\base\Component;

/**
 * Scoped search keys: what a search page is handed instead of a real key (BR-17, BR-18).
 *
 * A scoped key is the search-only key plus embedded parameters, signed with it. Typesense applies
 * the embedded `filter_by` on top of whatever the browser asks for, and the browser cannot
 * override the embedded `exclude_fields` or `expires_at`. So the key, not the page, decides what
 * can be fetched. Its parameters are readable base64, so a key hides nothing about its filter.
 *
 * Every method answers null rather than throwing when the plugin is unconfigured, the collection
 * is unknown or the key cannot be made; the reason is logged to the `typesense-sync` category.
 *
 * @since 1.0.0
 */
class Search extends Component
{
    /**
     * Raised while a scoped key is being built, before it is signed (BR-18, BR-27).
     *
     * @see DefineScopedKeyEvent
     */
    public const EVENT_DEFINE_SCOPED_KEY = 'defineScopedKey';

    /**
     * Seconds a scoped key lasts when the caller does not say.
     */
    public const DEFAULT_TTL = 3600;

    /**
     * Embedded parameters the plugin sets itself; a caller's value for one is ignored.
     */
    public const RESERVED_PARAMS = ['filter_by', 'exclude_fields', 'expires_at'];

    /**
     * Caller parameters the plugin reads rather than embedding as they are.
     */
    private const OWN_PARAMS = ['filter', 'ttl', 'excludeFields'];

    private ?int $now = null;

    /**
     * Fixes the time keys are made at, for tests of the publication window and the TTL; null
     * returns to the real clock.
     */
    public function setNow(?int $now): void
    {
        $this->now = $now;
    }

    /**
     * The time keys are made at: the injected time, else now.
     */
    public function now(): int
    {
        return $this->now ?? time();
    }

    /**
     * The filter that keeps out content not yet published or already expired.
     *
     * Every document carries both dates, with a far-future sentinel for "none" (BR-11), so this
     * needs no handling for a missing date.
     */
    public function publicationWindow(?int $now = null): string
    {
        $now ??= $this->now();

        return "postDate:<=$now && expiryDate:>$now";
    }

    /**
     * The live (alias) name of a declared collection, or null when it is not declared or its
     * name resolves to nothing.
     */
    public function collectionName(string $handle): ?string
    {
        $collection = $this->collection($handle);

        return $collection === null ? null : $this->liveName($collection);
    }

    /**
     * What a search page needs to reach one collection: the server, the collection's live name,
     * and a scoped key, with the time that key stops working. Null when any of it cannot be had.
     *
     * The admin key is never part of this (BR-17).
     *
     * @param array<string, mixed> $params As for scopedKey().
     * @return array{host: string, port: int, protocol: string, collection: string, apiKey: string, expiresAt: int}|null
     */
    public function searchConfig(string $handle, array $params = []): ?array
    {
        $embedded = $this->scopedKeyParams($handle, $params);
        $collection = $this->collectionName($handle);

        if ($embedded === null || $collection === null) {
            return null;
        }

        $key = $this->sign($embedded);

        if ($key === null) {
            return null;
        }

        $settings = $this->settings();

        return [
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'protocol' => $settings->getProtocol(),
            'collection' => $collection,
            'apiKey' => $key,
            'expiresAt' => (int)$embedded['expires_at'],
        ];
    }

    /**
     * A scoped key for one collection, signed with the search-only key, or null.
     *
     * @param array<string, mixed> $params `filter` (a Typesense filter ANDed in), `ttl` (seconds,
     *        default 3600), `excludeFields` (fields to exclude on top of the collection's), and
     *        any other Typesense search parameter to embed as it is (`limit_hits`, …).
     */
    public function scopedKey(string $handle, array $params = []): ?string
    {
        $embedded = $this->scopedKeyParams($handle, $params);

        return $embedded === null ? null : $this->sign($embedded);
    }

    /**
     * The parameters a scoped key for the collection embeds (BR-18), or null when no key could be
     * made for it: the plugin is unconfigured, the collection is not declared, there is no
     * usable search-only key, or an EVENT_DEFINE_SCOPED_KEY handler refused it.
     *
     * `filter_by` ANDs the collection's publication window (unless its config turns it off), the
     * caller's filter and every filter added by EVENT_DEFINE_SCOPED_KEY, each in parentheses so an
     * `||` in one cannot widen another. `exclude_fields` is the collection's config plus the
     * caller's and the handlers' additions. `expires_at` is now + TTL.
     *
     * @param array<string, mixed> $params As for scopedKey().
     * @return array<string, mixed>|null
     */
    public function scopedKeyParams(string $handle, array $params = []): ?array
    {
        $collection = $this->collection($handle);

        if ($collection === null || $this->searchKey() === null) {
            return null;
        }

        $now = $this->now();

        $event = new DefineScopedKeyEvent([
            'collection' => $collection,
            'params' => $params,
            'now' => $now,
        ]);

        if ($this->hasEventHandlers(self::EVENT_DEFINE_SCOPED_KEY)) {
            $this->trigger(self::EVENT_DEFINE_SCOPED_KEY, $event);
        }

        if (!$event->isValid) {
            Craft::info(sprintf('A handler refused a scoped key for "%s"; none was made.', $handle), TypesenseSync::HANDLE);

            return null;
        }

        $filters = array_merge(
            $collection->publicationWindow ? [$this->publicationWindow($now)] : [],
            [$this->stringParam($params, 'filter', $handle)],
            $event->filters,
        );

        $embedded = [];

        foreach ($params as $name => $value) {
            if (in_array($name, self::OWN_PARAMS, true)) {
                continue;
            }

            if (in_array($name, self::RESERVED_PARAMS, true)) {
                Craft::warning(sprintf('A scoped key for "%s" was asked for its own %s, which the plugin sets; it was ignored.', $handle, $name), TypesenseSync::HANDLE);

                continue;
            }

            $embedded[$name] = $value;
        }

        $filter = $this->combine($filters);

        if ($filter !== '') {
            $embedded['filter_by'] = $filter;
        }

        $exclude = $this->fieldList(array_merge(
            $collection->excludeFields,
            (array)($params['excludeFields'] ?? []),
            $event->excludeFields,
        ));

        if ($exclude !== []) {
            $embedded['exclude_fields'] = implode(',', $exclude);
        }

        $embedded['expires_at'] = $now + $this->ttl($params);

        return $embedded;
    }

    /**
     * @param array<string, mixed> $embedded
     */
    private function sign(array $embedded): ?string
    {
        $key = $this->searchKey();
        $client = $this->client()->getClient();

        if ($key === null || $client === null) {
            return null;
        }

        try {
            return $client->keys->generateScopedSearchKey($key, $embedded);
        } catch (Throwable $e) {
            Craft::error('Could not make a scoped search key: ' . $e->getMessage(), TypesenseSync::HANDLE);

            return null;
        }
    }

    /**
     * The search-only key, or null when there is none — or when it is the admin key, since a key
     * signed with the admin key would carry admin rights into page HTML (BR-17).
     */
    private function searchKey(): ?string
    {
        $settings = $this->settings();
        $key = trim($settings->getSearchApiKey());

        if ($key === '') {
            Craft::warning('No search-only API key is set, so no scoped search key can be made.', TypesenseSync::HANDLE);

            return null;
        }

        if ($key === trim($settings->getApiKey())) {
            Craft::error('The search-only API key is the admin key; no scoped key is made from it.', TypesenseSync::HANDLE);

            return null;
        }

        return $key;
    }

    private function collection(string $handle): ?CollectionConfig
    {
        $settings = $this->settings();

        if (!$settings->isConfigured()) {
            return null;
        }

        $collection = $settings->getCollectionConfig($handle);

        if ($collection === null) {
            Craft::warning(sprintf('No Typesense collection "%s" is declared.', $handle), TypesenseSync::HANDLE);

            return null;
        }

        return $this->liveName($collection) === null ? null : $collection;
    }

    private function liveName(CollectionConfig $collection): ?string
    {
        try {
            return $collection->getName();
        } catch (Throwable $e) {
            Craft::warning($e->getMessage(), TypesenseSync::HANDLE);

            return null;
        }
    }

    /**
     * @param string[] $clauses
     */
    private function combine(array $clauses): string
    {
        $clauses = array_values(array_filter(array_map('trim', $clauses), fn(string $clause) => $clause !== ''));

        if (count($clauses) < 2) {
            return $clauses[0] ?? '';
        }

        return implode(' && ', array_map(fn(string $clause) => "($clause)", $clauses));
    }

    /**
     * @param array<mixed> $fields
     * @return string[]
     */
    private function fieldList(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            if (is_string($field) && trim($field) !== '') {
                $names[] = trim($field);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $name, string $handle): string
    {
        $value = $params[$name] ?? null;

        if ($value === null || is_string($value)) {
            return (string)$value;
        }

        Craft::warning(sprintf('A scoped key for "%s" was given a %s that is not a string; it was ignored.', $handle, $name), TypesenseSync::HANDLE);

        return '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function ttl(array $params): int
    {
        $ttl = $params['ttl'] ?? null;

        return is_numeric($ttl) && (int)$ttl > 0 ? (int)$ttl : self::DEFAULT_TTL;
    }

    private function settings(): Settings
    {
        return $this->plugin()->targets->getSettings();
    }

    private function client(): Client
    {
        return $this->plugin()->client;
    }

    private function plugin(): TypesenseSync
    {
        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        return $plugin;
    }
}
