---
spec: Curations and synonyms in the CP
slug: typesense-rules
status: approved
version: 1.0.1
date: 2026-10-29
author: Claude, with Sam Birch
client: webdna (Plugin Store product); first site St Monica Trust
approver: Sam Birch
profile: _PROFILE.typesense-sync.md
related: [2026-09-30-typesense-sync.md]
---

# Curations and synonyms in the CP

> **Status:** approved - **Version:** 1.0.1 - **Profile:** `_PROFILE.typesense-sync.md`
> Editors can decide what a search shows (pin, hide, schedule) and which words count as the same,
> from the Craft control panel, without a developer opening the Typesense dashboard.

---

## 1. How it works

A new **Typesense** section in the control panel has one page per search collection the site
declares. On it an editor writes **rules** ("when someone searches *garden*, show the Garden Room
page first and the Bath village second, and never show the old recruitment page") and **synonyms**
("*care home*, *nursing home* and *residential care* mean the same thing"). Saving makes the change
live in search straight away. A rule can be given dates, so a seasonal promotion starts and stops on
its own.

The idea it rests on: **Craft holds the rules; Typesense holds a copy.** The plugin owns exactly one
rule set and one synonym set per collection, creates them, attaches them to the collection, and
re-attaches them every time the collection is rebuilt. Because Craft is the record, a cluster that is
wiped or rebuilt gets its rules back from one command, rules travel between environments by export
and import, and a change someone makes directly in the Typesense dashboard is shown as "out of step"
and overwritten by the next save.

Developers have two console tools around it: **export / import**, to move a collection's rules and
synonyms from staging to production, and **adopt**, to bring a set someone already made by hand in
Typesense under the plugin's management.

Rejected: letting editors create named sets and choose which collections each applies to (Typesense's
own model). It is more flexible and adds a linking step editors can get wrong; one set per collection
covers every known site. Rejected too: editing Typesense directly from the CP with nothing stored in
Craft — less code, but a collection rebuild or a new environment would lose every rule.

**Vocabulary**

| Term | Means |
|---|---|
| Collection | One searchable index the site declares (for St Monica, its single site search). |
| Rule | "When the search is *X*, pin these, hide those." Typesense calls it a *curation*. |
| Pin | Put an entry at a fixed position at the top of a search's results. |
| Hide | Keep an entry out of a search's results, however well it matches. |
| Synonym group | Words that count as the same. *All equal*: each finds the others. *One way*: the main term finds the words, not the reverse (Typesense's `root`). |
| Out of step | What Typesense holds differs from what Craft holds — usually someone edited it in the dashboard. |
| Adopt | Copy a set made by hand in Typesense into a collection's rules or synonyms, and stop using the original. |

---

## 2. Scope

**In scope**

- The Typesense CP section: per collection, a Rules tab and a Synonyms tab — add, edit, reorder,
  switch off and delete.
- A rule: the search term, whether it must match exactly or only be contained, pinned entries in
  order, hidden entries, optional start and end, on/off.
- A synonym group: all equal, or one way to a main term.
- One permission, *Manage Typesense rules*.
- Keeping the sets attached through every collection rebuild, and restoring them from Craft with the
  existing apply command and in setup.
- An "in step / out of step / not live yet" state per collection, in the section and the utility.
- Console export and import, matching entries across environments.
- Console adoption of an existing Typesense set.

**Out of scope**

| Not doing | Why |
|---|---|
| Advanced rule options (replace the query, add a filter or sort, tags, "keep looking after this rule", custom metadata, rules triggered by a filter, result diversity) | Sam, 29 Oct: editors get the simple rule only. A developer who needs the rest still has the Typesense dashboard — on a set the plugin does not own. |
| Named or shared sets across collections | One per collection (§1). |
| Language-specific synonyms and indexed symbols | Simple scope; no known site needs them. |
| Notifications | Nothing is sent: a rule change is the editor's own act. |
| Search analytics in the section | Analytics already has its own config and report command. |
| Per-site rules inside one collection | A collection is already per site in the plugin's model. |

**Later**

- A try-a-search preview on the Rules tab, showing results with the rules applied.
- Change history: who changed which rule, and the previous version.

---

## 3. User journeys

**Who this is for**

| Group | What they get |
|---|---|
| Content editors (granted the permission) | Control over what key searches show, and synonyms, without a ticket. |
| Developers / webdna | No more hand-editing Typesense per request; rules move between environments by command. |
| Site visitors | Better results for the searches that matter. Nothing else changes for them. |
| Site admins | **Affected:** one more permission to grant, and one more thing the utility can report out of step. |

**1. Pin and hide for a search**
   1. The editor opens **Typesense** in the CP nav and picks the collection (with one collection, it
      opens straight on it).
   2. On **Rules**, they choose **New rule**, type the search term and choose *Exact* or *Contains*.
   3. Under **Pin**, they pick entries; the order they are listed is the order they appear.
   4. Under **Hide**, they pick entries that must never show for that search.
   5. Optionally, they set **Starts** and **Ends**.
   6. They save. The rule is live; searching the site for the term shows the pinned entries first.

**2. Reorder or pause rules**
   1. On **Rules**, the editor drags a rule above another. When two rules could match one search, the
      higher one wins.
   2. They switch a rule off; it stops applying but is kept.

**3. Add synonyms**
   1. On **Synonyms**, the editor chooses **New group**, picks *All equal* or *One way*.
   2. They enter the words (and for one way, the main term) and save. Searching for any of the words
      now finds pages that use the others.

**4. Move rules to production** (developer)
   1. On staging, the developer exports a collection's rules and synonyms to a file.
   2. On production, they import the file and confirm by typing the collection's name; it replaces
      that collection's rules and synonyms there.
   3. The import lists anything it could not match on production (an entry that does not exist
      there) and leaves it out.

**5. Adopt a hand-made set** (developer)
   1. The developer runs the adopt command, naming the collection and the set made in Typesense.
   2. Every item the CP can show is copied into the collection's rules; anything using an advanced
      option is listed and left out.
   3. The collection stops using the old set; the old set itself is kept in Typesense.

**First run - the empty state.** A collection with no rules shows one sentence on what a rule does
and a **New rule** button; Synonyms likewise. If the site declares no collections, the section says so
and points at the utility. Someone without the permission does not see the section at all.

---

## 4. Data model

**`typesensesync_rules`**

| Field | Type | Notes |
|---|---|---|
| id, dateCreated, dateUpdated, uid | Craft standard | |
| collection | string(64) | The collection **handle** from config, never a Typesense name. |
| sortOrder | smallint | 1-based within the collection. Earlier wins. |
| query | string(255) | Trimmed. |
| matchType | enum `exact`, `contains` | |
| pins | json | Ordered list of element ids. Position = index + 1. |
| hides | json | List of element ids. |
| effectiveFrom, effectiveTo | datetime, null | UTC. |
| enabled | bool | Default true. |

Unique: (`collection`, lower(`query`), `matchType`) — enforced in validation (BR-6), indexed on
(`collection`, `sortOrder`).

**`typesensesync_synonyms`**

| Field | Type | Notes |
|---|---|---|
| id, dateCreated, dateUpdated, uid | Craft standard | |
| collection | string(64) | Handle. |
| sortOrder | smallint | Display order only; synonyms have no precedence. |
| root | string(255), null | Null = all equal; set = one way to this term. |
| words | json | List of strings, 1-50 (BR-10). |
| enabled | bool | |

**Why this shape.** Rows are content, not configuration: editors change them on production, where
project config is read-only, so they live in plugin tables and never in project config. Pins and hides
are **element ids**, not document ids — the document id is the collection formatter's
`documentId()`, which a site may override, so it is computed at push time. The Typesense item ids
(`rule-0001`…, `synonym-0001`…) are **derived** from `sortOrder` on every push, never stored:
Typesense evaluates rules in lexical id order, so the id is the ordering. "In step" and "not live
yet" are **derived** by comparing what Craft would push with what Typesense holds; nothing records a
push, so the state cannot itself drift.

**On deletion.** An element that is pinned or hidden and later deleted stays in the row until the rule
is next saved and is skipped at push (BR-8). Removing a collection from config leaves its rows; the
section stops listing them and the utility names them as orphaned (BR-21). Uninstalling drops both
tables and leaves the Typesense sets attached (BR-20).

**States** (per collection, per kind — derived)

| State | When | Shown as |
|---|---|---|
| In step | Typesense's set equals the desired set and the live collection links it | Nothing (quiet) |
| Not live yet | The last push failed, or the set or link is missing | Warning with *Push now* |
| Out of step | Typesense's set differs from the desired one | Warning naming the difference; next save or apply overwrites |
| Unknown | Typesense unreachable | "Can't check — Typesense is unreachable" |

---

## 5. Rules

| # | Rule |
|---|---|
| BR-1 | For every declared collection the plugin owns one curation set named `<live alias>_rules` and one synonym set named `<live alias>_synonyms`, so the collection prefix separates environments sharing a cluster. Both are created even when empty. |
| BR-2 | Every collection version the plugin creates — create, recreate, the dependants of a recreate — carries both owned sets in `curation_sets` / `synonym_sets`, **plus every other set the live version already links**, so a rebuild never drops a hand-made link. |
| BR-3 | `diff()` reports a missing owned link as a change and `apply()` restores it with a PATCH of the set lists alone — no reindex advised. A foreign link (not owned) is never removed by apply; the utility lists it as "also linked". |
| BR-4 | A save, reorder, switch or delete in the CP writes the row(s) and then PUTs the collection's **whole** set to Typesense. A failed push does not lose the edit: the row is saved, the editor sees "Saved, not live yet: <reason>", and a retryable queue job pushes again (the existing retry pattern, TTR as backoff). |
| BR-5 | Rules are pushed in `sortOrder` with ids `rule-0001`, `rule-0002`… (four digits, zero-padded); every item has `stop_processing: true`, so the first matching rule in the editor's order is the only one applied. A disabled rule is not pushed. Synonym ids are `synonym-0001`…; a disabled group is not pushed. |
| BR-6 | A rule needs a query (1-255 characters after trimming), a match type, and at least one pin or hide. Two rules in a collection with the same query (case-insensitive) and match type are refused. |
| BR-7 | Up to 20 pins and 100 hides per rule. An element may not be both pinned and hidden in one rule. |
| BR-8 | Only elements that feed the collection may be pinned or hidden: the pickers offer exactly the sources declared for it, and a save naming any other element is refused. At push, each element is turned into its document id by the collection's formatter; an id that no longer resolves to an element is skipped and logged, not an error. |
| BR-9 | **Every pushed rule sets `filter_curated_hits: true`.** Without it a pinned document is shown even when the search's filter — including a scoped key's publication window and isolation filter — excludes it (verified on 30.0, 29 Oct 2026). Adoption forces it on too. |
| BR-10 | A synonym group needs 2-50 words of 1-255 characters (one way: 1-50 words plus a main term); words are trimmed, blanks dropped, duplicates within a group removed. |
| BR-11 | Starts and Ends are stored in UTC and pushed as `effective_from_ts` / `effective_to_ts`. Ends must be after Starts. A rule past its end is still pushed (Typesense ignores it) and listed as *Ended*; one not yet started as *Scheduled*. |
| BR-12 | **A set is never deleted while any collection links it.** Every delete the plugin performs unlinks first (PATCH), then deletes. Deleting a still-linked synonym set makes every search on that collection fail with 404 "Synonym index not found" (verified on 30.0). |
| BR-13 | One permission, `typesense-sync:manageRules`, label *Manage Typesense rules*. It shows the **Typesense** CP nav item and every rules/synonyms screen and action; admins always have it. Each controller action checks it; a request without it gets 403. |
| BR-14 | Craft is the source of truth. The section and the utility show each collection's state (§4 States); a set changed in the Typesense dashboard is shown as out of step, naming how many items differ, and the next CP save or `collections/apply` overwrites it. Nothing ever writes Typesense's version back into Craft except adopt (BR-18). |
| BR-15 | `collections/apply`, `setup` and the utility's apply push every collection's sets and links. `collections/status` and the utility report the state. A `--dry-run` reports without writing. |
| BR-16 | Export writes one JSON document per collection: rules and synonyms in order, each pinned or hidden element as `{uid, type, site, section/group, slug}`. The format carries a `version: 1` key. |
| BR-17 | Import resolves each element by uid, then by type + site + section/group + slug; an element it cannot resolve is left out and listed. Import **replaces** the collection's rules and synonyms, so it needs `--confirm=<handle>` (or the typed handle) like the other destructive commands, then pushes (BR-4). Exit 0 with a list of anything left out; 64 on a bad file; 78 on bad config. |
| BR-18 | Adopt copies a named Typesense set's items into the collection, appended after existing rows, then pushes and links the owned set, then unlinks the source set from that collection. It never deletes the source set. An item using anything outside the simple rule (§2) — a filter-triggered rule, tags, replace query, filter or sort, metadata, diversity, `stop_processing: false`, a synonym locale or symbols — is skipped and listed. An include or exclude whose id is not an element feeding the collection is dropped and listed. |
| BR-19 | The Typesense nav item and section exist only while the connection is configured; otherwise the section shows the utility's unconfigured message. A collection page reads Typesense only for the state — the rules themselves always render from Craft, so an unreachable cluster never blocks editing. |
| BR-20 | Uninstall drops both tables and leaves the Typesense sets attached and intact (BR-12 forbids deleting them while linked, and stripping links would change live search on uninstall). The README says how to remove them by hand. |
| BR-21 | Rows for a collection no longer declared are kept and not shown in the section; the utility lists them as orphaned, and `typesense-sync/rules/prune <handle> --confirm=<handle>` deletes them. |
| BR-22 | Every new user-facing string goes through `Craft::t('typesense-sync', …)` and into the translations file; log lines stay English, as in the 1.0 spec. |

**Non-functional**

- A push is one request per set per save; sets stay small (tens of items), so it is synchronous.
- Accessibility: the screens are Craft's own components (element selects, editable table, lightswitch,
  date pickers, admin table with drag reorder) and inherit their accessibility.
- Server: Typesense 30.0 or later, unchanged; the client is `typesense-php` 6, which already has
  `curationSets` and `synonymSets`.

---

## 6. Interfaces

| Method | Path | Purpose | Auth | Returns |
|---|---|---|---|---|
| GET | `admin/typesense` | Section index: collections with rule/synonym counts and state; with one collection, redirects to it | `manageRules` | CP page |
| GET | `admin/typesense/<collection>/rules` | Rules tab | `manageRules` | CP page |
| GET | `admin/typesense/<collection>/rules/new`, `…/rules/<id>` | Rule edit | `manageRules` | CP page |
| GET | `admin/typesense/<collection>/synonyms` (+ `new`, `<id>`) | Synonyms tab and edit | `manageRules` | CP page |
| POST | `typesense-sync/rules/save`, `delete`, `reorder`, `toggle` | Rule actions | `manageRules` + CSRF | Redirect with notice, or JSON for reorder/toggle |
| POST | `typesense-sync/synonyms/save`, `delete`, `reorder`, `toggle` | Synonym actions | `manageRules` + CSRF | As above |
| POST | `typesense-sync/rules/push` | Push one collection's sets now (*Push now*) | `manageRules` + CSRF | Redirect with result |
| CLI | `typesense-sync/rules/export <handle> [--file]` | BR-16 | shell | JSON, exit 0 / 78 |
| CLI | `typesense-sync/rules/import <file> [--confirm]` | BR-17 | shell | exit 0 / 64 / 78 / 1 |
| CLI | `typesense-sync/rules/adopt <handle> --curation-set=<n> \| --synonym-set=<n>` | BR-18 | shell | exit 0 / 64 / 78 / 1 |
| CLI | `typesense-sync/rules/prune <handle> [--confirm]` | BR-21 | shell | exit 0 / 64 |

`collections/apply`, `collections/status`, `setup` and the utility gain the sets (BR-15); no new
utility actions.

**Screens**

| Surface | New or reuse | Notes |
|---|---|---|
| **Typesense** nav item + section | New | Craft's CP section; tabs Rules / Synonyms per collection; collection switcher when there are several. |
| Rules list | New | Admin table, drag to reorder; columns: term, match, pins count, hides count, status (On / Off / Scheduled / Ended), dates. |
| Rule edit | New | Term, Exact/Contains, Pin (element select, sortable, max 20), Hide (element select), Starts, Ends, Enabled. |
| Synonyms list + edit | New | Type (All equal / One way), main term (one way), words (editable table). |
| State banner | New | Top of each collection page (§4 States), with *Push now*. |
| Utility | Reuse | Adds a per-collection rules/synonyms state row, "also linked" sets and orphaned rows. |

**Design source.** No supplied design: the developer extends Craft's own CP patterns, as the
utility and settings screen did.

**Copy ownership.** Claude drafts every label, empty state and message; Sam reviews (as for 1.0).

---

## 7. Test plan

### Client-verified criteria

| # | Given / When / Then | Proved by |
|---|---|---|
| AC-1 | Given a rule pinning A then B for "garden", when a visitor searches "garden", then A is first and B second. | TS-1 |
| AC-2 | Given a rule hiding C for "garden", when a visitor searches "garden", then C is not in the results. | TS-1 |
| AC-3 | Given a rule with Starts tomorrow, when a visitor searches its term today, then the rule does not apply; it shows as Scheduled. | TS-2 |
| AC-4 | Given two rules that both match a search, then only the one higher in the list applies; dragging the other above it swaps that. | TS-3 |
| AC-5 | Given a synonym group "care home / nursing home", when a visitor searches "care home", then a page saying only "nursing home" is found. | TS-4 |
| AC-6 | Given rules on a collection, when the collection is rebuilt, then every rule and synonym still applies. | TS-5 |
| AC-7 | Given a rule edited in the Typesense dashboard, then the section shows the collection out of step, and the next save puts Craft's version back. | TS-6 |
| AC-8 | Given rules exported on one environment, when imported on another, then the same entries are pinned and hidden there, and anything missing is listed. | TS-7 |
| AC-9 | Given a hand-made set in Typesense, when adopted, then its items appear as rules in the CP, advanced items are listed, and search behaves as before. | TS-8 |
| AC-10 | A user without *Manage Typesense rules* sees no Typesense nav item and cannot reach the screens; a user with it can. | TS-9 |
| AC-11 | With Typesense unreachable, an editor can still open, edit and save rules; the page says the change is not live yet, and it goes live once Typesense is back. | TS-10 |
| AC-12 | A pinned entry that is not published (future post date or expired) is never shown to visitors. | TS-11 |

### Test data and preconditions

- Test site (exists): a section feeding a declared collection, entries made per test. **New:** a second
  section **not** feeding the collection (for BR-8), and a fixture user without the permission (TN-1).
- Typesense 30.0 container (exists). Each integration test uses its own collection prefix and
  `TestCollections::deleteAll()`; **new**: that helper also deletes the prefix's curation and synonym
  sets, unlinking first (BR-12).
- A scoped search key per test (existing pattern, `SearchTest`).
- craft5 sandbox (exists) for the CP browser pass, with typesense-sync installed and the `landing`
  collection declared.

### Scenarios

**TS-1 - Pin and hide** - *covers AC-1, AC-2; BR-1, BR-4, BR-5, BR-8, BR-9* - Type: integration

| # | Step | Expected |
|---|---|---|
| 1 | Make entries A, B, C titled with "garden"; sync | Search "garden" returns all three |
| 2 | Save a rule: "garden", Exact, pin B then A, hide C | Save succeeds; the curation set holds one item `rule-0001` with includes B@1, A@2, excludes C, `filter_curated_hits: true` |
| 3 | Search "garden" with the scoped key | Hits begin B, A; C absent |

*Success criterion: step 3's order is exactly B, A and C is absent.*

**TS-2 - Dates** - *covers AC-3; BR-11* - Type: integration (+ CP browser for the label)

| # | Step | Expected |
|---|---|---|
| 1 | Save a rule pinning B for "hall" with Starts = tomorrow | Item carries `effective_from_ts` = tomorrow, UTC |
| 2 | Search "hall" | B is not forced first |
| 3 | Open the Rules tab | The rule's status reads Scheduled |

*Success criterion: the rule has no effect before its start, and says so.*

**TS-3 - Order and switching off** - *covers AC-4; BR-5* - Type: integration

| # | Step | Expected |
|---|---|---|
| 1 | Rule 1 "garden" Contains pins A; rule 2 "garden" Exact pins B | Ids `rule-0001` (A), `rule-0002` (B) |
| 2 | Search "garden" | A first |
| 3 | Reorder: rule 2 above rule 1 | Ids swap; search "garden" puts B first |
| 4 | Switch rule 2 off | It is not in the set; A first again |

*Success criterion: only the higher enabled rule applies, at every step.*

**TS-4 - Synonyms** - *covers AC-5; BR-1, BR-5, BR-10* - Type: integration

| # | Step | Expected |
|---|---|---|
| 1 | Entry D titled "nursing home"; search "care home" | D not found |
| 2 | Save an All equal group "care home", "nursing home" | Synonym set holds `synonym-0001` with both words, no root |
| 3 | Search "care home" | D found |
| 4 | Change it to One way, main term "care home", words "nursing home"; search "care home" | D found; searching "nursing home" does not find a page saying only "care home" |

*Success criterion: steps 3 and 4 match the group type.*

**TS-5 - Survives a rebuild** - *covers AC-6; BR-2, BR-3, BR-15* - Type: integration

| # | Step | Expected |
|---|---|---|
| 1 | Rule and synonym group saved on the collection; also link a hand-made set `<prefix>_extra` to the live version | — |
| 2 | `collections/recreate` | New version's schema lists `_rules`, `_synonyms` and `_extra` |
| 3 | Repeat TS-1 step 3 and TS-4 step 3 | Both still hold |
| 4 | PATCH the live version to drop the `_rules` link; `collections/status` | Reports the missing link; `apply` restores it with no reindex advice |

*Success criterion: no rule, synonym or foreign link is lost by a rebuild.*

**TS-6 - Out of step** - *covers AC-7; BR-14, BR-15* - Type: integration + CP browser

| # | Step | Expected |
|---|---|---|
| 1 | Rule saved; then PUT a different item into `_rules` directly | — |
| 2 | Open the collection page | Banner: out of step, 1 item differs |
| 3 | Save any rule | Typesense set equals Craft's again; banner gone |

*Success criterion: drift is shown and the next save removes it.*

**TS-7 - Export and import** - *covers AC-8; BR-16, BR-17* - Type: integration (console)

| # | Step | Expected |
|---|---|---|
| 1 | Two rules and a group; `rules/export <h> --file` | JSON with `version: 1`, elements by uid + slug |
| 2 | Delete the rows; change one pinned entry's uid in the file, delete another entry | — |
| 3 | `rules/import <file> --confirm=<h>` | Rows recreated in order; the slug-matched entry kept; the deleted one listed and left out; exit 0; set pushed |
| 4 | Import without `--confirm` and answer wrongly | Nothing changed, exit 64 |

*Success criterion: rules match except listed leftovers.*

**TS-8 - Adopt** - *covers AC-9; BR-18* - Type: integration (console)

| # | Step | Expected |
|---|---|---|
| 1 | PUT a hand-made set `<prefix>_old` with a simple item, an item using `replace_query`, and an include of a non-element id; link it | — |
| 2 | `rules/adopt <h> --curation-set=<prefix>_old` | One rule created; the advanced item and the bad id listed; exit 0 |
| 3 | Read the live schema and the old set | `_rules` linked, `_old` unlinked, `_old` still exists |
| 4 | Search the simple item's term | Same pinned result as before adoption |

*Success criterion: search behaves the same and the CP shows the rule.*

**TS-9 - Permission** - *covers AC-10; BR-13, BR-19* - Type: integration (web controller) + CP browser

| # | Step | Expected |
|---|---|---|
| 1 | As the fixture user without the permission, GET each CP route and POST each action | 403 each; nav has no Typesense item |
| 2 | Grant the permission | Nav item shows; save works |

*Success criterion: access matches the permission exactly.*

**TS-10 - Typesense unreachable** - *covers AC-11; BR-4, BR-14, BR-19* - Type: integration (mocked client) + CP browser

| # | Step | Expected |
|---|---|---|
| 1 | Point the client at an unreachable host; open the collection page | Rules listed; banner "Can't check" |
| 2 | Save a rule | Row saved; notice "Saved, not live yet"; a push job queued |
| 3 | Restore the host; run the queue | Set pushed; state in step |

*Success criterion: no edit is lost and none is blocked.*

**TS-11 - Pins respect the scoped key** - *covers AC-12; BR-9* - Type: integration

| # | Step | Expected |
|---|---|---|
| 1 | Entry E with a future post date, indexed; rule pins E for "garden" | — |
| 2 | Search "garden" with a scoped key carrying the publication window | E absent |

*Success criterion: E is never returned. Mutation-check: with `filter_curated_hits` false the test must fail.*

### Negative and edge cases

| # | Condition | Expected behaviour |
|---|---|---|
| TN-1 | No permission, direct POST to any rules/synonyms action | 403; nothing written (BR-13) |
| TN-2 | Two rules, same query differing only in case, same match type | Second refused with a field error (BR-6) |
| TN-3 | 21 pins, or one element pinned and hidden | Refused (BR-7) |
| TN-4 | Pin an element from a section not feeding the collection (crafted POST) | Refused (BR-8) |
| TN-5 | A pinned entry is deleted, then any rule saved | Push skips it, logs one line, no error (BR-8) |
| TN-6 | Ends before Starts | Refused (BR-11) |
| TN-7 | Synonym group with one word, or 51 | Refused (BR-10) |
| TN-8 | The plugin deletes a set (prune, test cleanup) | Unlinked first; search on the collection still answers 200 (BR-12) |
| TN-9 | A formatter overriding `documentId()` | Pins are pushed under the overridden id (BR-8) |
| TN-10 | Collection removed from config with rows left | Section hides it; utility lists it orphaned; prune deletes after confirm (BR-21) |
| TN-11 | Import file malformed or `version` unknown | Exit 64, nothing changed (BR-17) |
| TN-12 | Adopt a set that does not exist, or name both `--curation-set` and `--synonym-set` | Exit 64 (BR-18) |
| TN-13 | Two editors save rules on one collection at once | Each push sends the whole set as stored after its own save; the later push wins and equals the stored rows |
| TN-14 | Connection unconfigured | Section shows the unconfigured message; no nav item (BR-19) |
| TN-15 | Uninstall | Tables gone; sets still in Typesense and linked; search unaffected (BR-20) |
| TN-16 | Query of 256 characters, or only spaces | Refused (BR-6) |

### Automated checks

- Unit (`tests/unit/services/`): push payload building (ids, order, `stop_processing`,
  `filter_curated_hits`, dates, disabled rows), validation (BR-6, 7, 10, 11), export/import
  resolution, adopt's simple-scope filter.
- Integration (`tests/integration/RulesTest.php`, `RulesConsoleTest.php`, `RulesControllerTest.php`):
  TS-1 to TS-11 and TN-1 to TN-16 except the browser halves, against the 30.0 container. Web-controller
  tests follow `UtilityTest` (controller namespace swap, Accept JSON, CSRF, Pro edition).
- Existing guards extend automatically: `TranslationsTest` (every new string, BR-22), `ReadmeTest` (no new
  config keys; the README gains a Rules section). `SiteNamesTest` / B5 #2 still pass.
- **Mutation checks required:** BR-9 (TS-11), BR-12 (TN-8), BR-2 (TS-5 step 2).
- Manual: the CP browser pass in craft5 — TS-2 step 3, TS-6 step 2, TS-9, TS-10, drag reorder,
  the empty states. Craft's own components carry no automation hooks the suite needs; the browser
  pass is a checklist, not a script.

**Test hooks the build must add:** `TestCollections::deleteAll()` deletes the prefix's sets (unlinking
first); a `rules` service method returning the exact payload it would push (`desiredSet()`), used by
both the state check and the tests.

