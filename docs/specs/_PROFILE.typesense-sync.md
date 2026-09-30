# Project profile — Typesense Sync (Craft CMS 5 plugin)

**This file is referenced from the front matter of every spec in `docs/specs/`.** It carries the
project-specific half of a spec's build contract, so individual specs can stay about their feature.

> **Agents: read this whole file before writing code.** The repo is new (30 Sep 2026). Stack,
> commands and layout below were created by the first spec's task 1.1 and are on disk. The Traps section is not invented: every entry is
> something that broke, or was guarded against, in one of the five hand-built Typesense modules
> this plugin replaces (chiefly `~/Projects/lll/modules/typesense`).

---

## 1. Stack

| Layer | Choice |
|---|---|
| Package | `webdna/typesense-sync`, type `craft-plugin`, handle `typesense-sync` |
| Namespace | `webdna\typesensesync\` → `src/` |
| CMS | Craft CMS `^5.6` |
| Language | PHP `^8.2` (CI matrix 8.2 and 8.4) |
| Typesense client | `typesense/typesense-php` `^6.0`, on Craft's Guzzle (`Craft::createGuzzleClient()`) |
| Typesense server | **30.0 or later** (analytics rule format and reference joins as of v30) |
| Tests | Codeception 5 with Craft's `craft\test\Craft` module — `unit` and `integration` suites |
| Static analysis | PHPStan 2 level 6 via `craftcms/phpstan`, **no baseline** |
| Coding standard | ECS via `craftcms/ecs` |
| Licence | Craft License (commercial, Plugin Store) |

## 2. Commands

**The host has no PHP.** Everything runs in the test containers defined by `docker-compose.yml`
(PHP 8.2 CLI, MySQL 8.0, Typesense 30.x):

```bash
docker compose up -d mysql typesense               # services for the integration suite
docker compose run --rm php composer install
docker compose run --rm php composer check         # ecs + phpstan + both codeception suites
docker compose run --rm php composer test:unit     # codecept run unit
docker compose run --rm php composer test:integration
docker compose run --rm php composer analyse       # phpstan
docker compose run --rm php composer cs            # ecs check (cs-fix to apply)
PHP_VERSION=8.4 docker compose run --rm php composer check   # the second CI leg
```

`validate` needs `--no-plugins`: with plugins loaded, `craftcms/plugin-installer` throws
"$from (./vendor/craftcms) and $to (/app) must be absolute paths". On PHP 8.4, `composer cs` prints
~150 `Deprecated:` lines from ECS 10's vendored React packages (`craftcms/ecs` pins ECS 10); they are
noise — PHP_CodeSniffer resets `error_reporting(E_ALL)` itself, so no `-d` flag silences them. Judge
the run by its exit code and "No errors found".

Manual testing against a real site uses a composer **path repository** pointing at this repo from a
throwaway Craft 5 install — never from LLL (see Traps).

## 3. Where things go

| What | Where |
|---|---|
| Plugin class, settings model | `src/TypesenseSync.php`, `src/models/Settings.php` |
| Config parsing models | `src/models/` |
| Services | `src/services/` (registered as components in the plugin class) |
| Queue jobs | `src/jobs/` |
| Formatter contract + base | `src/formatters/` |
| Events | `src/events/` |
| Console controllers | `src/console/controllers/` |
| CP controllers | `src/controllers/` |
| Utility, element action, Twig variable | `src/utilities/`, `src/elements/actions/`, `src/variables/` |
| CP templates | `src/templates/` |
| Translations | `src/translations/en/typesense-sync.php` |
| Copy-in examples (not autoloaded) | `examples/config/`, `examples/formatters/`, `examples/alpine/` |
| Tests | `tests/unit/`, `tests/integration/`, `tests/_craft/` (test site config) |
| Specs | `docs/specs/YYYY-MM-DD-<slug>.md` — **the spec is the plan; there is no `docs/plans/`** |

## 4. Verification

```bash
# 1. Everything
docker compose run --rm php composer check
# expect: ECS "No errors found", PHPStan "[OK] No errors", and both Codeception suites "OK (N tests, M assertions)"

# 2. No project-specific names leaked from LLL
grep -rniE 'members|marketplace|lll|legacy|goodStanding|companies|Features::' src/
# expect: no output

# 3. Composer metadata is Store-valid
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"
```

## 5. Traps

### Typesense

- **Typesense binds a reference field to the *versioned* collection behind an alias, not to the
  alias.** Recreating a referenced collection alone breaks every join into it ("No reference field
  found") until the referencing collections are rebuilt too. Recreate dependants, in order, in the
  same run.
- **`typesense-php` v6 ignores `connection_timeout_seconds`.** Timeouts must be set on the Guzzle
  client handed to it, or an unreachable cluster hangs a CP request or a queue worker for the PHP
  default.
- **A scoped key's embedded parameters are readable base64.** Putting a filter in a key restricts
  what can be *fetched*; it hides nothing about the filter itself. Never embed a secret in one.
- **A scoped key is not limited by collection.** Its embedded filter and `exclude_fields` are its
  only isolation, so every key needs its own filter.
- **Never sign a scoped key with the admin key** — the derived key then inherits admin rights and is
  printed into page HTML.
- **A reindex rewrites whole documents.** Counter fields (view counts) are reset to zero unless they
  are read from the live collection first and written back.
- **A prune that runs after a query returned nothing deletes the whole collection.** Skip the prune
  when the run built zero documents.

### Tooling

- **Craft 5's `TestSetup::configureCraft()` needs `CRAFT_ROOT_PATH` and `CRAFT_TESTS_PATH` defined,
  and every `CRAFT_*_PATH` directory to exist** (it `realpath()`s them). Older webdna harnesses
  (spam-blocker) omit both and fail with "Undefined constant".
- **Codeception 5 reads `bootstrap:` at the top level of `codeception.yml`.** Under `settings:` it is
  silently ignored and Craft's constants are never defined.
- **`craftcms/phpstan` does not require PHPStan** — it only suggests it. `phpstan/phpstan` is its
  own dev requirement.

### Craft

- **Destructive element handlers must skip drafts and revisions.** A CP save hard-deletes its
  provisional draft, which fires the same delete event as a real delete; unguarded, that deleted
  real content in LLL.
- **User status changes write the `users` table without saving the element.** Activate, suspend,
  lock, unlock and group assignment fire no save event, so an index fed only by saves goes stale.
- **A bulk resave sets `resaving`**, and syncing on it queues one job per element. Skip it; a full
  reindex is the tool for that.
- **Nested entries (Matrix) have no section.** Resolving them as sources indexes fragments of pages.
- **Absolute URLs in documents break multi-environment clusters.** One Typesense cluster commonly
  serves every environment; store root-relative URLs.
- **Craft applies `config/<handle>.php` over the plugin's Settings model**, and an unknown key is an
  error. Every key the config file may carry must be a declared Settings property.
- **A static or page cache that outlives a scoped key's TTL** serves an expired key and search
  silently fails for anonymous visitors.

## 6. Working conventions

- **Commit, never push.** Sam pushes and owns the GitHub repo, Packagist and the Plugin Store
  listing. The repo has no remote until Sam creates one.
- One spec task per session; tick the spec, commit, then hand over.
- **Do not touch `~/Projects/lll` from this repo's work.** LLL adopting the plugin is a separate,
  later piece of work; reading it for reference is fine.
