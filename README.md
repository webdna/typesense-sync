# Typesense Sync

Keep Craft content in a [Typesense](https://typesense.org) search index, and give search pages a
safe, filtered search key, by declaring what to index instead of writing a module.

- **The index follows the content.** Saving, disabling, deleting or restoring an element queues a
  sync. So do a user's status and group changes. Nothing is indexed unless you declare it.
- **Schema changes without downtime.** The utility shows how each collection differs from what
  your formatters declare. It applies the difference in place, or rebuilds the collection beside
  the live one and switches search over when the rebuild is complete, joined collections included.
- **Keys that can only read what the page may show.** `craft.typesense.searchConfig()` hands a
  search page a short-lived key. The key only searches, never returns pending or expired entries,
  and never returns the fields you mark private. The admin key never reaches a template.
- **Built in, off until you declare it:** users, categories, Craft Commerce products, search
  analytics (popular searches, searches with no results, view counters that survive reindexing),
  and collections that join to one another.

The plugin owns the machinery: queueing, batching, safe rebuilds and keys. Your site owns the
meaning: which content goes where, and what a result looks like.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [How the index follows content](#how-the-index-follows-content)
- [Configuration reference](#configuration-reference)
- [Writing a formatter](#writing-a-formatter)
- [Search pages](#search-pages)
- [Joined collections](#joined-collections)
- [Analytics](#analytics)
- [Control panel](#control-panel)
- [Console commands](#console-commands)
- [Extension points](#extension-points)
- [Several environments, one cluster](#several-environments-one-cluster)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- A Typesense server, **version 30.0 or later**, self-hosted or Typesense Cloud
- Craft Commerce 5, only to index products

## Installation

From the Plugin Store: search for "Typesense Sync" and install it.

With Composer:

```bash
composer require webdna/typesense-sync
php craft plugin/install typesense-sync
```

## Getting started

### 1. Connect

Go to **Settings → Plugins → Typesense Sync** and enter the server details. Every field accepts an
environment variable, and the settings are saved to project config as the `$VARIABLE` reference,
never as the value.

```dotenv
TYPESENSE_HOST="xyz.a1.typesense.net"
TYPESENSE_PORT="443"
TYPESENSE_PROTOCOL="https"
TYPESENSE_API_KEY="…"          # admin key: stays on the server
TYPESENSE_SEARCH_API_KEY="…"   # search-only key: signs the keys pages receive
TYPESENSE_PREFIX="prod_"       # optional: one cluster, several environments
```

Press **Test connection**. It reports the server version and checks the search-only key on the
server. A key that allows anything beyond `documents:search` is refused, because pages receive keys
derived from it. To make a search-only key:

```bash
curl "https://$TYPESENSE_HOST/keys" -X POST \
  -H "X-TYPESENSE-API-KEY: $TYPESENSE_API_KEY" -H "Content-Type: application/json" \
  -d '{"description": "Search only", "actions": ["documents:search"], "collections": ["*"]}'
```

The server returns the key's value once. Copy it straight into `.env`.

### 2. Declare what to index

Collections, sources and analytics are declared in `config/typesense-sync.php`, because they name
PHP classes. Copy the base example to start:

```bash
cp vendor/webdna/typesense-sync/examples/config/typesense-sync.php config/
mkdir -p modules/search/formatters
cp vendor/webdna/typesense-sync/examples/formatters/ContentFormatter.php modules/search/formatters/
```

Change the section handle `news` to one of yours. The example formatter uses the namespace
`modules\search\formatters`, which Craft's default `modules\` autoload already covers. The
[examples README](examples/README.md) lists every example config, formatter and search component.

```php
<?php

use modules\search\formatters\ContentFormatter;

return [
    'collections' => [
        'content' => [
            'defaultSortingField' => 'priority',
        ],
    ],
    'sources' => [
        [
            'kind' => 'section',
            'handle' => 'news',
            'collection' => 'content',
            'formatter' => ContentFormatter::class,
        ],
    ],
];
```

### 3. Set up

```bash
php craft typesense-sync/setup
```

`setup` validates the config, tests the connection, creates each collection and its alias, applies
any analytics rules, and indexes every declared element. It is safe to run again. Run it on each
environment after deploying a config change, or run `collections/apply` and `sync` yourself.

### 4. Search

```twig
{% set search = craft.typesense.searchConfig('content') %}
{% if search %}
  <div data-search="{{ search|json_encode|e('html_attr') }}"></div>
{% else %}
  <p>Search is unavailable.</p>
{% endif %}
```

`examples/alpine/` has a search box that uses this config.

## How the index follows content

| Change | What happens |
|---|---|
| An element of a declared source is saved, restored or deleted | A sync or delete job is queued. The save itself makes no network call. |
| A user is activated, deactivated, suspended, unsuspended, locked, unlocked or assigned to groups | A sync is queued, although no save event fires. This only happens while a users source is declared. |
| The same element is saved again while its job is waiting | Nothing more is queued. A save during a running job queues a fresh one. |
| A draft, a revision, a bulk resave, a propagation, or a nested entry with no section is saved | Nothing is queued. Use a reindex after a bulk resave. |
| An element of an undeclared section, group or type is saved | Nothing is queued, and nothing is sent. |

A sync job loads the element again and asks its formatter. A missing element, a disabled element, or
a formatter's `shouldIndex()` returning false removes the document. Craft's queue makes up to three
attempts at a job that cannot reach the server. A document Typesense rejects is logged and not
retried. **A save never fails because of Typesense.**

Pending and expired entries stay in the index with their `postDate` and `expiryDate`, and every
search key filters them out. No cron is needed for an entry to appear or expire on time.

Two things are not followed automatically:

- **Anything your documents read from other elements.** If a document shows its author's name, the
  entry is not re-synced when the author is saved. Call
  [`queueElement()`](#queue-an-element-yourself) from a listener of your own.
- **An entry moved into an undeclared section or entry type.** Its document stays until a reindex
  with prune.

## Configuration reference

**Craft silently ignores an unknown key in a plugin's config file.** This plugin reports every key
it does not recognise, at every level, in the utility and in `setup`. So a typo is caught rather
than quietly doing nothing. The keys below are the complete list.

Values marked *env* accept an environment variable (`'$TYPESENSE_PREFIX'`) or are resolved with
`App::env()`.

### Top level

The connection keys are normally set in the control panel. Any of them set here overrides the
control panel value.

| Key | Default | |
|---|---|---|
| `host` | `''` | *env.* Hostname, without the protocol. |
| `port` | `'443'` | *env.* |
| `protocol` | `'https'` | *env.* `http` or `https`. |
| `apiKey` | `''` | *env.* Admin key. Never passed to Twig, and shown in the control panel only as its last four characters. |
| `searchApiKey` | `''` | *env.* Search-only key. Signs every scoped key. |
| `collectionPrefix` | `''` | *env.* Prepended to each collection handle to make its live name. |
| `connectTimeout` | `2` | Seconds allowed to connect. |
| `timeout` | `5` | Seconds allowed for each request. |
| `numRetries` | `3` | Retries per request. |
| `queuePriority` | `1024` | Priority of the plugin's queue jobs. Lower runs sooner. |
| `batchSize` | `100` | Elements per import during a reindex. |
| `collections` | `[]` | **File only.** Collections, keyed by handle. |
| `sources` | `[]` | **File only.** A list of sources. |
| `analytics` | `[]` | **File only.** See [Analytics](#analytics). |

### `collections.<handle>`

| Key | Default | |
|---|---|---|
| `name` | prefix + handle | *env.* An explicit live name, e.g. to keep names a cluster already uses. |
| `defaultSortingField` | none | Changing it later needs a recreate. |
| `enableNestedFields` | `true` | Changing it later needs a recreate. |
| `schema` | `[]` | Typesense field definitions added to whatever the formatters declare, e.g. a field another process writes. |
| `counters` | `[]` | Field names whose values are read from the live collection and kept through every reindex. |
| `search` | | What every scoped key for this collection is limited to: |
| `search.publicationWindow` | `true` | Only documents with `postDate <= now` and `expiryDate > now`. |
| `search.excludeFields` | `[]` | Fields a search with this collection's keys never returns, whatever the browser asks. |

A collection's live name is always an **alias**. The physical collection behind it is
`<name>_1`, `<name>_2` and so on, which is how a rebuild happens beside the live version.

### `sources[]`

| Key | Default | |
|---|---|---|
| `kind` | `'section'` | `section`, `categoryGroup`, `users` or `productType`. |
| `handle` | | The section, category group or product type handle. Not used for `users`. |
| `collection` | | The collection handle it feeds. |
| `formatter` | | A class implementing `FormatterInterface`, usually extending `BaseFormatter`. |
| `enabled` | `true` | Switches the whole source. It accepts any PHP expression, e.g. `App::env('SEARCH_NEWS') ?? true`. |
| `priority` | `100` | Written to each document's `priority`. Lower sorts first. |
| `site` | primary site | The site handle whose version of each element is indexed. |
| `entryTypes` | `[]` | Sections only: overrides keyed by entry type handle. |

An `entryTypes.<handle>` override takes `enabled`, `collection`, `formatter` and `priority`. An
override can switch one entry type off, or route it to another collection or formatter. It cannot
switch a type on inside a disabled source.

A `productType` source is ignored with a warning, not an error, when Commerce is not installed.

### What is validated

The utility and `setup` list these as problems, and `setup` exits non-zero:

- an unknown key, anywhere
- a source naming an undeclared collection
- a formatter class that is missing or does not implement `FormatterInterface`
- two formatters in one collection declaring the same field differently
- two collections resolving to the same live name
- a reference field naming an undeclared collection, or references forming a cycle
- an analytics rule with an unknown type or an undeclared collection, or an events key equal to the
  admin key

## Writing a formatter

A formatter turns one element into one document and declares the fields its documents carry. Extend
`BaseFormatter` and implement `fields()`. Declare any extra fields by adding them to
`parent::schema()`.

```php
<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\elements\Entry;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

class ContentFormatter extends BaseFormatter
{
    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            ['name' => 'summary', 'type' => 'string', 'optional' => true],
            self::facet('section'),
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        return [
            'summary' => $this->plainText($element, 'summary', 300),
            'section' => $element instanceof Entry ? $element->getSection()?->name : null,
        ];
    }
}
```

**Every document carries** `id`, `title`, `type` (the entry type, product type or element type's
name), `url` (root-relative), `priority`, `postDate` and `expiryDate` (Unix seconds; "never" is
`BaseFormatter::FAR_FUTURE`, 253402300799), and `keywords`.

- **Make your own fields `optional`.** Several formatters can write into one collection, and no
  single document carries every field.
- **Null, `''` and `[]` are dropped** before indexing, so return values unconditionally. Typesense
  rejects null for a typed field.
- **Keep URLs root-relative.** `url()` already does. One cluster often serves several environments.
- `shouldIndex()` decides whether the element has a document at all. By default that is enabled
  and enabled for the site. Override it to exclude more; the example `UserFormatter` excludes
  inactive users.
- `documentId()` defaults to the element id. Override it only with a value that never changes for
  the element, because deletes are addressed by it.

Helpers on `BaseFormatter`, all safe on a field missing from the element's layout: `fieldValue()`,
`plainText()`, `firstPlainText()`, `optionValue()`, `optionLabel()`, `optionLabels()`,
`relatedTitles()`, `assetUrls()`, `firstAssetUrl()`, `number()`, `integer()`, `boolean()`,
`timestamp()`, `url()`, and `facet()` for a schema entry.

If you do not extend `BaseFormatter`, implement `FormatterInterface`:
`shouldIndex(ElementInterface): bool`, `format(ElementInterface): array` and
`schema(SchemaContext): array`. Your documents then need their own `postDate` and `expiryDate`, or
set `search.publicationWindow` to `false` for that collection.

## Search pages

`craft.typesense` in Twig, `TypesenseSync::getInstance()->search` in PHP:

| Method | Returns |
|---|---|
| `searchConfig(handle, params)` | `{host, port, protocol, collection, apiKey, expiresAt}` |
| `scopedKey(handle, params)` | The scoped key alone |
| `collectionName(handle)` | The live alias name |
| `analyticsConfig()` | What a page needs to post analytics events; see [Analytics](#analytics) |
| `viewCounts(ruleHandle, ids)` | View counts by document id |

**Each one returns `null` rather than throwing** when the plugin is unconfigured, the collection is
undeclared, or a key cannot be made. A page built before setup renders its own "unavailable" state.

`params` for a key:

| Param | |
|---|---|
| `filter` | A Typesense filter, ANDed with the collection's own. |
| `ttl` | Seconds the key lives. Default 3600. |
| `excludeFields` | Fields to exclude on top of the collection's `search.excludeFields`. |
| any other | Embedded as a Typesense search parameter, e.g. `limit_hits`. |

A scoped key embeds `filter_by`, `exclude_fields` and `expires_at`, and the browser cannot override
any of them. Each filter clause is parenthesised, so an `||` in one clause cannot widen another. The
key is signed with the search-only key, never the admin key.

- **A key's embedded parameters are readable.** Anyone can base64-decode them, so never put a
  secret in a filter.
- **A key is not limited to one collection.** Its filter is its isolation.
- **Page caches must not outlive the key.** A static or edge cache that keeps a page for longer than
  `ttl` serves an expired key, and search fails for anonymous visitors. Use `expiresAt` to set the
  cache lifetime, or fetch the config from an uncached endpoint.

## Joined collections

A formatter declares a reference into another collection through the `SchemaContext` it is given:

```php
['name' => 'authorId', 'type' => 'string', 'reference' => $context->reference('people'),
 'optional' => true, 'async_reference' => true, 'cascade_delete' => false],
```

A search can then include the referenced document's fields (`include_fields: '$people(title)'`).
Consider Typesense's defaults before you keep them: without `async_reference` a document is rejected
while the one it references is not yet indexed, and `cascade_delete` deletes the referencing
documents with it.

Typesense binds a reference to the *physical* collection, and a joined search names the *alias*.
So rebuilding a referenced collection alone breaks every join into it. **Recreate therefore rebuilds
the collection and everything that references it**, directly or not, in dependency order. It swaps
all the aliases only once every build has succeeded. Declare referenced collections before the ones
that reference them, so that `setup` creates them first. `examples/config/joins.php` is a complete
example.

## Analytics

The server must have analytics switched on. A self-hosted server is started with
`--enable-search-analytics=true --analytics-dir=…`. On Typesense Cloud it is a cluster setting.

```php
'analytics' => [
    'eventsKey' => '$TYPESENSE_EVENTS_KEY',
    'rules' => [
        'popular' => ['type' => 'popular_queries', 'collection' => 'content'],
        'noResults' => ['type' => 'nohits_queries', 'collection' => 'content'],
        'views' => ['type' => 'counter', 'collection' => 'content', 'counterField' => 'popularity'],
    ],
],
```

```bash
php craft typesense-sync/analytics/create-events-key   # once: prints the value to put in .env
php craft typesense-sync/analytics/apply
php craft typesense-sync/analytics/report --limit=20
```

| `analytics` key | Default | |
|---|---|---|
| `enabled` | `true` | Switches analytics off on an environment without touching the rules. |
| `eventsKey` | `''` | *env.* An events-only key, needed by counter rules. Must not be the admin key. |
| `ignoreQueries` | `['*', '']` | Queries never recorded. |
| `rules` | `[]` | Keyed by handle. |

| Rule key | Default | |
|---|---|---|
| `type` | `'popular_queries'` | `popular_queries`, `nohits_queries` or `counter`. |
| `collection` | | A declared collection handle. |
| `enabled` | `true` | |
| `destination` | live name + `_<handle>` | *env.* Query rules: the collection the counts are kept in. |
| `limit` | `1000` | Query rules: how many queries are kept. |
| `counterField` | `'popularity'` | Counter rules: the document field incremented. It is added to the schema and kept through every reindex. |
| `eventType` | `'click'` | Counter rules. |
| `weight` | `1` | Counter rules: added per event. |

A rule's name on the server is the collection's live name plus `_<handle>`. `apply` changes only what
differs, and running it twice changes nothing. `remove` deletes only the rules this config declares,
never one made by hand or by another environment. It keeps the collected counts.
`craft.typesense.analyticsConfig()` gives a page the events key and rule names, and
`examples/alpine/view-counter.js` posts one view per page load.

## Control panel

**Utilities → Typesense Sync** shows the connection, any configuration problems and warnings. For
each collection it shows the alias and live version, the document count and how the schema differs
from the formatters, with **Apply**, **Reindex** (optionally pruning documents no element builds)
and **Recreate** buttons. Recreate asks you to type the collection's handle to confirm. Reindex and
recreate run as queue jobs. When analytics is declared, the utility also shows each rule's state on
the server; apply rules with `analytics/apply`.

**Sync to Typesense** is an element index action and an edit-screen action menu item for declared
entries, categories, users and products.

The utility, its actions, the element action and the menu item all need the **Utilities → Typesense
Sync** permission (`utility:typesense-sync`).

## Console commands

| Command | |
|---|---|
| `typesense-sync/setup [--skip-sync]` | Validate, test, create collections, apply analytics, index |
| `typesense-sync/sync [--collection=h] [--prune] [--queue]` | Reindex one collection or all; `--queue` pushes jobs instead |
| `typesense-sync/sync/element <id> [--site=h]` | Sync one element now |
| `typesense-sync/sync/flush <collection> [--confirm=h]` | Delete every document, keeping the collection |
| `typesense-sync/collections/status` | Each collection's difference from its formatters |
| `typesense-sync/collections/apply [--collection=h] [--dry-run]` | Create or alter in place |
| `typesense-sync/collections/recreate --collection=h [--confirm=h]` | Rebuild beside the live version, then swap |
| `typesense-sync/analytics/status\|apply\|report\|create-events-key\|remove` | See [Analytics](#analytics); `--dry-run`, `--limit` |

Flush and recreate ask you to type the collection's handle, or take it as `--confirm=<handle>` in
scripts. A non-interactive run without `--confirm` is refused.

**Exit codes:** `0` success; `78` a configuration problem, or nothing declared; `64` bad usage (an
undeclared collection, a missing option, an unknown element or site); `1` anything else that failed
(the server, a wrong confirmation, a rejected document). A prune skipped because the run built no
documents exits `0` with a warning. The analytics commands exit `0` when no rules are declared, so a
deploy script can run them on every environment.

## Extension points

These are the plugin's public API, and follow semantic versioning.

### Queue an element yourself

```php
use webdna\typesensesync\TypesenseSync;

TypesenseSync::getInstance()->sync->queueElement($entry); // bool: whether a job was queued
```

Use it when a document depends on something saved elsewhere, such as an author's name shown on
their articles. It respects everything a save does: undeclared elements, drafts and duplicates queue
nothing.

### `Sync::EVENT_BEFORE_INDEX_DOCUMENT`

`webdna\typesensesync\events\IndexDocumentEvent`: `element`, `target` (`ResolvedTarget`) and
`document` (array). Raised for every document on its way to Typesense, in a single sync and in a
reindex. Change `$event->document` to alter what is written. The `id` is always put back to the
formatter's `documentId()`. **Cancellable:** set `$event->isValid = false` to keep the element out
of the index. A single sync then deletes its document, and a reindex skips it.

```php
use webdna\typesensesync\events\IndexDocumentEvent;
use webdna\typesensesync\services\Sync;
use yii\base\Event;

Event::on(Sync::class, Sync::EVENT_BEFORE_INDEX_DOCUMENT, function(IndexDocumentEvent $event) {
    $event->document['boost'] = $event->element->featured ? 10 : 0;
});
```

### `Sync::EVENT_AFTER_SYNC`

`webdna\typesensesync\events\SyncEvent`: `element`, `target`, `outcome` (`Sync::OUTCOME_INDEXED`,
`OUTCOME_REMOVED`, `OUTCOME_REJECTED` or `OUTCOME_SKIPPED`) and `document` (what was written, or
null). Raised after each single-element sync, but not for each element of a reindex.

### `Sync::EVENT_AFTER_DELETE_DOCUMENT`

`webdna\typesensesync\events\DeleteDocumentEvent`: `collection` (the live name) and `documentId`.
Raised after every delete, including one that found nothing to delete.

### `Targets::EVENT_RESOLVE_TARGET`

`webdna\typesensesync\events\ResolveTargetEvent`: `element` and `target`, a `ResolvedTarget` or
null. Raised whenever the plugin decides where an element goes. Set `target` to null to keep an
element out, or change its `collection`, `formatter` or `priority`. Whatever you leave is checked
again, so an element cannot be routed into an undeclared collection. While a handler is attached, a
reindex asks it about every element too.

```php
use webdna\typesensesync\events\ResolveTargetEvent;
use webdna\typesensesync\services\Targets;

Event::on(Targets::class, Targets::EVENT_RESOLVE_TARGET, function(ResolveTargetEvent $event) {
    if ($event->element->getFieldValue('hideFromSearch')) {
        $event->target = null;
    }
});
```

### `Targets::EVENT_REGISTER_ELEMENT_TYPES`

`webdna\typesensesync\events\RegisterElementTypesEvent`: `types`, the element classes whose save,
delete and restore events the plugin listens to. `Entry` is always there, and `Category`, `User` and
Commerce's `Product` appear while a source of their kind is declared. Add a class so that its saves
queue a sync, then route its elements with `EVENT_RESOLVE_TARGET`. Two limits apply to elements
that no source declares. A reindex does not walk them, so call `queueElement()` for each one to
fill a new collection. And the collection's schema comes from its sources' formatters and its
`schema` key, so declare their fields in one of those.

### `Search::EVENT_DEFINE_SCOPED_KEY`

`webdna\typesensesync\events\DefineScopedKeyEvent`: `collection` (`CollectionConfig`), `params` (the
caller's), `now`, `filters` and `excludeFields`. Raised before each key is signed. Add to
`filters` and `excludeFields` to narrow the key. A handler cannot remove the publication window or
the collection's excluded fields, because those are applied on top.

```php
use webdna\typesensesync\events\DefineScopedKeyEvent;
use webdna\typesensesync\services\Search;

Event::on(Search::class, Search::EVENT_DEFINE_SCOPED_KEY, function(DefineScopedKeyEvent $event) {
    if ($event->collection->handle === 'people') {
        $event->filters[] = 'status:=active';
    }
});
```

### Services

On `TypesenseSync::getInstance()`: `client`, `targets`, `sync`, `collections`, `search` and
`analytics`. The methods named in this README are public API. Other public methods may change in a
minor release.

## Several environments, one cluster

- Give each environment its own `collectionPrefix` (`dev_`, `staging_`, `prod_`), or an explicit
  env-aware `name` per collection.
- Documents hold root-relative URLs, so a document indexed by one environment links correctly from
  another.
- Analytics rule names follow the live name, so each environment's rules are its own, and `remove`
  never touches another's.

## Troubleshooting

- **Nothing is indexed.** Is the queue running? Is the element in a declared source, with an
  enabled entry type? Open the utility and look for problems first.
- **A field is missing from results.** Run `collections/status`, then `collections/apply`, then
  `sync --collection=<handle>`, because an alter changes the schema but not existing documents.
- **"Nothing enabled routes into …"**: every source for that collection is switched off or
  undeclared.
- **Search works for editors but not for visitors.** A page cache is probably serving an expired key.
  See [Search pages](#search-pages).
- **Logs.** Everything is logged to Craft's logs under the category `typesense-sync`, with the
  document ids involved.

## Uninstalling

Uninstalling removes the plugin's settings and stops the syncing. **It does not delete anything on
the Typesense server.** The collections may be serving another environment. To remove them, first
remove the analytics rules while the plugin is still installed:

```bash
php craft typesense-sync/analytics/remove
```

Then delete each alias and its versioned collections, analytics destinations included, with the
admin key:

```bash
curl -X DELETE "https://$TYPESENSE_HOST/aliases/prod_content"      -H "X-TYPESENSE-API-KEY: $TYPESENSE_API_KEY"
curl -X DELETE "https://$TYPESENSE_HOST/collections/prod_content_1" -H "X-TYPESENSE-API-KEY: $TYPESENSE_API_KEY"
```

`GET /aliases` and `GET /collections` list what is there. Delete the search-only key and the events
key through `/keys` if nothing else uses them.

## Support

Report issues on [GitHub](https://github.com/webdna/typesense-sync/issues). Typesense Sync is
licensed under the [Craft License](LICENSE.md) and made by [webdna](https://webdna.co.uk).
