---
spec: Typesense Sync
slug: typesense-sync
status: draft
version: 0.1
date: 2026-09-30
author: Claude (with Sam Birch)
client: webdna (Craft Plugin Store product)
approver: Sam Birch
profile: _PROFILE.typesense-sync.md
related: []
---

# Typesense Sync

> **Status:** draft - **Version:** 0.1 - **Profile:** `_PROFILE.typesense-sync.md`
> A Craft site can keep its content in a Typesense search index, and give its search page a safe,
> filtered search key, by declaring what to index instead of writing a module.

---

## 1. How it works

A developer installs the plugin, enters the search server's details in the control panel, and
declares in one configuration file which content goes into which search collection and how each
item is shaped. From then on the index follows the content: when an editor saves, disables,
deletes or restores something, the change reaches search within moments, and when a developer
changes what a collection holds, the control panel shows the difference and applies it, rebuilding
the collection alongside the live one so search never goes down. A search page asks the plugin
for its connection details and gets a short-lived key that can only read what that page is allowed
to show — never unpublished or expired content, never fields marked private.

The idea it rests on: **the plugin owns the machinery, the site owns the meaning.** Every webdna site
that uses Typesense has rebuilt the same machinery — queueing, batching, safe rebuilds, keys — five
times, each a copy of the last with its own bugs. What differs between sites is only which content
is indexed and what a search result looks like. So those are the two things a site writes (a list of
sources and one small class per kind of result); everything else is the plugin's, tested once.

It is extracted from the most complete of the five, the one built for Legacy Luxury Lifestyle, with
everything specific to that site left behind. That site is not changed by this work; it can move onto
the plugin later, and this spec proves on paper that it could (§6, migration map).

Optional parts — indexing user accounts, indexing shop products, search analytics, and collections
that join to one another — are built in but do nothing until a site declares them. The plugin ships
copy-in example configurations for each, so a site takes only what it needs.

**Rejected alternatives.** Configuring collections in the control panel (as the existing third-party
plugin does) was rejected: what a result looks like has to be code, so half the setup would live in
the database and half in code. Shipping ready-made search widgets was rejected: every site's search
interface is different; widgets ship as unsupported examples instead. A free Lite edition with a
paid Pro was rejected for 1.0: it puts licence checks inside the sync path.

**Vocabulary**

| Term | Means |
|---|---|
| Collection | One searchable index on the Typesense server, e.g. "content" or "products". |
| Source | A kind of Craft content declared as feeding a collection — a section, a category group, users, or a product type. |
| Formatter | The small piece of site code that turns one item of content into one search result, and says which fields the collection has. |
| Document | One search result as stored in a collection. |
| Reindex | Re-sending every declared item to its collection. |
| Recreate | Building a fresh copy of a collection beside the live one and switching search over to it when complete. |
| Scoped key | A search key made for one page: it can only search, only for a limited time, and only within a filter the site chose. |

---

## 2. Scope

**In scope**

- Connection settings in the control panel, with environment variables, and a connection test
- Declaring collections and sources in a configuration file, with validation and clear problems
- Keeping the index in step with content: save, disable, delete, restore, and account status changes
- Reindexing, pruning removed content, and rebuilding a collection without search going down
- Collections that join to one another, rebuilt in the right order
- Short-lived, filtered search keys, and a template helper that hands a search page everything it needs
- Search analytics: popular searches, searches with no results, and view counters that survive reindexing
- A control-panel utility, an element action, and command-line tools
- Example configurations, example result classes and example search components to copy in
- Everything the Craft Plugin Store requires to list a paid plugin

**Out of scope**

| Not doing | Why |
|---|---|
| Moving Legacy Luxury Lifestyle onto the plugin | A separate piece of work on a live site; this spec only proves it is possible |
| Moving the other four hand-built sites, or the four sites on the third-party plugin | New sites only (Sam, 30 Sep 2026) |
| Supported front-end search widgets | Every site's search UI differs; examples are copy-in and unsupported |
| One result per site on multi-site installs | No new site needs it yet; the design leaves one seam for it |
| Commerce variants as their own results | Only one existing site needs it, and it is not moving |
| Vector / semantic search fields | Only one existing site uses it; a site can still declare the fields itself |
| Craft 4 | The one Craft 4 site is not moving; the Store listing is Craft 5 |
| Typesense servers older than 30 | One API shape to build and test against |
| Editing collections in the control panel | See §1, rejected alternatives |
| Synonyms and curation rules | No surveyed site uses them from Craft |

**Later**

- One result per site for multi-site installs
- Commerce variants as a source
- A Lite edition, if the Store listing suggests demand

---

## 3. User journeys

**Who this is for**

| Group | What they get |
|---|---|
| Developers building a Craft site | Search indexing by declaration instead of a hand-built module |
| Site editors | Nothing to learn: content they publish becomes searchable, content they remove stops being |
| Site administrators | A utility showing each collection's health, with sync and rebuild buttons |
| Site visitors | Search that never shows unpublished, expired or private content |
| webdna (affected) | A paid product to support: releases, a changelog, Store listing, customer questions |

**1. Set up a site**
   1. The developer installs the plugin from the Plugin Store.
   2. They open its settings and enter the server address and keys, as environment variables.
   3. They press *Test connection* and see the server version and "Connected".
   4. They copy the base example configuration, name a section and a result class, and run setup.
   5. Setup reports each collection created and how many items were indexed.

**2. Edit content**
   1. An editor publishes an entry in a declared section; within moments it can be found.
   2. They disable it, or delete it; it stops being found.
   3. They restore it; it can be found again.
   4. An entry in a section nobody declared is never sent to search.

**3. Change what a collection holds**
   1. The developer adds a field to a result class.
   2. The utility shows that collection as out of date, with the difference listed.
   3. They apply it, or choose *Recreate*, type the collection's name to confirm, and search keeps
      answering throughout; when it finishes the utility shows the new version live.

**4. Build a search page**
   1. The developer asks the template helper for a collection's search configuration.
   2. They pass it to their search component (or an example one) and results appear.
   3. The page source holds a key that expires within the hour and can only search.

**5. Watch search behaviour** *(optional)*
   1. The developer copies the analytics example and runs the analytics setup.
   2. After visitors search, the report shows the most popular searches and those with no results.
   3. A view counter on results keeps its numbers through every reindex.

**First run - the empty state.** Before any settings are saved, the utility shows one message: the
plugin is not connected, with a link to its settings. Connected but with nothing declared, it shows
"No collections declared" and points to the example configurations. The template helper returns
nothing rather than erroring, so a search page built before setup renders its own "search
unavailable" state instead of breaking the site.

---

## 4. Data model

**No new database tables.** The plugin stores nothing about people.

| Stored | Where | Notes |
|---|---|---|
| Connection settings | Project config (plugin settings) | Kept as `$ENV_VAR` references, never resolved values |
| Collections, sources, analytics | `config/typesense-sync.php` | Code, not content; names formatter classes |
| Pending-sync flag | Craft cache, key per element and site | Dedupe only; losing it costs at most one duplicate job |
| Documents | Typesense | Rebuildable from Craft at any time |

**Why this shape.** Everything in Typesense is derived from Craft and can be rebuilt by a reindex, so
nothing the plugin keeps needs a migration, a backup or a subject-access answer. What a document
holds about a person is decided by the site's own formatter, so the site owns that question.

**On deletion.** Deleting an element deletes its document. Uninstalling the plugin leaves the
Typesense collections in place (they may serve another environment); the README says how to remove
them.

**Collection states**

| From | Event | To | Side effects |
|---|---|---|---|
| absent | apply / setup | live v1 | Physical `<name>_1` created, alias `<name>` → it |
| live vN | apply, schema differs | live vN | Altered in place; reports that a reindex is needed |
| live vN | recreate | building vN+1 | vN keeps serving through the alias |
| building vN+1 | populated, and every dependant built | live vN+1 | Aliases swapped together, elements changed during the build re-synced, vN dropped |
| building vN+1 | its or a dependant's build fails | live vN | Every new version deleted, no alias moved, error reported |

---

## 5. Rules

