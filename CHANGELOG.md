# Release Notes for Typesense Sync

## 1.1.0 - 2026-11-05

### Added
- A **Typesense** section in the control panel, with a page per declared collection, where editors manage rules and synonyms. It needs the new *Manage Typesense rules* permission and appears only while the connection is configured.
- Rules: for a search term, matched exactly or contained, pin up to 20 entries in order and hide up to 100, with optional start and end dates. *Also match synonyms* makes a rule fire for its term's synonyms too. Drag to set which rule wins, and switch a rule off without deleting it. Pinned entries still obey the search key's publication window and filters.
- Synonym groups: all equal, or one way from a main term.
- Craft holds the rules and Typesense holds a copy. The plugin owns one curation set and one synonym set per collection, pushes them on every save, links them to every collection version it builds, and keeps any set someone linked by hand. An edit saved while Typesense is unreachable is kept and pushed again from the queue.
- Each collection shows whether Typesense matches Craft (not live yet, or out of step after a dashboard edit), in the section, the utility and `collections/status`. `collections/apply` and `setup` put Craft's version back.
- `rules/export` and `rules/import` move a collection's rules and synonyms between environments, matching entries by uid, then by section and slug, and listing anything they cannot find.
- `rules/adopt` brings a curation or synonym set made by hand in Typesense under the plugin, listing any item that uses an option the section does not offer, and unlinks the original without deleting it.
- The utility lists rows left by a collection no longer declared, and `rules/prune` deletes them.

### Changed
- Uninstalling drops the plugin's rules and synonyms tables. The sets stay in Typesense, linked, so search is unchanged; the README says how to remove them.

## 1.0.1 - 2026-10-23

### Changed
- New plugin icon: a white glyph on a black circle, with a white icon mask for the control panel nav.

## 1.0.0 - 2026-10-19

### Fixed
- Saving the plugin settings no longer fails with an error when a connection setting names an environment variable that is set but empty; the empty value is saved like any other.
- *Test connection* and `setup` no longer refuse a correct search-only API key because another key on the server starts with the same four characters. A key that genuinely allows more than search is still refused.

## 1.0.0-beta.2 - 2026-10-01

### Added
- A collection can name the field its documents carry their type in with `typeField` (default `type`), so documents that use `type` for a field of their own keep it. `BaseFormatter`'s schema and documents both follow it, `SchemaContext::getTypeField()` gives it to a formatter's schema, and a value that is not a field name or that names another base field is reported as a problem.
- `Search::EVENT_DEFINE_SCOPED_KEY` is cancellable: a handler that sets `$event->isValid = false` refuses the key, and `scopedKey()`, `searchConfig()` and `scopedKeyParams()` return null, so a page fails closed when a filter its key must carry cannot be built.

## 1.0.0-beta.1 - 2026-09-30

### Added
- Connection settings in the control panel, environment-variable aware, with a *Test connection* button that checks the server version (30.0 or later) and refuses a search-only key that can do more than search.
- Collections, sources and analytics declared in `config/typesense-sync.php`, validated in full: unknown keys, undeclared collections, missing formatters, conflicting fields, clashing names and reference cycles are all reported.
- Sources for sections (with per-entry-type overrides), category groups, users and Craft Commerce product types. The Commerce source is optional and warns rather than fails when Commerce is absent.
- The index follows content: saves, disables, deletes and restores queue a deduplicated sync, as do user status and group changes. Drafts, revisions, bulk resaves and nested entries are skipped, and a save never fails because of Typesense.
- `BaseFormatter`, `FormatterInterface` and `SchemaContext` for turning elements into documents, with root-relative URLs and a publication window that needs no cron.
- Reindex with optional prune (skipped when a run builds nothing), and counter fields kept through every reindex.
- Apply (create, or alter in place) and recreate (rebuild beside the live version behind an alias, then swap), with collections that reference each other rebuilt together in dependency order.
- Short-lived scoped search keys with the publication window, a caller's filter and excluded fields embedded, and `craft.typesense` for templates, which returns null instead of throwing.
- Search analytics: popular searches, searches with no results, and view counters, applied as a diff; an events-only key command; and a report.
- Utilities → Typesense Sync: connection, problems, each collection's version, document count and schema difference, analytics rule states, and Apply, Reindex and Recreate actions.
- A "Sync to Typesense" element action and edit-screen menu item.
- Console commands: `setup`, `sync`, `sync/element`, `sync/flush`, `collections/status|apply|recreate` and `analytics/status|apply|report|create-events-key|remove`, each exiting non-zero on failure.
- Events: `Sync::EVENT_BEFORE_INDEX_DOCUMENT`, `Sync::EVENT_AFTER_SYNC`, `Sync::EVENT_AFTER_DELETE_DOCUMENT`, `Targets::EVENT_RESOLVE_TARGET`, `Targets::EVENT_REGISTER_ELEMENT_TYPES` and `Search::EVENT_DEFINE_SCOPED_KEY`, plus `sync->queueElement()`.
- Copy-in examples: configs, formatters and Alpine.js search and view-counter components.
