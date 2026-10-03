---
kind: code
depends_on: []
---

# Proposal: case-search-declares-its-fields

The dossiq consumer half of openregister
`search-quality-operators-and-facets`, merged as openregister#3768
(`733a26c01`) and openregister#3806 (`3938bb012`). Decision D22: the query
layer is openregister's. Gap register rows 9.2 "Advanced search with
per-case-type fields" and 9.1 / 9.12, `procest/_gaps/gap-register.json` in
ConductionNL/market-intelligence.

## Why

A handler types `dakkapel` into the Cases search box. The platform scans
every string column on the case, including a uuid, a BSN and a hold reason,
and compares each one by substring. `2026-0042` finds the case whose
identifier is `2026-0042` and also the four cases that happen to mention it
in a description. Nothing on the case says which of its hundred-odd fields
is a term to type, which is a date to bracket, and which is a list to pick
from, so every surface guesses, and they guess differently.

openregister now reads that from the property itself. A property declares
`matchType`, one of exact, prefix, range, fuzzy or fulltext, and
`inputControl`, one of text, select, multiselect, range, date-range or
boolean. `?_facetable=true` answers `searchable_fields[<prop>]` with both,
plus `declared` and `title`, so a list, a facet, the API and the portal read
one answer instead of four. A property that declares nothing behaves exactly
as it did before, which is why silence is not an option worth keeping: it is
the drift, not a default.

The register's own example for the facet half: "twelve cases have no result
type" is a data quality report nobody has to write.

## What changes

- **The register declares the case.** A new fragment,
  `lib/Settings/register.d/39-search-declarations.json`, adds `matchType`
  and `inputControl` to the case properties a person searches or filters
  on. The identifier is exact, the title and the description are fulltext,
  the dates are ranges, the enums and the references are exact behind a
  select, the tags are fuzzy, and a party name is a prefix.
- **A refused search says where it broke.** `_search` now takes `AND`,
  `OR`, `NOT`, brackets, `"phrases"` and a leading or trailing `*`, and a
  malformed term is refused with `400 {error, position, term}` rather than
  evaluated as a literal. dossiq showed that refusal as an empty list.
  It now shows the position and the term, under the box the term was typed
  into, on the Cases page and in the initiator picker.
- **The whole-result selection carries the search.** `readListFilters()`
  dropped `_search` along with the paging keys, so "select all 400 cases
  matching this search" handed the bulk job every case in the register.
  That is the exact surprise the module was written to prevent.
- **A not-set lens.** The Cases page gains one lens for a closed case with
  no result, spelled in the platform's own grammar, `result_isnull=true`.
- **The index is reachable.** Admin settings gain a search index panel
  reading openregister's `GET /api/settings/search-index`, and naming the
  `occ openregister:tables:search-index` verbs that act on it.

## What dossiq deliberately does not build

**The not-set chip in the facet sidebar.** A terms facet now carries
`facet.missing.results`, and `normalizeFacets()` in nextcloud-vue keeps only
`buckets`, so the count never reaches a consumer. That is
ConductionNL/nextcloud-vue#1176, open, with openregister named as the
producer. Reading the raw facet payload beside the normalised one, per app,
is the drift the shared normaliser exists to remove. dossiq waits for the
normaliser and ships the lens above, which needs no facet payload at all.

**A Dutch query grammar.** The operators are upper-case `AND`, `OR` and
`NOT`. Translating them is openregister's C-search-38, recorded there as
documented rather than driven.

## Impact

- `lib/Settings/register.d/39-search-declarations.json` (new)
- `src/utils/searchRefusal.js` (new), `src/utils/selectionScope.js`
- `src/components/search/CaseSearchRefusal.vue` (new),
  `src/components/initiator/InitiatorPicker.vue`
- `src/views/settings/tabs/SearchIndexTab.vue` (new),
  `src/views/settings/AdminRoot.vue`
- `src/manifest.json`, the `Cases` page only
- `src/registry.js`, `src/customComponents.js`