### Regression checks

| Journey | Why at risk |
|---|---|
| `collections/apply` on a collection with no rules | `diff()` now compares set lists; a retrieved schema always reports them, so a normalising slip reads every collection as changed (profile trap on retrieved schemas) |
| `collections/recreate` with reference joins | `buildSchema()` changes; dependants must still be built against physical names |
| `setup` on a fresh install | Gains a step; must still exit 0 with nothing declared |
| Scoped-key search (`SearchTest`) | Rules now apply to the same searches |
| Settings save | Must not pick up any rules data into project config (`Settings::fields()`) |

---

## 8. Build order

| Phase | What it delivers | Depends on |
|---|---|---|
| 1 | Tables, models, the `rules` service: desired payloads, push, state, BR-12-safe delete | - |
| 2 | Collections integration: links in every version, diff, apply, status, setup, utility | 1 |
| 3 | CP section, permission, screens | 1 |
| 4 | Console: export, import, adopt, prune | 1, 2 |
| 5 | README, CHANGELOG 1.1.0, translations, craft5 browser pass | 2, 3, 4 |

Phase 2 goes before 3: it is where search can break for everyone (a rebuild losing links), so it is
proven before any screen writes rules.

### Tasks

- [x] **1.1 Storage** - `src/migrations/Install.php` (new), `src/migrations/m261029_000000_rules.php`,
      `src/records/RuleRecord.php`, `src/records/SynonymRecord.php`, `src/models/Rule.php`,
      `src/models/SynonymGroup.php`, `schemaVersion` → 1.1.0 in `src/TypesenseSync.php`
      Rules: BR-6, BR-7, BR-10, BR-11, BR-20 · Verify: B5 #1 (unit validation tests)
      *As built (29 Oct):* `Install::createTables()` makes whichever table is missing and is the
      upgrade migration's whole body (a fresh install marks `m261029` applied without running it);
      `m261029::safeDown()` refuses. Table names are `RuleRecord::TABLE` / `SynonymRecord::TABLE`.
      Models carry `fromRecord()` / `populateRecord()` (dates to UTC via `Db::prepareDateForDb`; read
      back they arrive in the **system** timezone, same instant) and the normalisers
      `Rule::normaliseIds()` / `SynonymGroup::normaliseWords()` (ints / trimmed words, each once,
      first order kept; words deduped case-insensitively). BR-6's duplicate check is a model
      validator querying the table (`LOWER(query)`, own id excluded). BR-11's labels are
      `Rule::getStatus(?now)` → `on|off|scheduled|ended` (ended at `effectiveTo <= now`). A blank
      main term makes a group all equal. Tests: `tests/unit/models/{Rule,SynonymGroup}Test.php`
      (15), `tests/integration/MigrationsTest.php` (install, uninstall drop, upgrade, no revert).
      B5 #1 green on 8.2 + 8.4: 233 unit + 103 integration.