| # | Rule |
|---|---|
| BR-1 | **Nothing is indexed unless declared.** An element whose section, category group, product type or kind is not a declared, enabled source is never sent to Typesense, and its save queues nothing. |
| BR-2 | Connection settings (host, port, protocol, admin key, search-only key, connect timeout default 2s, request timeout default 5s, retries default 3, queue priority default 1024, batch size default 100, collection prefix) are editable in the CP, accept `$ENV_VAR` references, and are stored unresolved in project config. `config/typesense-sync.php` may override any of them. `collections`, `sources` and `analytics` are settable **only** in that file. |
| BR-3 | A collection's live name is its explicit env-aware `name` if given, else collection prefix + handle. The live name is always an alias; the physical collection is `<name>_<n>`. |
| BR-4 | Config is validated by one routine whose problems are shown in the utility and by `setup`, which exits non-zero on any error: a source naming an undeclared collection; a formatter class that is missing or does not implement the formatter interface; two formatters in one collection declaring the same field differently; two collections resolving to the same live name; a reference field naming an undeclared collection; a reference cycle. |
| BR-5 | **No hard dependency on any plugin.** A Products source is ignored with a warning (not an error) when Commerce is not installed. |
| BR-6 | After save, after delete and after restore queue work for elements of a declared source, **except** when the element is a draft or revision, is resaving or propagating, or is a nested entry with no section. Delete resolves the target collection at event time. |
| BR-7 | When — and only when — a users source is declared, activate, deactivate, suspend, unsuspend, lock, unlock and group assignment each queue a sync for that user. |
| BR-8 | A sync job re-derives everything: it loads the element with any status, resolves its target and asks the formatter. Missing element, disabled element or `shouldIndex() === false` → delete the document. There is no "disabled" flag in a document. |
| BR-9 | **Dedupe.** Queuing a sync sets a cache flag for element + site (TTL 600s); a queue call while it is set is dropped. The job clears the flag **before** loading the element, so a save that lands mid-job queues a fresh sync. |
| BR-10 | **A save never fails because of Typesense.** Unreachable server, bad key or a rejected document is logged to the `typesense-sync` category with the document id; queue jobs retry through Craft's queue; the utility shows the connection problem. |
| BR-11 | A document's id is `BaseFormatter::documentId()`, by default the element id as a string. URLs are root-relative. Dates are Unix seconds; "no expiry" is the sentinel `253402300799`. |
| BR-12 | A reindex walks every declared target of the collection in batches of batch size and imports with upsert; each import's per-line results are read and failures logged. |
| BR-13 | **Prune** runs only when asked for. It deletes documents whose ids no formatter built during that run, and is **skipped entirely** when the run built zero documents. |
| BR-14 | **Counters survive.** Fields declared as counters (by analytics or a collection's `counters` list) are read from the live collection for each batch and written into the outgoing documents. If that read fails, the batch is not written. |
| BR-15 | **Recreate** rebuilds a collection and every collection holding a reference into it, directly or not, in dependency order: each gets `<name>_<n+1>`, populated while its alias keeps serving `<name>_<n>`, a dependant referencing the new physical versions. Only when every build has succeeded are the aliases swapped, back to back; then every element of each collection's sources updated or trashed since its build began is re-synced, and the old versions are dropped, dependants first. If any build fails — or writes nothing while the live version holds documents — every new version is deleted and no alias moves. |
| BR-16 | **Apply** creates a missing collection and alias, or alters the live one in place to match the desired schema (derived from its formatters plus config extras). After an alter that adds or changes a field it reports that a reindex is needed and does not run one. `--dry-run` changes nothing. |
| BR-17 | **The admin key never leaves the server.** It is not passed to Twig, not rendered in HTML or JS, and shown in the CP only as its last four characters. Scoped keys are signed only with the search-only key. |
| BR-18 | A scoped key embeds `filter_by` = the collection's default filter (the publication window `postDate <= now && expiryDate > now`, on by default, switchable off per collection) AND the caller's filter AND any filters added by `EVENT_DEFINE_SCOPED_KEY`; `exclude_fields` from the collection's config, which the client cannot override; and `expires_at` = now + TTL (default 3600s). |
| BR-19 | `setup` and *Test connection* check the search-only key's actions on the server and **refuse** a key that allows anything beyond `documents:search`. |
| BR-20 | The Twig variable returns `null` from every method when the plugin is unconfigured or the key cannot be made; it never throws on a front-end request. |
| BR-21 | Analytics does nothing unless declared. Rules (popular queries, no-hits queries, counters) are applied as a diff and are idempotent; `remove` deletes only rules the plugin declared (name-prefixed). The events-only key is created by command and its value shown once. |
| BR-22 | The utility, its actions, the element action and the edit-screen menu item require the `utility:typesense-sync` permission. Recreate and flush require the collection handle to be typed. |
| BR-23 | Every web action is CP-only, POST, CSRF-checked and refuses anonymous requests. |
| BR-24 | `setup`, the utility and *Test connection* refuse a Typesense server older than 30.0 with the version found. |
| BR-25 | The plugin contains no name specific to any site: no section, field, group, env var or collection handle other than its own. |
| BR-26 | Release packaging meets the Plugin Store: Craft License, `composer.json` `extra` (name, handle, class, developer, developerUrl, documentationUrl, changelogUrl), `icon.svg` + `icon-mask.svg`, `CHANGELOG.md` in `## x.y.z - YYYY-MM-DD` form, every string through `Craft::t('typesense-sync', …)`. |
| BR-27 | Extension points are public API, documented in the README: `sync->queueElement()`, `EVENT_BEFORE_INDEX_DOCUMENT` (cancellable), `EVENT_AFTER_SYNC`, `EVENT_AFTER_DELETE_DOCUMENT`, `EVENT_RESOLVE_TARGET`, `EVENT_REGISTER_ELEMENT_TYPES`, `EVENT_DEFINE_SCOPED_KEY`. |

**Non-functional**

- Every client call is bounded by the configured timeouts on the Guzzle client (profile trap); no CP
  request waits longer than connect + request timeout × retries.
- An element save adds one cache write and one queue push, never a network call.
- PHPStan level 6 with no baseline; PHP 8.2 and 8.4.
- Nothing is emailed or notified. The only feedback is the utility, flash messages and console output.

---

## 6. Interfaces

**CP actions** (all CP-only, POST, CSRF; BR-22, BR-23)

| Method | Path | Purpose | Auth | Returns |
|---|---|---|---|---|
| POST | `actions/typesense-sync/settings/test-connection` | Test connection | admin (settings) | JSON: ok, version, problems |
| POST | `actions/typesense-sync/utility/sync-element` | Queue one element | `utility:typesense-sync` | Redirect + flash, or JSON |
| POST | `actions/typesense-sync/utility/reindex` | Queue a reindex (optional prune) | `utility:typesense-sync` | Redirect + flash |
| POST | `actions/typesense-sync/utility/apply` | Apply schema | `utility:typesense-sync` | Redirect + flash |
| POST | `actions/typesense-sync/utility/recreate` | Recreate (typed confirm) | `utility:typesense-sync` | Redirect + flash |

**Console** (`typesense-sync/…`): `setup [--skip-sync]` · `sync [--collection] [--prune] [--queue]` ·
`sync/element <id> [--site]` · `flush <collection>` (confirms) · `collections/status` ·
`collections/apply [--collection] [--dry-run]` · `collections/recreate --collection` (confirms) ·
`analytics/status|apply|report|create-events-key|remove [--dry-run] [--limit]`. Every command exits
non-zero on failure.

**Twig** — `craft.typesense`: `searchConfig(handle, {filter, ttl})` → `{host, port, protocol,
collection, apiKey, expiresAt}` · `scopedKey(handle, params)` · `collectionName(handle)` · `analyticsConfig()` ·
`viewCounts(handle, ids)`. All `null` when unconfigured (BR-20).

**PHP** — services `client`, `targets`, `sync`, `collections`, `search`, `analytics` on
`TypesenseSync::getInstance()`; formatter contract `shouldIndex($element): bool`,
`format($element): array`, `schema(SchemaContext $ctx): array`; events per BR-27.

**Screens**

| Surface | New or reuse | Notes |
|---|---|---|
| Plugin settings | New | Craft `forms` macros with env autosuggest; *Test connection* button |
| Utility "Typesense Sync" | New | Problems; per collection: alias → version, documents, diff, buttons; analytics status |
| Element index action "Sync to Typesense" | New | Entries, categories, users, products (when declared) |
| Edit-screen action menu item | New | Same, one element |

**Design source.** None supplied: the developer extends Craft's native control-panel components
(`_includes/forms`, utility and settings conventions). No custom styling.

**Copy ownership.** Claude drafts all CP copy, the README and a draft Store listing; Sam reviews.
Sam owns the final listing text, screenshots and price.

**LLL migration map** — each LLL-specific piece and the plugin interface it would sit on. A row that
cannot be filled is a missing extension point and is fixed here, not in LLL.

| LLL piece | Lands on |
|---|---|
| `MemberFormatter`, `CompanyFormatter`, marketplace formatters | Site formatters extending `BaseFormatter`; `marketplace` doc-type field → `type` (needs resync) |
| Company → people fan-out; Stripe subscription listener | Site listeners calling `sync->queueElement()` |
| `goodStanding()`, `listingVisibility()`, `memberDirectory()` | `EVENT_DEFINE_SCOPED_KEY` + per-call `filter` |
| `listingByline()` join, `authorId` reference | Formatter `schema()` reference via `SchemaContext`; recreate ordering (BR-15) |
| `Search::EXCLUDED_FIELDS` | Collection `search.excludeFields` |
| `config/typesense.php`, `TYPESENSE_COLLECTION_<HANDLE>` | `config/typesense-sync.php`; explicit env-aware `name` keeps existing names |
| `Features::on('search')`, `FeatureGate 'search.analytics'` | Config `enabled` expressions (PHP in the config file) |
| `MemberSearch`, `MembersReconcile`, nightly reconcile | Stay site code, using `search` and `queueElement()` |
| Currency panel in the utility; legacy view import | Stay site code (dropped from the utility) |
| `craft.app.getModule('typesense').*` in templates | `craft.typesense.*` |

---

## 7. Test plan

### Client-verified criteria

| # | Given / When / Then | Proved by |
|---|---|---|
| AC-1 | Given valid details, *Test connection* shows "Connected" and the server version; given a wrong key it shows the failure and settings still save | TS-1 |
| AC-2 | Given the base example config, after setup an entry in the declared section is found by search and an entry in an undeclared section is not | TS-2 |
| AC-3 | Publishing, disabling, deleting and restoring an entry is reflected in search once the queue has run | TS-3 |
| AC-4 | With the server unreachable, an entry still saves normally and the utility names the problem | TN-1 |
| AC-5 | After a result-class change the utility shows the collection out of date with the difference; recreate keeps search answering throughout and ends on a new version | TS-4 |
| AC-6 | A search page built with the template helper returns results; its source holds no admin key; an expired or future-dated entry never appears | TS-5 |
| AC-7 | A reindex with prune removes content no longer declared, keeps view counts, and leaves the collection untouched when nothing could be built | TS-6 |
| AC-8 | A search that joins two collections keeps working after the joined-to collection is recreated | TS-7 |
| AC-9 | With analytics declared, popular and no-result searches appear in the report and a view counter increments and survives a reindex | TS-8 |
| AC-10 | With users declared, suspending a user updates search without anyone saving the user | TS-9 |
| AC-11 | A products source without Commerce shows a warning and nothing breaks; with Commerce, products are indexed | TS-10 |
| AC-12 | The plugin installs on a fresh Craft 5.6 site on PHP 8.2 and on PHP 8.4, and the Store validator accepts its metadata | TS-11 |

### Test data and preconditions

- **Test site** (`tests/_craft/`, to be made in 1.1): sections `news` (declared) and `private`
  (undeclared), a category group, three users, all created by fixtures.
- **Example formatters** from `examples/formatters/` double as test formatters.
- **Typesense 30.x and MySQL 8.0 containers** (to be made in 1.1). The integration suite prefixes
  collections with a per-run prefix and deletes them afterwards.
- **Commerce**: installed only in the integration leg that runs TS-10's second half (a separate
  composer install); to be made in task 7.2.
