# Plugin Store listing — draft

**Status:** draft by Claude, 30 Sep 2026. Sam owns the final text, the screenshots and the price
(spec Appendix A, #1, #3 and #4). Nothing here is published until Sam submits it.

## Fields

| Field | Draft |
|---|---|
| Name | Typesense Sync |
| Handle | `typesense-sync` |
| Package | `webdna/typesense-sync` |
| Short description | Keep your content in a Typesense search index, with safe, filtered search keys for your pages. |
| Categories | Search · Developer Tools |
| Keywords | typesense, search, index, instant search, search keys, analytics |
| Edition | One edition, paid (price: **Sam — open**) |
| Licence | Craft License |
| Requires | Craft CMS 5.6+, PHP 8.2+, Typesense 30.0+ |
| Documentation URL | https://github.com/webdna/typesense-sync/blob/main/README.md |
| Changelog URL | https://raw.githubusercontent.com/webdna/typesense-sync/main/CHANGELOG.md |
| Repository | https://github.com/webdna/typesense-sync (**Sam — to create**) |
| Icon | `src/icon.svg` (draft: a magnifier holding two sync arrows — **Sam to approve or replace**) |

## Long description

> Typesense Sync keeps your Craft content in a [Typesense](https://typesense.org) search index and
> gives your search pages a safe, short-lived key. You declare what to index and what a result
> looks like. The plugin does the rest.
>
> ### The index follows your content
>
> When an editor publishes, disables, deletes or restores an entry, the change reaches search
> within moments, through Craft's queue. User status changes are followed too, without anyone
> saving the user. Nothing is indexed unless you declare it, and a save never fails because the
> search server is down.
>
> ### Change the schema without taking search down
>
> The utility shows how each collection differs from what your code declares. Apply the
> difference in place, or recreate the collection: a new version is built beside the live one, and
> search switches to it only when it is complete. Collections that join to one another are rebuilt
> together, in the right order, so joins keep working.
>
> ### Search keys that can only read what the page may show
>
> `craft.typesense.searchConfig('content')` hands your search page everything it needs, including
> a key that can only search, expires within the hour, never returns pending or expired entries,
> and never returns the fields you mark private. The admin key never reaches a template.
>
> ### Built in, off until you need it
>
> - Users, categories and Craft Commerce products as sources
> - Popular searches, searches with no results, and view counters that survive every reindex
> - Collections that reference each other, with joined searches
> - Copy-in example configs, result classes and Alpine.js search components
>
> ### For developers
>
> Configuration lives in `config/typesense-sync.php`, so it is versioned with your code, and every
> key is validated: a typo is reported instead of silently ignored. Each kind of result is a small
> PHP class. Events let you add fields, exclude elements, reroute content and narrow search keys.
> Console commands cover setup, reindexing and rebuilds, with exit codes a deploy script can trust.
>
> Requires Typesense 30.0 or later, self-hosted or Typesense Cloud.

## Screenshots to take (Sam)

1. Utilities → Typesense Sync with two collections: one up to date, one out of date with its field
   difference listed.
2. The recreate confirmation (typed handle), with its dependants named.
3. Plugin settings with environment variables and a successful *Test connection*.
4. The analytics pane, with its rules up to date.
5. A search page built from `examples/alpine/search.twig`.

## Before submitting

- [ ] Price and renewal (Appendix A #1)
- [ ] GitHub repo, Packagist and the developer account connected (Appendix A #2)
- [ ] Text reviewed and screenshots taken (Appendix A #3)
- [ ] Icon approved (Appendix A #4)
- [ ] `1.0.0` tagged with a matching `CHANGELOG.md` heading