- [x] **1.2 The rules service** - `src/services/Rules.php` (component `rules`): set names, `desiredSet()`
      for both kinds, push, state compare, unlink-then-delete, `jobs/PushRules.php` (retryable);
      `tests/Support/TestCollections.php` deletes sets
      Rules: BR-1, BR-4, BR-5, BR-8, BR-9, BR-11, BR-12, BR-14 · Verify: B5 #1, TS-1, TS-3, TS-4, TS-11, TN-5, TN-8, TN-9
      *As built (29 Oct):* `Rules` also owns the rows — `getRules` / `getSynonymGroups` / `get…ById`,
      `saveRule` (BR-8: refused, `pins`/`hides` error, when an id is not an element routing to the
      collection), `saveSynonymGroup`, `deleteRow(kind, id)`, `reorder(kind, collection, ids)`; a new row
      goes last. **Row writes never push**: the caller saves, then `pushOrQueue()` (null = live, else the
      reason; queues `PushRules` and sets a cache flag, `isPending()`). `push()` PUTs both sets (empty
      ones too, BR-1) then `link()` — a PATCH adding only the owned names to the live version's lists,
      others kept; nothing before a version exists (2.1 links at build, and can reuse `link()`). Item
      ids are the running index of the items actually pushed (`rule-0001`…), not `sortOrder`, so gaps or
      equal orders cannot clash; empty `includes`/`excludes` keys are omitted, and a rule whose elements
      all fail to resolve is skipped with a warning. `state()` per kind →
      `{state, name, exists, linked, differs, message}`; a differing set reads *not live* while the
      pending flag is set (a push failed and is queued), *out of step* otherwise — the one thing a push
      records, and it expires (`PushRules::ATTEMPTS × TTR + 60` s, 5 × 120). Comparison is
      `Rules::countDiffering()` over `normaliseItem()`, which drops false / '' / null / [] — 30.0 fills in
      `remove_matched_tokens: false` and `root: ""` (probed) — and sorts keys. `deleteSet(kind, name)`
      unlinks from every collection, then deletes; a set already gone is fine. Element → document id
      goes through the target's site and formatter (TN-9 fixture `PrefixedIdFormatter`).
      **Spec corrected:** probed on 30.0, `root: "nursing home"` makes *nursing home* find *care home*,
      never the reverse — so one way is "the main term finds the words" (§1 vocabulary, TS-4 step 4);
      one group stays one item with `root` = the main term, which keeps export and adopt 1:1.
      `TestCollections::deleteAll()` deletes the prefix's collections first (dropping their links),
      unlinks any other link to a prefix set, then deletes the sets. Tests: `tests/unit/services/RulesTest.php`
      (3), `tests/integration/RulesTest.php` (11: TS-1, TS-3, TS-4, TS-11, TN-5, TN-8, TN-9, BR-8 refusal,
      BR-11 timestamps, state in step / out of step / unlinked, BR-4 failed push → job → live).
      Mutation-checked: BR-9 (`filter_curated_hits` false → TS-11 shows the future entry) and BR-12 (no
      unlink → TN-8 search 404 "Synonym index not found"). B5 #1 green on 8.2 + 8.4: 236 unit + 114
      integration; B5 #2 no output, #3 valid.