- **Clock**: publication-window and TTL tests inject `now` rather than waiting.

### Scenarios

**TS-1 - Connect** - *covers AC-1 · BR-2, BR-19, BR-24* · Integration + manual

| # | Step | Expected |
|---|---|---|
| 1 | Save settings with valid env refs; press *Test connection* | "Connected", version ≥ 30.0 shown |
| 2 | Change the admin key to a wrong value; test | Failure message naming authentication; settings save succeeds |
| 3 | Put the admin key into the search-key field; test | Refused: "search key allows more than search" (BR-19) |

*Success criterion: all three messages as stated, no exception page.*

**TS-2 - Declare and set up** - *covers AC-2 · BR-1, BR-3* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Copy base example config naming `news`; run `setup` | Exit 0; reports collection created, N documents |
| 2 | Search the collection for a `news` entry's title | Hit, root-relative URL |
| 3 | Search for a `private` entry's title | No hit; no job was queued for it |

*Success criterion: only declared content is in the collection.*

**TS-3 - Content lifecycle** - *covers AC-3 · BR-6, BR-8, BR-9* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Save a new `news` entry; run the queue | Document present |
| 2 | Save it five times quickly; count queued jobs | One job (BR-9) |
| 3 | Disable it; run queue | Document gone |
| 4 | Enable, then delete; run queue | Document gone |
| 5 | Restore; run queue | Document present |
| 6 | Save a draft of it and a bulk resave of the section | No jobs queued |

*Success criterion: the document exists exactly when the entry is live.*

**TS-4 - Schema change and recreate** - *covers AC-5* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Add a field to the test formatter; open the utility | Collection marked out of date, field listed |
| 2 | Run `collections/recreate` while a loop searches the alias | Every search answers; none errors |
| 3 | Save an entry mid-build | Present in the new version after the swap (BR-15) |
| 4 | After completion, list collections | Only `<name>_2`, alias → it |

*Success criterion: zero failed searches and no lost update.*

**TS-5 - Search page** - *covers AC-6 · BR-17, BR-18, BR-20* · Integration + manual

| # | Step | Expected |
|---|---|---|
| 1 | Render a template calling `searchConfig('news')` | Host, alias name and a key |
| 2 | Search with that key | Results |
| 3 | Grep the rendered HTML for the admin key | Absent |
| 4 | Search for a future-dated and an expired entry, with and without a client filter | Never returned |
| 5 | Search with the key after injected now + 3601s | Rejected |

*Success criterion: only published, in-window content is reachable with the page's key.*

**TS-6 - Reindex and prune** - *covers AC-7 · BR-13, BR-14* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Set `popularity` on a document; `sync --prune` | Popularity unchanged |
| 2 | Remove a section from config; `sync --prune` | Its documents gone |
| 3 | Make the formatter build nothing; `sync --prune` | Prune skipped, collection unchanged |
| 4 | Make the counter read fail | Batch not written, error logged |

*Success criterion: prune removes only what was undeclared, and never on an empty run.*

**TS-7 - Joins survive a recreate** - *covers AC-8* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Declare collections A and B, B referencing A; setup | Joined search from B returns A's fields |
| 2 | Recreate A | B recreated after A; joined search still works |
| 3 | Declare A referencing B as well | `setup` refuses: reference cycle |

*Success criterion: joined search works after the run.*

**TS-8 - Analytics** - *covers AC-9 · BR-14, BR-21* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Copy the analytics example; `analytics/apply` twice | Rules created once; second run "no changes" |
| 2 | Run searches, including one with no results | `analytics/report` lists both |
| 3 | Send two view events with the events key; reindex | Counter = 2 after the reindex |
| 4 | `analytics/remove` | Only prefixed rules removed |

*Success criterion: report and counter as stated.*

**TS-9 - User status** - *covers AC-10 · BR-7* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Declare users with the example user formatter (excludes suspended); setup | Active users indexed |
| 2 | Suspend a user through the users service (no save); run queue | Their document gone |
| 3 | Unsuspend; run queue | Back |
| 4 | Remove the users source; suspend another user | No job queued |

*Success criterion: status changes reach search without a save.*

**TS-10 - Commerce optional** - *covers AC-11 · BR-5* · Integration

| # | Step | Expected |
|---|---|---|
| 1 | Without Commerce, add the products snippet; open the utility | Warning, no error; `setup` exit 0 |
| 2 | With Commerce installed, save a product of the declared type | Document present |

*Success criterion: absence warns, presence works.*

**TS-11 - Install and package** - *covers AC-12 · BR-25, BR-26, BR-27* · CI + manual

| # | Step | Expected |
|---|---|---|
| 1 | CI legs on PHP 8.2 and 8.4 | `composer check` green on both |
| 2 | Install into a fresh Craft 5.6 site via a path repository; install the plugin | Installs, settings screen loads |
| 3 | `composer validate --strict`; Store submission preview | Valid; listing accepted |
| 4 | Read the README extension-points section | Every BR-27 event and `queueElement()` documented |

*Success criterion: installs clean on both PHP versions and passes Store validation.*

### Negative and edge cases

