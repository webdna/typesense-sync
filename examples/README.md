# Examples

Copy-in starting points. None of this is loaded by the plugin. A site copies what it needs and
makes it its own, and these files are not a supported API: they can change between releases
without notice.

The formatters and the config snippets are exercised by the plugin's own test suite. The Alpine
components are not.

## Config — `config/`

| File | What it declares |
|---|---|
| `typesense-sync.php` | **Start here.** A complete config: one `content` collection fed by one section. Copy to `config/typesense-sync.php`. |
| `users.php` | A `people` collection fed by Craft users, following status changes without a save |
| `categories.php` | A category group in its own collection |
| `products.php` | A Craft Commerce product type (a warning, not an error, where Commerce is absent) |
| `analytics.php` | Popular searches, searches with no results, and a view counter |
| `joins.php` | A complete config in which content joins to its authors in `people` |

Each snippet returns only the keys it adds. Merge them into your `config/typesense-sync.php`:
collections by handle, sources appended to the list, `analytics` as a key of its own.

## Formatters — `formatters/`

| Class | For |
|---|---|
| `ContentFormatter` | Entries: summary, image, section |
| `ArticleFormatter` | Entries joined to their author (extends `ContentFormatter`) |
| `UserFormatter` | Active users with a name; no email address, no username |
| `CategoryFormatter` | Categories: group, parent titles, level |
| `ProductFormatter` | Commerce products: price, SKU, availability of the default variant |

They are in the namespace `modules\search\formatters`. With Craft's default
`"modules\\": "modules/"` autoload, put them in `modules/search/formatters/`; otherwise change the
namespace to match where they go.

## Search page — `alpine/`

| File | What it is |
|---|---|
| `search.js` + `search.twig` | A search box and results list, fed by `craft.typesense.searchConfig()` |
| `view-counter.js` + `view-counter.twig` | Posts one view event to a counter rule, fed by `craft.typesense.analyticsConfig()` |

They call Typesense's REST API with `fetch` and need Alpine.js 3 and nothing else. Register each
with `Alpine.data()` as its header comment shows.