- [x] **2.1 Links survive everything** - `src/services/Collections.php` (`buildSchema()`, `diff()`,
      `apply()`, the recreate plan), `normalise` of set lists
      Rules: BR-2, BR-3 · Verify: B5 #1, TS-5, regression rows 1-2
      *As built (29 Oct):* probed on 30.0 first: **a version naming a set that does not exist is
      refused** ("Synonym set … not found"), a retrieved schema always carries both lists (`[]` when
      nothing is linked), and one PATCH may carry fields and lists together. So
      `Rules::ensureSets()` writes any owned set that is missing (as Craft holds it; an existing set
      is left alone) before every create, every recreate build and every link restore. The list rule
      lives once, in `Rules::linkedSets(collection, ?schema)` — the given schema's links in its order,
      owned names appended where missing — used by `buildSchema(collection, version, ?live)` (create
      passes nothing; `build()` passes the live version it now reads once, for its document count and
      its links) and by `Rules::linkPatch()` (only the lists that change), which `link()` now uses.
      `Collections::normaliseSets()` (static): strings only, blanks dropped, each once, order kept.
      `diff()` gains `missingLinks` (owned names not linked; counts against `upToDate`),
      `foreignLinks` (for 2.2's "also linked") and `linked` (the live lists). `apply()`'s alter path
      PATCHes fields alone as before, then, for missing links, `ensureSets()` + `link()` — so a
      link-only apply is the PATCH of the lists alone, `reindex` false, "Linked … to …"; a dry run's
      payload shows both. `Rules::LINK_KEYS` is public. Pulled forward from 2.2 so TS-5 step 4 reads:
      one "Link <set>" line in `collections/status` and in the utility's out-of-date list (3 new
      strings). Tests: unit `CollectionsTest` (+6: lists, normalise, diff, link-only apply, dry run,
      fields + link); integration `RulesTest` TS-5 steps 1-3 and step 4's service half (with
      regression row 1: a second apply is `none`), `ConsoleTest` step 4's status/apply output and a
      field alter keeping links, `RecreateTest` the dependant links its own sets (row 2); RulesTest's
      state test now starts in step (apply made the empty sets). **BR-2 mutation-checked** on TS-5
      step 2: links not carried, and no lists at all, each fail. B5 #1 green on 8.2 + 8.4: 242 unit +
      116 integration; #2 no output; #3 valid.