| # | Condition | Expected behaviour |
|---|---|---|
| TN-1 | Server unreachable during a save | Save succeeds; job fails and retries; log line with element id; utility shows problem (BR-10) |
| TN-2 | Element deleted before its sync job runs | Job deletes the document, no error (BR-8) |
| TN-3 | Formatter class missing or wrong interface | Problem listed; setup exits non-zero; saves queue nothing for that source (BR-4) |
| TN-4 | Two collections resolve to the same live name | Problem listed (BR-4) |
| TN-5 | Server older than 30.0 | Setup and test refuse with the version found (BR-24) |
| TN-6 | Provisional draft hard-deleted on CP save | No delete queued (BR-6) |
| TN-7 | Import returns a rejected line | Other lines kept; failure logged with id (BR-12) |
| TN-8 | Non-admin CP user without the permission posts a utility action | 403 (BR-22) |
| TN-9 | Anonymous request to any action | Refused, login required (BR-23) |
| TN-10 | Recreate or flush with the wrong typed handle | Refused, nothing changed (BR-22) |
| TN-11 | Template helper called with no settings | Returns null, page renders (BR-20) |
| TN-12 | Populate fails mid-recreate | New version deleted, alias unchanged, error shown (BR-15) |
| TN-13 | Save lands while its own sync job is running | A second job runs after (BR-9) |
| TN-14 | Config file sets an unknown key | Listed as a problem naming the key — Craft itself ignores it silently; README lists valid keys |

### Automated checks

- **Unit suite** (client mocked): config parsing + every BR-4 problem, target resolution (BR-1,
  BR-6), dedupe flag (BR-9), stale-id calculation (BR-13), schema diff/normalise (BR-16), scoped-key
  params (BR-18), BaseFormatter helpers (BR-11), version gate (BR-24).
- **Integration suite** (real Typesense + MySQL): TS-2 to TS-10, TN-1, TN-2, TN-6, TN-7, TN-12,
  TN-13.
- **Guard**: B5 #2 grep for site-specific names (BR-25).
- **Manual**: TS-1 and TS-11 step 2-3 (Store preview is a human-only surface).
- **Test hooks the build must add**: `data-collection="<handle>"` and `data-ts-action="<action>"`
  on utility rows and buttons; console exit codes per command; log category `typesense-sync`;
  an injectable clock on `search` (`setNow()` for tests).

### Regression checks

The plugin is new, so its regressions are LLL's already-fixed bugs coming back through the port:

- **Root-relative URLs** — ported from `TypesenseRootRelativeUrlTest`; at risk because
  `UrlHelper` defaults to absolute.
- **Prune on an empty run** — ported from `TypesensePruneTest`; at risk in the batching rewrite.
- **User lifecycle without save** — ported from `TypesenseUserLifecycleTest`; at risk because the
  events move behind a "users declared" check.
- **Counters across reindex** — at risk because the counter-field names become configurable.
- **Joins after recreate** — at risk because dependant discovery moves from resolved names to
  `SchemaContext`.

---

## 8. Build order

| Phase | What it delivers | Depends on |
|---|---|---|
| 1 | Repo scaffold, containers, CI, settings + config validation | - |
| 2 | Client, targets, formatter contract, sync + jobs + dedupe | 1 |
| 3 | Collections: apply, recreate, alias, joins | 2 |
| 4 | Search: scoped keys, Twig variable | 3 |
| 5 | CP utility, element action, console | 3 |
| 6 | Analytics | 3 |
| 7 | Users, categories, Commerce sources | 2 |
| 8 | Examples, README, Store packaging, beta tag | all |

Phases 4–7 are independent once 3 is done; build them in number order unless one is blocked.

### Tasks

- [x] **1.1 Scaffold** — `composer.json`, `src/TypesenseSync.php`, `docker-compose.yml`, `codeception.yml`, `tests/_craft/`, `phpstan.neon`, `ecs.php`, `.github/workflows/ci.yml`, `LICENSE.md`
      Rules: BR-26 · Verify: B5 #1, #3
- [x] **1.2 Settings model, CP settings screen, config parsing and validation** — `src/models/{Settings,CollectionConfig,SourceConfig,ResolvedTarget}.php`, `src/templates/settings.twig`
      Rules: BR-2, BR-3, BR-4, BR-5 · Verify: unit suite, TN-3, TN-4, TN-14
      *As built (30 Sep 2026):* config shape is `collections` (keyed by handle: `name`, `schema`, `defaultSortingField`, `enableNestedFields`, `counters`, `search.{publicationWindow,excludeFields}`) and `sources` (a list, each `kind` = `section`|`categoryGroup`|`productType`|`users` (default `section`), `handle`, `collection`, `formatter`, `enabled` (default **true**), `priority`, `site`, `entryTypes` — sections only). An override can switch a type off but never on inside a disabled source. **Craft drops an unknown config-file key silently** (`setAttributes(…, false)`), so `getProblems()` names unknown keys at every level itself (TN-14). File-only keys are kept out of project config by `Settings::fields()` and re-read from the file in `init()`. `port` is an env-aware string (`getPort()`); the tuning numbers are plain integers. Problems are errors; `getWarnings()` holds BR-5. `FormatterInterface` and a minimal `SchemaContext` (`reference(handle)`) were built here because BR-4's field and reference checks call `schema()`; 2.2 adds `BaseFormatter` and anything more the context needs. A self-reference counts as a cycle. The settings screen lists problems until the utility (5.1) exists; the *Test connection* button is 2.1's.
- [x] **2.1 Client service with bounded timeouts, version gate, test-connection action** — `src/services/Client.php`, `src/controllers/SettingsController.php`
      Rules: BR-10, BR-19, BR-24 · Verify: TS-1, TN-5
      *As built (30 Sep 2026):* service `client`: `getClient()` (memoised, null when unconfigured), `createClient(Settings, ?numRetries, ?apiKey)` (always on a Guzzle client carrying `connectTimeout`/`timeout`; retry interval fixed at 0.5 s), `testConnection(?Settings)` → `{ok, version, problems}`, never throws. Later tasks reuse `Client::versionProblem()` for BR-24 (setup, utility) — it also refuses a version that does not start with a number (nightly). **BR-19 needs two checks, because Typesense never returns a key's value, only `value_prefix` (4 chars):** the search key is used to list collections (a search-only key is refused — this catches the admin key and the server's bootstrap `--api-key`, which `/keys` does not list), then every `/keys` entry sharing its prefix must have actions exactly `['documents:search']`; no match is "not a key on this server". The test makes one attempt (no retries) and validates the limits first, since a timeout of 0 is Guzzle's "wait for ever". It tests the form's values unsaved (`Settings::withConnection()`: blank admin key keeps the saved one, config-file keys win). Tests: `tests/unit/services/ClientTest.php` (HTTP mocked via `tests/fixtures/MockedClient.php`, the seam is `Client::createGuzzleClient()`), `tests/integration/ConnectionTest.php` (TS-1 steps 1–3 automated against the 30.0 container). **Manual TS-1 in a fresh Craft site not yet run** — the button's JS is unexercised in a browser.
