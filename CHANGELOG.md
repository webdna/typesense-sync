# Release Notes for Typesense Sync

## Unreleased

### Added
- A collection can name the field its documents carry their type in with `typeField` (default `type`), so documents that use `type` for a field of their own keep it. `BaseFormatter`'s schema and documents both follow it, `SchemaContext::getTypeField()` gives it to a formatter's schema, and a value that is not a field name or that names another base field is reported as a problem.

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