- [x] **2.2 Apply, status, setup, utility** - `src/console/controllers/CollectionsController.php`,
      `SetupController.php`, `src/utilities/Utility.php`, `src/templates/_utility.twig`,
      `src/controllers/UtilityController.php`
      Rules: BR-14, BR-15, BR-21 · Verify: B5 #1, TS-6 (integration half), TN-10, regression row 3
      *As built (29 Oct):* the one decision lives in `Rules::apply(collection, dryRun)` →
      `{pushed, sets, message}`: reads `state()`, throws `SyncException` on *unknown*, and when either
      set is not in step pushes **both** (`push()`, so Craft's version wins); a dry run decides the
      same and writes nothing. `Rules::describeState(row)` is the few words for a set (*in step*,
      *missing*, *not linked*, *N items differ*, or the unknown reason) used by status, apply's
      message and the utility. `Rules::orphans()` → `handle => {rules, synonyms}` for rows whose
      collection is not declared (a `NOT IN` over both tables, sorted). Callers run the rules step
      after the schema for every result except `skip` / `blocked` — `needs-recreate` too, since the
      sets do not depend on the schema: `collections/apply` prints it under each collection (exit
      unchanged), `setup` has a **Rules and synonyms** step between Collections and Analytics, and
      the utility's Apply appends "Pushed …" to its notice (or says only that, for an up-to-date
      schema). `collections/status` prints each set's line and `also linked: …` from
      `diff()['foreignLinks']`. Utility rows gain `sets` (each with `description`), `setsBehind`
      (not live / out of step only — *unknown* offers no button) and `alsoLinked`; the Apply button
      now also shows when the schema is up to date but a set is behind. `orphans` is read in every
      state, unconfigured included, and rendered last with the `rules/prune` command (built in 4.2).
      Template hooks: `data-ts-set` / `data-ts-set-state`, `data-ts-also-linked`,
      `data-ts-section="orphans"`, `data-orphan`. 20 new strings. Tests: integration `RulesTest`
      (+2: TS-6 dry run / apply / second apply finds nothing / step 3 "the next save" via
      `pushOrQueue()`; apply refuses an unreachable server), `UtilityTest` (+2: state rows, also
      linked, Apply restores Craft's set keeping the foreign link; TN-10 orphans listed, not among the
      collections, still listed unconfigured), `ConsoleTest` (+2: status drift line, also linked,
      dry run writes nothing, apply pushes, second apply in step; regression row 3 — setup on a fresh
      install makes and links both empty sets, exit 0, and remakes a deleted one). TN-10's *section
      hides it* is 3.1's and *prune deletes* is 4.2's. Mutation-checked: no push in `apply()` fails
      all three TS-6 tests. B5 #1 green on 8.2 + 8.4: 242 unit + 122 integration; #2 no output; #3
      valid.