- [x] **2.2 Targets and formatter contract** — `src/services/Targets.php`, `src/formatters/{FormatterInterface,BaseFormatter,SchemaContext}.php`, `src/events/*`
      Rules: BR-1, BR-6, BR-11, BR-27 · Verify: unit suite
      *As built (30 Sep 2026):* service `targets`: `isSyncable()` (id set, not draft/revision — `ElementHelper::isDraftOrRevision()` also defers to the owner — not resaving, not propagating), `resolveTargetFor()` (indexable target or null; entries by section + entry type, a sectionless nested entry is null; categories by group; every user to the one users source), `shouldQueue()` = both, `formatterFor()` (`Craft::createObject()`, then `setTarget()` on a `BaseFormatter`), `siteFor()` (named site, else primary; null for an unknown handle), `getElementTypes()` (Entry always; Category / User only while a source of that kind is declared). Events on the service: `EVENT_RESOLVE_TARGET` (`ResolveTargetEvent`: `element`, `target` — a handler may replace, null or mutate it, and whatever it leaves is re-checked by `Settings::markValidity()`, now public, so it cannot route into an undeclared collection) and `EVENT_REGISTER_ELEMENT_TYPES` (`RegisterElementTypesEvent::$types`). The other four BR-27 events belong to the services that raise them (2.3, 4.1) and are not created yet. **Products are not resolved** — 7.2 adds them with the Commerce leg. `BaseFormatter` is a port of LLL's with `marketplace` renamed `type`; formatters are made with no constructor arguments (the validator already builds them that way), so the target arrives by `setTarget()` and `priority` defaults to 100 without one. LLL's `EntryFormatter` rule is folded in: an entry with no post date gets `FAR_FUTURE` (pending), any other element 0. `EXCLUDED_KEYWORD_FIELDS` is empty (LLL's list named its own fields); `documentId()` is public and instance, so 2.3's delete asks the formatter at event time. A subclass field named like a base field replaces it, `type` included — documented on `fields()`. `url()` is root-relative; `assetUrls()` returns the volume's URLs as they are. Tests: `tests/unit/services/TargetsTest.php`, `tests/unit/formatters/BaseFormatterTest.php`, with unsaved element fixtures in `tests/fixtures/elements/` (`TestEntry`, `TestCategory` name a section/type/group without the database); 100 unit + 8 integration green on 8.2 + 8.4.
- [x] **2.3 Sync service, jobs, element events, dedupe** — `src/services/Sync.php`, `src/jobs/{SyncElement,DeleteElement,Reindex}.php`
      Rules: BR-6, BR-8, BR-9, BR-10, BR-12, BR-13, BR-14 · Verify: TS-2, TS-3, TS-6, TN-1, TN-2, TN-6, TN-7, TN-13
      *As built (30 Sep 2026):* service `sync`: `queueElement()` (BR-27) / `queueDelete()` / `isPending()`; `syncById()` (what the job runs) and `syncElement()`, which return an `OUTCOME_*` (`indexed`, `removed`, `rejected`, `skipped`); `deleteDocument()`; `import($handle, $docs, ?$into)`, which writes to the alias or to a named physical version and always reads counters from the alias (for 3.2's recreate); `counterFields()` (the collection's `counters` only; 6.1 adds analytics fields); `fetchCounters()`; `reindex()` / `reindexAndPrune()` / `prune()` / `staleIds()`; `queryForTarget()`, public for 3.2's mid-build re-sync. **Dedupe:** cache key `typesense-sync:pending:<element>:<site>`, where the site is the *target's* site, not the event's. It is taken with `cache->add()` **before** the push, because a worker can take the job at once, and a flag set after the job had cleared it would drop every save for 600 s. Nothing is queued while the plugin is unconfigured. **Retry:** Craft's queue defaults to one attempt, so `SyncElement` and `DeleteElement` implement `RetryableJobInterface` (3 attempts, TTR 60 s). Craft's DB queue leaves a failed job reserved and picks it up again once its TTR expires, so that TTR is the backoff. Any failed call throws `errors\SyncException` (logged to `typesense-sync` with the document ids). A document Typesense *rejects* is logged and not retried (`rejected`). `Reindex` is not retryable: a failed batch is logged and counted, the run carries on, and the job then fails, naming the count. Counters are read with `export` (`filter_by` on backtick-quoted ids + `include_fields`), not `search`, so no `query_by` field is assumed. A missing collection reads as no counters; a failed read writes nothing (BR-14). A document's `id` is always forced back to `documentId()` after the formatter and the events. `EVENT_BEFORE_INDEX_DOCUMENT` (`IndexDocumentEvent`, cancellable): cancelling deletes the document on a single sync and skips it on a reindex. `EVENT_AFTER_SYNC` (`SyncEvent`) is raised on a single sync only; `EVENT_AFTER_DELETE_DOCUMENT` (`DeleteDocumentEvent`) is raised on every delete, including one that found nothing to delete. A reindex re-asks `resolveTargetFor()` for each element only while `EVENT_RESOLVE_TARGET` has handlers, so an exclusion holds there too; an element a handler routes *into* a collection from outside its sources is not reached by a reindex. Element events are wired in `TypesenseSync::registerElementEvents()` from `Craft::$app->onInit()`, so other plugins' element-type handlers count. Each handler catches everything, so a save never fails (BR-10). **Known gap, as in LLL:** an entry moved to an undeclared entry type or section queues nothing, so its document stays until a prune. Verified: TS-3 steps 1–6, TS-6 steps 1–3, TN-1 (the job is left at attempt 1, not failed), TN-2, TN-6, TN-7 and TN-13 in `tests/integration/SyncTest.php`, against real saves and the real queue. TS-6 step 4 and the unit-level rules are in `tests/unit/services/SyncTest.php`. **TS-2 needs `setup` (5.2)**; only its step 3 ("no job queued") is covered here. Integration tests create their own entry types and sections. The entry type needs a layout with `EntryTitleField`, or the title is not saved. The slug must be set explicitly, `writeYamlAutomatically` is off, and each test gets its own collection prefix. The test site's URLs are `/index.php?p=…`. 125 unit + 20 integration green on 8.2 + 8.4.
- [x] **3.1 Collections: desired schema, diff, apply** — `src/services/Collections.php`
      Rules: BR-3, BR-16 · Verify: unit suite, TS-4 step 1
      *As built (30 Sep 2026):* service `collections`: `getDesiredFields()` (the union of `schema()` from every indexable target's formatter, made by `targets->formatterFor()` with a `SchemaContext` of `Settings::getLiveNames()`; config `schema` extras win, but are added only while something routes in, so a collection whose sources are all off has no fields and apply answers `skip`; sorted by name; a conflict throws `InvalidConfigException`, judged by `Settings::comparableField()` — now public static — so it agrees with BR-4's problem), `buildSchema()`, `getActiveCollectionName()`, `getLiveSchema()`, `diff()`, `apply($handle, $dryRun)`, `nextVersion()` (one past the highest version on the server, alias or not, so a version orphaned by a failed run is never reused) and public static `normalise()`. `diff()` returns `exists`, `collection`, `added`, `dropped`, `changed` (from/to), `needsRecreate` + `recreateReasons`, `upToDate` and `documents`. `apply()` returns `{action, collection, payload, message, reindex}`, `action` one of `create` / `alter` / `none` / `skip` / `needs-recreate` / `blocked`; it never reindexes, and `reindex` is true after a create or an alter that adds or changes a field (a drop alone needs none). **Departures from LLL:** only `ObjectNotFound` reads as absent — LLL swallowed every error, so a failed alias read could create an empty version and point search at it; here any other failure throws `SyncException`. `enable_nested_fields` is compared as well as `default_sorting_field`, and either differing is `needs-recreate` (returned, not thrown, so 5.1/5.2 can show it). A live name taken by a real collection is `blocked` rather than aliased over. `normalise()` also compares `infix`, `stem`, `store` and `locale`. **Calibrated against 30.0:** a collection with no sorting field reports `default_sorting_field: ""` (LLL compared it with null), and `enable_nested_fields` is reported; the plugin's default for it is **on**. A changed field is dropped and re-added in one PATCH. Tests: `tests/unit/services/CollectionsTest.php` (22, mocked) and `tests/integration/CollectionsTest.php` (5: create then `none`, sorting field + nesting off read back, a reference field reads back up to date, TS-4 step 1 — a formatter gains a field, the diff lists it, a dry run changes nothing, the alter keeps the version and its documents — and a changed sorting field refused). `tests/integration/SyncTest.php` now makes its collection with `apply()` (the counter `popularity` is a config `schema` extra) and every integration test deletes its prefix through `tests/Support/TestCollections::deleteAll()`. The alter runs under the configured request timeout; a large collection's alter may outlast it (the server carries on), which 3.2/5.x should surface rather than retry. 147 unit + 25 integration green on 8.2 + 8.4.
- [x] **3.2 Recreate with alias swap, mid-build re-sync, dependant ordering** — `src/services/Collections.php`
      Rules: BR-4, BR-15 · Verify: TS-4, TS-7, TN-12
      *As built (30 Sep 2026):* `collections->recreate($handle, ?$progress)` returns one row per collection rebuilt — `{collection, from, to, indexed, rejected, resynced, dropped}` — and throws `SyncException` on a failed build (nothing then changed) or `InvalidConfigException` while `Settings::getProblems()` lists anything (BR-4, which is how a cycle is refused before the server is asked). Also public: `referencingCollections()` (direct) and `getDependants()` (transitive, in rebuild order; throws on a cycle it cannot order). `$progress(handle, indexed, target)` is called inside each build; 5.1/5.2 can show it. **Departure from LLL, found against 30.0:** a joined search names the *alias* (`$people(title)`) while the reference is bound to the physical version, so once `people` moves to `people_2` a join from `posts_1` returns nothing, **even though `people_1` still exists** — keeping the old version does not keep the join. So the whole plan is built first, each dependant's reference rewritten to the new physical name (`people_2.id`, which Typesense accepts), and the aliases are swapped back to back only once every build has succeeded; a failed dependant deletes *every* new version and moves nothing. Typesense reports such a reference back as `people_2.id`, so `diff()` maps a versioned reference to its alias before comparing, or the dependant would read as changed for ever. A build **fails** when it throws, when any batch failed to write, or when it built zero documents while the live version holds some (the BR-13 guard, applied to a swap). Mid-build re-sync: per collection, `queryForTarget()` with `dateUpdated >=` build start − 1 s (second precision) plus trashed elements by `dateDeleted`, each through `sync->syncElement()`; a failed element is logged, not thrown, because the alias has already moved. Old versions are dropped after the re-sync, dependants first, and **every** non-live version of the handle goes — so a version orphaned by a failed delete is swept by the next successful run. A Craft mutex per handle in the plan refuses a second concurrent recreate. Tests: `tests/integration/RecreateTest.php` (5: TS-4 steps 2–4 with searches and a save + queue run from inside the build; TN-12; an empty build not swapped; TS-7 steps 1–2 with the join checked during both builds; a failing dependant leaving everything as it was, then the rerun finishing) and two unit tests (dependant order across a chain; TS-7 step 3's cycle refused with no request sent). TS-4 step 2's "loop" is the progress callback — single process — so it proves search answers at every point *between writes*, not under concurrent load. 149 unit + 30 integration green on 8.2 + 8.4.
- [x] **4.1 Scoped keys and `craft.typesense`** — `src/services/Search.php`, `src/variables/TypesenseVariable.php`
      Rules: BR-17, BR-18, BR-20 · Verify: TS-5, TN-11
      *As built (30 Sep 2026):* service `search`: `scopedKeyParams($handle, $params)` (the embedded parameters, public so they can be tested and shown), `scopedKey()`, `searchConfig()` → `{host, port, protocol, collection, apiKey, expiresAt}` (**`expiresAt` added** beyond §6, so a page or a cache in front of it can tell when the key goes stale — profile trap), `collectionName()`, `publicationWindow(?now)`, and the clock `setNow(?int)` / `now()`. Caller params: `filter` (string), `ttl` (seconds, a non-positive or non-numeric value falls back to 3600), `excludeFields` (added to the config's); any other key is embedded as a Typesense search parameter (`limit_hits`, …), **except `filter_by`, `exclude_fields` and `expires_at`, which the plugin sets and a caller's value for is ignored with a warning**. `filter_by` = window AND caller AND handlers, **each clause in parentheses** so an `||` in one cannot widen another; with only one clause it is unwrapped, with none `filter_by` is left out. `EVENT_DEFINE_SCOPED_KEY` (`DefineScopedKeyEvent`: `collection`, `params`, `now`, `filters`, `excludeFields`) is raised before signing; `filters` and `excludeFields` start empty and are added on top, so a handler can narrow a key but never remove the window or the config's excluded fields. Signing goes through the library's `keys->generateScopedSearchKey()` (local HMAC, no request). **Null, never a throw, when** the plugin is unconfigured (no host or admin key), the collection is undeclared or its explicit name resolves to nothing, the search-only key is empty, **or the search-only key equals the admin key** (BR-17; the full BR-19 check needs the server and stays with *Test connection* / setup). `craft.typesense` is registered on `CraftVariable::EVENT_INIT` and wraps each call in a catch-all (logged to `typesense-sync`), so a throwing handler still renders the page (BR-20). **`analyticsConfig()` and `viewCounts()` are not here** — they need 6.1's Analytics service and are added with it. Tests: `tests/unit/services/SearchTest.php` (16: every embedded parameter, the HMAC checked against the search key and not the admin key, each null case, TN-11 through a rendered template, a throwing handler) and `tests/integration/SearchTest.php` (4, against a real search-only key made per test: TS-5 steps 1–5 — a rendered page's `data-` attribute holds the alias and a key, finds the live document only, holds neither the admin nor the search key, a browser `filter_by` asking for future/expired finds nothing, a key made 3601 s ago is refused — plus a caller filter with `||` staying inside the window, and `exclude_fields` holding against a browser's `include_fields`). **Manual TS-5 steps 1–3 in a browser (view-source) not yet run.** 165 unit + 34 integration green on 8.2 + 8.4.
- [x] **5.1 Utility, CP actions, element action, edit-screen menu item** — `src/utilities/Utility.php`, `src/templates/_utility.twig`, `src/controllers/UtilityController.php`, `src/elements/actions/Sync.php`
      Rules: BR-17, BR-22, BR-23 · Verify: TN-8, TN-9, TN-10, manual utility pass
      *As built (30 Sep 2026):* utility id `typesense-sync` (so the permission is `utility:typesense-sync`, `Utility::PERMISSION`), "Typesense Sync", icon `magnifying-glass`. `Utility::variables()` is the page as data (public for tests): `state` = `unconfigured` (one message + settings link) / `unreachable` (the server did not answer or is older than 30.0 — the connection problems are shown and no collection is read, BR-24) / `empty` ("No collections declared", points at `examples/config`) / `ready`, plus `version`, `connectionProblems` (from `client->testConnection()`, so a bad search key is shown but does not block the collections), `problems`, `warnings`, and one row per collection: `handle`, `alias`, `fieldCount`, `diff` (3.1's), `dependants` (3.2's rebuild order, named in the recreate instructions) and `error` (a formatter conflict or cycle shows on its row, never an error page). Buttons: Create/Apply only while there is something alterable; Reindex (with a prune checkbox) and Recreate (a typed-handle text field) only once the collection exists. **Hooks:** `data-collection="<handle>"` on each row, `data-ts-action` = `apply` / `reindex` / `recreate` / `sync-element` on each control, and `data-ts-schema` = `up-to-date` / `out-of-date` / `needs-recreate`. **Controller** `UtilityController` (`sync-element`, `reindex`, `apply`, `recreate`): Craft's `beforeAction()` runs first (CSRF → 400, then login + `accessCp`), then CP-only (400), the permission (403), POST (405). An undeclared collection is a 400; every other refusal is `asFailure()` (re-renders the posting page with the reason, or JSON 400) and changes nothing. **Departures from §6:** `reindex` and **`recreate` are queued** (`jobs/Recreate`, not retried, pushed with TTR 3600 because `BaseJob` has no TTR hook) — a build walks every element and would die at a CP request's time limit, orphaning versions; the typed handle, the config problems and the server are all checked before the push, so a refusal still changes nothing (TN-10). `apply` runs in the request (one call; flash worded per `ACTION_*`, reindex advised, never run). New **`Client::serverProblem()`**: one bounded `/debug` probe (unconfigured / unreachable / refused / < 30.0), which reindex, apply and recreate refuse on. **`Client` now reads settings through `targets->getSettings()`** like every other service, so injected test settings reach it. Element action `elements\actions\Sync` ("Sync to Typesense") and the ⋯ menu item (`TypesenseSync::syncMenuItem()`, via `Element::EVENT_DEFINE_ACTION_MENU_ITEMS` — Craft submits it as a form, so it carries a hashed `redirect` to the edit screen) are registered per followed element type **only for a user holding the permission**, and `performAction()` checks again; the menu item offers a provisional draft's canonical element and nothing for an unpublished draft or an undeclared element. No key is rendered (BR-17 — the test greps the page for the admin key). Tests: `tests/integration/UtilityTest.php` (18: TN-9 anonymous → 403 from all four actions, no CSRF → 400, GET → 405, site request → 400; TN-8 a CP user without the permission → 403 from all four, and no element action or menu item; TN-10 five wrong typed handles → 400, no job, alias unmoved, and the right one queues one `Recreate`; apply then a pruning reindex; an unreachable server refused with its reason; sync-element and the element action queue declared elements only; the menu item; all four page states; the rows' hooks through a formatter change; a config problem listed). Mutation-checked: removing the permission guard or the typed-handle check fails TN-8 / TN-10. **The test app is CLI, so Craft gives the plugin `console\controllers`; UtilityTest points `controllerNamespace` at `controllers` for its duration** — any later web-controller test needs the same. Also two `ClientTest` cases for `serverProblem()`. 167 unit + 52 integration green on 8.2 + 8.4. **craft5 sandbox:** installed (bind mount + a Typesense 30.0 service in `.ddev/docker-compose.typesense-sync.yaml`, `config/typesense-sync.php` declaring `landing`); on real content `variables()` read `ready`, apply created `craft5_content_1`, a reindex wrote 2 documents, the row read up to date and a recreate moved the alias to `_2`. **The browser pass in the CP was not run** — signing in is Sam's; TS-1's button and the utility page, the ⋯ item and the index action are still unseen in a browser.