- [x] **3.1 Section and permission** - `src/TypesenseSync.php` (CP nav, URL rules, permission),
      `src/controllers/RulesController.php`, `src/controllers/SynonymsController.php`
      Rules: BR-4, BR-13, BR-19 · Verify: B5 #1, TS-9, TN-1, TN-14
      *As built (29 Oct):* **not Craft's `hasCpSection`** — it registers an `accessPlugin-typesense-sync`
      permission beside ours, and `web\Application` 403s any CP path whose first segment is a plugin
      handle without it; so the URL segment is `typesense` (`TypesenseSync::SECTION_URI`), the nav
      item comes from `Cp::EVENT_REGISTER_CP_NAV_ITEMS` (spliced in before Utilities) via the public
      `sectionNavItem()` (null for a guest, without `PERMISSION_RULES`, or unconfigured), and the
      permission is registered under the heading *Typesense Sync*. CP URL rules: `typesense` →
      `rules/collections` (one collection redirects to its Rules tab, several list with counts +
      state, none says so and links the utility), `typesense/<collection>` → its Rules tab,
      `…/rules`, `…/rules/new`, `…/rules/<ruleId>` and the same for `synonyms` (`<groupId>`). The
      shared base is `src/web/SectionController.php` (outside `controllers/` so it is no route):
      `beforeAction` = CP + permission (403) + POST for all but `SCREENS`; `delete`, `reorder` (ids
      as a list or the admin table's JSON string), `toggle` (flips unless `enabled` posted) live
      there; `goLive()` is BR-4 — `pushOrQueue()` after the write, JSON `live: bool`, the message
      "Saved, not live yet: <reason>" as an error-type notice; `requireCollection()` / `requireRow()`
      404 an undeclared collection (TN-10's section half); `tabVariables()` = `collection`,
      `collections`, `kind`, `state` (from `Rules::state()`, any throw read as *unknown*, BR-19),
      `sectionUri` — **3.2's banner reads `state`** and can use `Rules::describeState()`.
      Unconfigured: every screen renders `_section/unconfigured.twig`, every write is refused (400).
      Rules `save` posts `ruleId` | `collection`, `query`, `matchType`, `pins[]`, `hides[]`,
      `effectiveFrom` / `effectiveTo` (Craft date-picker arrays), `enabled`; a failed save is
      `asModelFailure(…, 'rule')` so the edit action receives `rule`. Synonyms `save` posts `groupId` |
      `collection`, `type` (`oneWay` else all equal), `root`, `words` (list, `[{word}]` rows or one
      per line), `enabled`; failure route param `group`. `rules/push` = *Push now*. **Templates are
      skeletons for 3.2 to replace**: `_section/{layout,index,unconfigured}.twig`, `rules/_{index,edit}`,
      `synonyms/_{index,edit}` (layout = CP layout + Rules/Synonyms tabs; hooks `data-ts-section`,
      `data-ts-rules`, `data-ts-synonyms`). 22 new strings. Tests: `tests/integration/RulesControllerTest.php`
      (15: permission registered once; TS-9 steps 1-2 and TN-1 over every screen + action with nothing
      written or pushed; anonymous; admin without grant; nav placement; every URL rule resolves and
      `typesense-sync/…` does not; CSRF/POST/CP; TN-14; unreachable cluster still lists rows; BR-4
      failed push via the controller; BR-8 refusal reaches the form; delete/reorder/toggle push live;
      Push now; TN-10 section half). **Mutation-checked:** no `requirePermission` fails TN-1. Templates
      compiled in a one-off Twig parse (7/7); not rendered in a browser (B5 #4 is 3.2's). B5 #1 green
      on 8.2 + 8.4: 242 unit + 137 integration; #2 no output; #3 valid.
- [ ] **3.2 Screens** - `src/templates/rules/*.twig`, `src/templates/synonyms/*.twig`, state banner,
      empty states
      Rules: BR-6, BR-7, BR-8, BR-10, BR-11, BR-14 · Verify: B5 #4 (browser), TS-2, TS-6, TS-10, TN-2, TN-3, TN-4, TN-6, TN-7, TN-16
      *As built (29 Oct, browser pass OWED):* every display decision is a PHP variable the tests read,
      and the templates only lay it out. **Banner** = `SectionController::banner(state)` →
      `{lines: [{kind, state, text}], push}`, in `tabVariables()` as `banner`; one line per set not in
      step, the same *unknown* failure for both sets is ONE line, `push` false while only *unknown*.
      It sits in `_section/layout.twig` above a `tab` block (children fill `tab`, not `content`) on
      every collection screen, edit screens too; *Push now* posts by script
      (`Craft.sendActionRequest`), because an edit screen is a `fullPageForm` and a nested form is
      invalid. **Pickers (BR-8)** = `Rules::pickers(collection)`: one per element type + site, from
      `getTargetsForCollection()` (entry-type overrides included), source keys `section:<uid>`,
      `group:<uid>`, `*` (users), `productType:<uid>` (new `Commerce::productTypeUid()`); a picker
      cannot filter by entry type, so saveRule() still refuses one routed elsewhere. Several pickers
      post `pins[<n>][]` — **one shared name would lose values**, because each element select posts
      its own empty `name=''` first — and `RulesController::ids()` flattens in picker order.
      **Lists** are Craft's `VueAdminTable` over `tableData` rows built in the controllers
      (`RulesController::tableRow()`, `statusLabel()`: On / Off / Scheduled / Ended); drag = reorder,
      delete, and a *Status* bulk action. The table posts reorder as `ids` alone and its actions as
      `ids[]` + `enabled`, so `reorder` now takes `collection` from the first row when not posted and
      `toggle` takes `ids` (one collection, one push). **One way with a blank main term is refused**
      on `root` ("Enter the main term, or choose All equal.") instead of saving as all equal; the
      error path leaves `root = ''` so the form stays on One way (Craft's Model constructor typecasts
      `''` to null — set it after construction). Collection switcher = `disclosureMenu()` in
      `contextMenu` (several collections only); edit screens drop the tabs for crumbs and carry
      Delete in `formActions`. A child template's top-level `set` runs BEFORE the layout's, so a
      child cannot read the layout's variables there. 50 new strings. Tests:
      `RulesControllerTest` +8 — every screen rendered for real through
      `View::renderTemplate(…, TEMPLATE_MODE_CP)` (works in the harness), both empty states, the
      switcher; TS-2 step 3 (Scheduled / Ended / Off labels); TS-6 steps 2-3 (out of step, 1 item
      differs, on either tab, gone after a save); TS-10 step 1 (one *Can't check* line, no Push now);
      each banner state's wording; TN-2, TN-3 ×2, TN-6, TN-7 ×2, TN-16 ×2 and the blank main term
      through the forms, each on its field, nothing written, the error rendered under it; BR-8
      pickers (only the feeding section) and nested pins flattened in order; the admin table's
      reorder / bulk toggle shapes. TN-4 was 3.1's. B5 #1 green on 8.2 + 8.4: 242 unit + 145
      integration; #2 no output; #3 valid. craft5: plugin migration applied (`migrate/up
      --plugin=typesense-sync`, 1 migration); **B5 #4 not run — the CP needs Sam to sign in.**
- [ ] **4.1 Export and import** - `src/console/controllers/RulesController.php`,
      `src/services/RulesTransfer.php`
      Rules: BR-16, BR-17 · Verify: B5 #1, TS-7, TN-11, TN-13
- [ ] **4.2 Adopt and prune** - same console controller, `src/services/RulesTransfer.php`
      Rules: BR-18, BR-21 · Verify: B5 #1, TS-8, TN-10, TN-12
- [ ] **5.1 Release** - `README.md` (Rules section, uninstall note), `CHANGELOG.md` 1.1.0,
      `src/translations/en/typesense-sync.php`, `docs/store-listing.md`, craft5 browser pass
      Rules: BR-20, BR-22 · Verify: B5 #1-#4, TN-15

---

# Appendix A - Open questions

| # | Question | Owner | State |
|---|---|---|---|
| 2 | Store listing and screenshots: add the Typesense section to the listing text and shots? | Sam | Open - blocking release |

**Assumptions**

- If Typesense is down, a save still succeeds and the change goes live on retry (BR-4) — chosen over
  refusing the save, so an outage never blocks editors.
- Limits: 20 pins and 100 hides per rule, 2-50 words per synonym group (BR-7, BR-10).
- Duplicate queries are compared case-insensitively (BR-6); Typesense's own exact match is
  case-insensitive on the tokenised query.
- Set names are the live alias plus `_rules` / `_synonyms` (BR-1); no site has sets with those names.
- Uninstall leaves sets attached rather than unlinking them (BR-20).
- Export matches entries by uid then section + slug (BR-16, BR-17), which covers a database copied
  down from production and content made separately with the same slugs.
- 1.1.0 is a minor release in the existing paid edition — no new edition or price.

---

# Appendix B - build scaffolding

## B1. Before writing anything

Read `_PROFILE.typesense-sync.md` whole: the docker commands, the traps, commit-never-push. Read the
1.0 spec's rules on document ids, scoped keys and translations — this spec builds on
all three.

## B2. Context to load

1. `src/services/Collections.php` - `buildSchema()` :144 (where the set lists go), `diff()` :219 and
   the alter payload ~:389 (the PATCH to extend), `recreate()` :429 and the plan built against
   physical names ~:630, `normalise()` :833, `result()` :909 and the `ACTION_*` constants :42.
2. `src/TypesenseSync.php` - components :68, `init()` :78, utility registration :90, the
   permission check pattern :303, `schemaVersion` :57.
3. `src/services/Targets.php` - `resolveTargetFor()` :88 and `formatterFor()` :125: how a pinned
   element becomes a document id (BR-8).
4. `src/formatters/BaseFormatter.php` - `documentId()` :134, overridable.
5. `src/console/Controller.php` - `checkConfig()` :50, `confirmByHandle()` :127, exit codes; and
   `src/console/controllers/CollectionsController.php` for the command shape.
6. `src/controllers/UtilityController.php` - permission and JSON patterns for CP actions.
7. `src/services/Analytics.php` - the nearest existing "desired vs live, apply with PUT" service to
   model `Rules` on.
8. `tests/integration/UtilityTest.php` (web-controller test pattern), `RecreateTest.php`,
   `tests/Support/TestCollections.php`.
9. `vendor/typesense/typesense-php/src/CurationSets.php`, `CurationSet.php`, `SynonymSets.php`.

## B3. Guardrails

- **Do not delete a curation or synonym set while a collection links it** - a deleted linked synonym
  set makes every search on that collection answer 404 "Synonym index not found" until a set of that
  name exists again (30.0, verified 29 Oct 2026). Unlink with a PATCH first, always, including in
  test cleanup.
- **Do not push a rule without `filter_curated_hits: true`** - the default `false` shows pinned
  documents the scoped key filters out: unpublished entries, and anything the key's isolation filter
  hides (verified 29 Oct 2026).
- **Do not build a collection version without the set lists** - a version created without them has
  no rules at all, silently; a link sits on the physical version, never on the alias.
- **Do not drop a foreign link in `apply()`** - a developer's hand-made set would vanish from live
  search with no warning.
- **Do not store Typesense item ids or document ids in the tables** - item ids are the ordering
  (lexical) and document ids are the formatter's to decide; storing either drifts.
- **Do not put rules in project config or `Settings::fields()`** - editors change them on production,
  where project config is read-only, and a settings save would carry them across environments.
- **Do not use the search-only key for set reads** - it gets 401; the state check needs the admin key,
  server side only.
- **Do not let a failed push roll back the editor's save** (BR-4) - an outage would lose their work.
- **Do not name St Monica or any site in `src/`** - B5 #2 fails.

## B4. Definition of done

- [ ] Every AC in §7 passes; TS-1 to TS-11 and TN-1 to TN-16 automated where §7 says so
- [ ] Every BR in §5 is enforced, and BR-2, BR-9 and BR-12 are mutation-checked
- [ ] The test hooks in §7 exist (`desiredSet()`, set-aware `TestCollections::deleteAll()`)
- [ ] The regression checks in §7 pass
- [ ] B5 below runs clean on PHP 8.2 and 8.4
- [ ] The craft5 browser pass (B5 #4) is recorded in the task line

## B5. Verification

```bash
# 1. Everything, both PHP legs
docker compose run --rm php composer check
PHP_VERSION=8.4 docker compose run --rm php composer check
# expect: ECS "No errors found", PHPStan "[OK] No errors", two Codeception "OK (N tests, M assertions)"

# 2. No site names leaked
grep -rniE 'members|marketplace|lll|legacy|goodStanding|companies|Features::|monica' src/
# expect: no output

# 3. Store-valid metadata
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"

# 4. CP browser pass in craft5 (manual) - AC-3 label, AC-7 banner, AC-10, AC-11, drag reorder,
#    both empty states. Record pass/fail per item in task 5.1.
```

| AC | Verified by |
|---|---|
| AC-1, 2, 4, 5, 6, 8, 9, 12 | B5 #1 |
| AC-3, 7, 10, 11 | B5 #1 and #4 |

## B6. Out of bounds

- `~/Projects/lll` and `~/Projects/st-monica-trust` - St Monica's move onto the plugin is its own spec.
- The 1.0 spec's rules other than where this spec extends them; any existing config key.
- Typesense sets the plugin does not own, except adopt's unlink (BR-18).

---

## Change log

| Date | Version | Change | By |
|---|---|---|---|
| 2026-10-29 | 1.0.1 | Task 1.2: one-way synonyms corrected to Typesense's direction (the main term finds the words), probed on 30.0; §1 vocabulary and TS-4 step 4 | Claude |
| 2026-10-29 | 1.0 | Approved by Sam. Appendix A row 1 (St Monica's server version) moved to St Monica's migration work - it does not block this build or release | Sam Birch |
| 2026-10-29 | 0.1 | First draft; supersedes the 1.0 spec's out-of-scope row "Synonyms and curation rules" | Claude, with Sam Birch |