- [x] **5.2 Console commands** — `src/console/controllers/{Setup,Sync,Collections}Controller.php`
      Rules: BR-4, BR-13, BR-15, BR-16, BR-22 · Verify: TS-2, TS-6
      *As built (30 Sep 2026):* shared base `src/console/Controller.php`, which sits outside `console/controllers/` so Craft does not list it as a command. It holds `checkConfig()` (warnings shown, problems refuse), `checkServer()` (`client->serverProblem()`), `checkDeclared()` / `handles()`, `confirmByHandle()`, `reportRun()` and a `--confirm=<handle>` option. **Exit codes:** `ExitCode::CONFIG` (78) for a config problem or nothing declared, `USAGE` (64) for an undeclared collection, a missing `--collection`, an unknown site or element, or an element that is undeclared or a draft, and `UNSPECIFIED_ERROR` (1) for the server, a wrong typed handle, a failed apply (`needs-recreate` / `blocked`) or a run with rejected or unwritten documents. **Commands:** `setup [--skip-sync]` runs config, then `client->testConnection()` (it covers `serverProblem()` *and* BR-19, so a too-broad search key refuses before anything is created; a missing search key is only a warning), then applies every collection, then reindexes each collection that applied. Analytics joins it in 6.1. `sync [--collection] [--prune] [--queue]` has the action id `index`. It refuses a collection not yet created, and `--queue` pushes one `Reindex` job per collection. **A skipped prune (the run built nothing) exits 0 with a warning**, because that is BR-13 working as designed; a *failed* prune exits 1. `sync/element <id> [--site=<handle>]` runs `sync->syncElement()` in the process. **Departure from §6: flush is `sync/flush <collection>`, not a top-level `flush`**, because the task names three controllers. It calls a new `sync->flush()`, which uses Typesense's `truncate` delete (28+) and leaves the collection and alias. `collections/status` (default) prints the diff and exits `CONFIG` while problems exist. `collections/apply [--collection] [--dry-run]` prints the payload on a dry run and advises `sync --collection=<h>` after an alter that needs it. `collections/recreate --collection=<h>` names the dependants, then confirms, then prints progress and one line per rebuilt collection. **Flush and recreate confirm by the typed handle (BR-22), from the prompt or `--confirm`.** A non-interactive run without `--confirm` gets the prompt's default, `''`, and is refused. **Help text:** yii uses a docblock's first line as the command summary and prints the whole docblock under `--help`, so rule ids sit in code comments, never in a command's docblock. **Tests:** `tests/integration/ConsoleTest.php` (14 tests) covers TS-2 steps 1–3 through `setup` (created, N documents, a hit with a root-relative URL, the private entry neither found nor queued) and TS-6 steps 1–3 through `sync --prune` (counter kept, a removed section's documents gone, an empty run skipped with the collection unchanged; step 4 stays covered by 2.3's unit test). It also covers setup re-run and `--skip-sync`, TN-3 (exit 78, nothing created), BR-19, an unreachable server, `--queue`, `sync/element`, flush with four wrong typed handles plus a non-interactive run (TN-10: nothing deleted), status / dry-run / apply / alter advice, and recreate refused then run (`_1 → _2`). The tests use Codeception `Stub` over the real controller, capturing output and answering prompts, instead of Craft's `CommandTest`, which asserts every output line in order. **Trap:** Craft's console controllers refuse root ("should not be run as the root/super user", **exit 0**, and the action never runs), and the test container is root, so `ConsoleTest` sets `CRAFT_ALLOW_SUPERUSER=1` in `_before()`. **Not done: the mutation check on the typed-handle guard** (disable it, watch TN-10 fail). The auto-mode classifier refused the temporary edit. In the craft5 sandbox, `craft help` lists the three controllers (not the base), and `collections/status` read `craft5_content_2, 2 documents, up to date`, exit 0. 167 unit + 66 integration green on 8.2 + 8.4.
- [x] **6.1 Analytics service and commands** — `src/services/Analytics.php`, `src/console/controllers/AnalyticsController.php`
      Rules: BR-14, BR-21 · Verify: TS-8
      *As built (30 Sep 2026):* **Config** — `analytics` takes `enabled` (default true, so a config expression can switch it off per environment), `eventsKey` (env-aware), `ignoreQueries` (default `['*', '']`) and `rules`, keyed by handle. A rule (`models/AnalyticsRule`) takes `type` (`popular_queries` default, `nohits_queries`, `counter`), `collection` (a declared handle), `enabled`, and for a query rule `destination` (env-aware; derived as live name + `_<handle>` when absent) and `limit` (1000), for a counter `counterField` (`popularity`), `eventType` (`click`) and `weight` (1). **A rule's server name is the collection's live name + `_<handle>`**, and it names the alias, which Typesense follows through a recreate (verified). `Settings` gained `isAnalyticsEnabled()`, `getAnalyticsRules()` (enabled rules whose collection is declared), `getAnalyticsEventsKey()`, `getIgnoredQueries()` and `getCounterFields()`, and `getProblems()` now also names: unknown keys in `analytics` and in each rule, an unknown type, an undeclared collection, a handle not starting with a letter (it would read as a version), a limit below 1, a destination that resolves to nothing, is a declared collection, or is shared by two rules, and **an events key equal to the admin key** (BR-17). A disabled rule is checked for its keys only. **Counters:** `sync->counterFields()` is now `Settings::getCounterFields()` — the collection's `counters` plus each counter rule's field — and `collections->getDesiredFields()` adds a counter rule's field as an optional int32 unless a formatter or the extras declare it, so collections apply puts it in the schema. **Service `analytics`:** `isEnabled()`, `getRules()`, `diff()` (per rule `state` missing / differs / ok, plus `unmanaged` names on the server), `apply(dryRun)` → per rule `create | replace | none | blocked`, `remove(dryRun)`, `report(handle, limit)`, `topCounted(handle, limit)`, `viewCounts(handle, ids)`, `clientConfig()`, `createEventsKey()`, `liveRules()`, static `matches()`. Apply creates a query rule's destination (`{q, count}`) first, writes with `PUT` (create or replace), compares only the declared keys, and marks a rule `blocked` — sending nothing — while its collection is not live or a counter's field is not in it. `remove` deletes only the declared rules and keeps destinations and counts. Every failure throws `SyncException`; only "not found" reads as absent. **Console** `analytics/status|apply|report|create-events-key|remove`, `--dry-run` on apply and remove, `--limit` on report. **Departure: with nothing declared or enabled, status/apply/report/remove print so and exit 0**, unlike `sync`'s 78, because an environment may switch analytics off and a deploy script must not fail on it; a config problem still exits 78, the server or a blocked rule 1. `create-events-key` prints the value once, as `TYPESENSE_EVENTS_KEY="…"`, with the config line to add. **Setup** gains an Analytics step after Collections and before Indexing (via the new base `applyRules()`), shown only when analytics is on, with a warning when a counter is declared and no events key is set. **Twig:** `craft.typesense.analyticsConfig()` → `{host, port, protocol, apiKey, rules: {<handle>: {name, collection, eventType}}}`, null without an events key, without a counter rule, or when the events key is the admin key; `craft.typesense.viewCounts(<counter rule handle>, ids)` → counts by document id, null when unreadable — **its handle is the rule's, not the collection's**. **Tests:** `tests/unit/services/AnalyticsTest.php` (17) and `tests/integration/AnalyticsTest.php` (8: TS-8 steps 1–4 — created once then "is up to date" ×3; popular and no-hits reported; two events posted with a real events-only key, whose actions are checked on the server, counted 2 and still 2 after `sync`, and read through the Twig helper; remove leaves a hand-made rule and the history — plus replace and dry run, blocked before collections exist, setup ordering, nothing declared exits 0, a config problem exits 78). BR-14 mutation-checked: dropping the counter-rule field from `getCounterFields()` fails TS-8 step 3. The test container now starts Typesense with analytics on and a raised event rate limit, and the tests force `POST /analytics/flush` (profile traps). **Not done: the utility's "analytics status" row (§6 Screens)** — not in this task's files; it can reuse `analytics->diff()`. 184 unit + 74 integration green on 8.2 + 8.4.
- [ ] **7.1 Users and categories sources** — `src/services/Targets.php`, `src/TypesenseSync.php`
      Rules: BR-1, BR-7 · Verify: TS-9
- [ ] **7.2 Commerce products source** — `src/services/Targets.php`, integration leg with Commerce
      Rules: BR-5 · Verify: TS-10
- [ ] **8.1 Examples** — `examples/config/*.php`, `examples/formatters/*.php`, `examples/alpine/*`
      Rules: BR-1, BR-25 · Verify: TS-2 runs on the base example
- [ ] **8.2 README, CHANGELOG, icons, translations, draft Store listing** — `README.md`, `CHANGELOG.md`, `src/icon.svg`, `src/icon-mask.svg`, `src/translations/en/typesense-sync.php`, `docs/store-listing.md`
      Rules: BR-25, BR-26, BR-27 · Verify: B5 #2, #3, TS-11
- [ ] **8.3 Beta** — full check green on both PHP legs, tag `1.0.0-beta.1` locally
      Rules: all · Verify: B5 #1–#3, B4

---

# Appendix A - Open questions

| # | Question | Owner | State |
|---|---|---|---|
| 1 | Price and renewal for the single edition | Sam | **Open - blocking release** |
| 2 | Create the public GitHub repo `webdna/typesense-sync`, Packagist package, and connect the Plugin Store developer account | Sam | **Open - blocking release** |
| 3 | Store listing text and screenshots (Claude drafts in 8.2) | Sam | **Open - blocking release** |
| 4 | Plugin icon: Claude drafts a simple SVG in 8.2; approve or supply one | Sam | **Open - blocking release** |

**Assumptions**

- Sam Birch approves this spec.
- Codeception with Craft's harness replaces bare PHPUnit (Sam, 30 Sep 2026); the two tiers stand.
- Nothing is emailed or notified by the plugin.
- A source indexes one site (the primary unless `site` is set); multi-site documents are later.
- Typesense Cloud and self-hosted servers are equally supported; nothing is Cloud-specific.
- LLL's module on 30 Sep 2026 (`~/Projects/lll/modules/typesense`) is the reference implementation.

---

# Appendix B - build scaffolding

## B1. Before writing anything

Read `_PROFILE.typesense-sync.md`. Everything in it applies — no PHP on the host, the containers,
the traps, commit-never-push.

## B2. Context to load

The reference implementation is LLL's module. Read it for behaviour; port the generic parts; leave
the rest (§6 migration map).

1. `~/Projects/lll/modules/typesense/models/Settings.php` — `fromArray()` :101 and `getProblems()` :389: the config shape and validator to port (task 1.2).
2. `~/Projects/lll/modules/typesense/models/SourceConfig.php` — `resolveFor()` :64: per-entry-type overrides; `CollectionConfig.php` `getVersionedName()` :91.
3. `~/Projects/lll/modules/typesense/Typesense.php` — `resolveTargetFor()` :101, event wiring :156, `registerElementEvents()` :352, `isSyncable()` :447. **Leave** :201–:270 (company fan-out) and the Stripe listener :278.
4. `~/Projects/lll/modules/typesense/services/Sync.php` — `getClient()` :47 (bounded Guzzle), `importInto()` :130, `fetchCounters()` :243, `reindexAndPrune()` :355, `staleIds()` :422, `queryForTarget()` :504 (the default target excludes entry types with their own override).
5. `~/Projects/lll/modules/typesense/services/Collections.php` — `getDesiredFields()` :41, `diff()` :151, `apply()` :213, `recreate()` :333, `normalise()` :409, `referencingCollections()` :444.
6. `~/Projects/lll/modules/typesense/services/Search.php` — `getScopedKey()` :62 and `publicationWindow()` :111 only; everything else in the file is LLL.
7. `~/Projects/lll/modules/typesense/services/Analytics.php` — `getClientConfig()` :66, `createEventsKey()` :265, `diff()` :431, `apply()` :481. **Leave** the legacy view import and the FeatureGate check.
8. `~/Projects/lll/modules/typesense/formatters/BaseFormatter.php` — `baseDocument()` :125, `harvestKeywords()` :179, `url()` :490; rename the `marketplace` doc-type field to `type`.
9. `~/Projects/lll/modules/typesense/jobs/` and `controllers/SyncController.php` — the job and CP-action shapes.
10. `~/Projects/lll/config/typesense.php` — a real config, for writing `examples/config/`.
11. `~/Projects/lll/tests/Typesense{Prune,RootRelativeUrl,UserLifecycle,UsersSource}Test.php` — behaviour to port as §7 regression tests.
12. `~/Projects/plugins/spam-blocker/codeception.yml` and `tests/` — the Codeception set-up already in use at webdna.

## B3. Guardrails

- **Do not copy an LLL file wholesale** — each carries LLL names (BR-25) and the guard grep fails the build.
- **Do not edit anything under `~/Projects/lll`** — it is a live site; adopting the plugin is separate work.
- **Do not sign any key with the admin key or pass it to Twig** — a derived key inherits admin rights and lands in page HTML (BR-17).
- **Do not prune after a run that built nothing** — it empties the collection (BR-13).
- **Do not recreate a collection without then recreating its dependants** — every join into it breaks (BR-15).
- **Do not make a network call inside an element event** — an unreachable server would block or fail editors' saves (BR-10).
- **Do not skip the draft/revision guard on the delete handler** — a CP save deletes its provisional draft and would delete the real document.
- **Do not store absolute URLs** — one cluster serves several environments.
- **Do not add a PHPStan baseline** — new code starts at zero; fix the error.
- **Do not push, or create a remote** — Sam owns GitHub, Packagist and the Store.

## B4. Definition of done

- [ ] Every AC in §7 passes
- [ ] Every BR in §5 is enforced, not merely intended
- [ ] The negative cases in §7 behave as specified
- [ ] The regression checks in §7 pass
- [ ] The test hooks in §7 exist
- [ ] B5 runs clean on PHP 8.2 and 8.4
- [ ] Every §6 migration-map row names an interface that exists

## B5. Verification

```bash
# 1. Coding standard, static analysis, unit + integration suites
docker compose run --rm php composer check
# expect: ECS "No errors found"; PHPStan "[OK] No errors"; two Codeception "OK (N tests, M assertions)" lines

# 2. No site-specific names (BR-25)
grep -rniE 'members|marketplace|lll|legacy|goodStanding|companies|Features::' src/
# expect: no output

# 3. Store-valid metadata (BR-26)
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"  (--no-plugins: craftcms/plugin-installer crashes `validate` on a relative vendor path)

# 4. Second PHP leg
PHP_VERSION=8.4 docker compose run --rm php composer check
# expect: as #1
```

| Manual check | Proves |
|---|---|
| TS-1 in a fresh Craft 5.6 site | AC-1 |
| TS-5 steps 1–3 in a browser, view-source | AC-6 |
| Utility pass: problems, diff, recreate confirm | AC-4, AC-5 |
| TS-11 steps 2–3 | AC-12 |

## B6. Out of bounds

- `~/Projects/lll` and every other site repo — read only.
- Any live or staging Typesense cluster — tests use the container and their own prefix only.
- GitHub, Packagist and the Plugin Store console — Sam's.

---

## Change log

| Date | Version | Change | By |
|---|---|---|---|
| 2026-09-30 | 0.1 | First draft, from the approved brainstorm | Claude |
| 2026-09-30 | 0.1 | Task 1.2 built; TN-14 corrected (Craft ignores unknown config keys, the plugin reports them) | Claude |
| 2026-09-30 | 0.1 | Task 2.1 built; BR-19 check is a probe plus a `value_prefix` match | Claude |
| 2026-09-30 | 0.1 | Task 2.2 built; the document-type field is `type`; products resolve in 7.2 | Claude |
| 2026-09-30 | 0.1 | Task 2.3 built; element jobs retry via `RetryableJobInterface`, dedupe flag keyed by the target's site | Claude |
| 2026-09-30 | 0.1 | Task 3.1 built; only "not found" reads as absent, nesting compared alongside the sorting field | Claude |
| 2026-09-30 | 0.1 | Task 3.2 built; BR-15 now builds every dependant before any alias moves (a join names the alias, so an old version kept does not keep it) | Claude |
| 2026-09-30 | 0.1 | Task 4.1 built; `searchConfig()` also returns `expiresAt`; a search key equal to the admin key signs nothing; analytics Twig methods move to 6.1 | Claude |
| 2026-09-30 | 0.1 | Task 5.1 built; the utility's reindex and recreate are queued, not run in the request; `Client::serverProblem()` gates them | Claude |
| 2026-09-30 | 0.1 | Task 5.2 built; flush is `sync/flush`, destructive commands also take `--confirm=<handle>`, a skipped prune exits 0 | Claude |
| 2026-09-30 | 0.1 | Task 6.1 built; analytics commands exit 0 when nothing is declared, `viewCounts()` takes a counter rule's handle, an events key equal to the admin key is a problem | Claude |
