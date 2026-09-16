# Design: case-search-declares-its-fields

## D-1. The declaration lives on the property, not on the page

openregister reads `matchType` and `inputControl` off the property
definition (`lib/Service/Search/PropertySearchProfile.php`). One
declaration serves the list, the facet, the API and the portal. Putting it
in the Cases page config instead would give the Queue page, the map, the
export leaf and the portal four chances to disagree, and the manifest
schema does not type a `facets` key anyway: `config.sidebar` is
`additionalProperties: true`, so a page-level spelling would validate
silently and be read by nothing.

## D-2. A fragment, not the monolith, and no version bump

`lib/Settings/register.d/*.json` deep-merges onto
`lib/Settings/dossiq_register.json` key by key
(`RegisterFragmentMerger::deepMerge`), so a fragment can add two keys to an
existing property without restating it. The diff then reads as what
changed rather than as a hundred re-stated properties.

The fragment hash is deliberately not folded into the import version any
more: `SettingsService::readEffectiveConfiguration()` says why, and
openregister now hashes the merged configuration itself and skips on hash
equality. So new content in a fragment forces the re-import on its own and
`info.version` stays where it is. `tests/vitest/searchableSchemas.spec.js`
guards that version against `0.11.0`; this change does not move it.

## D-3. Declaring a match type enlists the column in the free-text scan

This is the trap, and it decides which properties get `matchType`.
`PropertySearchProfile::participatesInFreeText()` asks a different question
of a declared property than of a silent one. A silent property joins the
`_search` scan when it is a string whose format is not a date. A property
that declares a match type joins it unless that type is `range`.

So declaring `exact` on a boolean would put `isDraft::text ILIKE 'true'`
into every free-text search, and a search for the word "true" would return
every draft. There is no match type that means "filter on me, never scan
me": `range` is the only one that keeps a column out, and `range` on a
boolean is a lie.

The rule this change follows: **`matchType` is declared on a property a
person types a term against. `inputControl` is declared on a property a
list offers as a filter.** Booleans and structured properties declare
`inputControl` alone, which changes no SQL. That is a platform coupling,
not a preference, and it is worth openregister's attention: a sixth match
type, or a separate `searchable: false`, would let a boolean declare its
control without joining the scan.

Silent by design: object and array-of-object properties (`properties`,
`statusDwellTotals`, `handoverRecord`, `actionResult`, `decisions`,
`publications`, `conversations` and their kin) declare neither. They are
not typed into a box and not offered as a filter.

## D-4. Declaring narrows, and that is the point

Three declarations change what an existing search returns, deliberately.

| property | was | becomes |
|---|---|---|
| `identifier` | substring of a case number | the whole case number |
| `initiatorSourceId` | substring of a BSN or a KvK number | the whole number |
| the uuid references (`status`, `caseType`, `result`, `assignedGroup`) | substring of a uuid | equality, which no typed word matches |

`tags` moves the other way. It is an array, so it never took part in the
scan at all; `fuzzy` puts it in, through pg_trgm similarity, which is what
makes "spoedd" find the cases tagged "spoed".

## D-5. The refusal is shown where the term was typed

The Cases search box belongs to `CnIndexPage`, not to dossiq. It fetches
through `useObjectStore.fetchCollection()`, which records the failure on
`objectStore.errors['dossiq-case']` and returns `[]`, so the page renders
an empty list over a refusal.

dossiq mounts its own component into the page's `after-search` slot,
through `pages[].slots`, which the manifest schema types as an open map of
slot name to registry component. The component reads that error and the
`_search` value from the route, and says where the term broke.

Two things the library drops on the way, both named rather than worked
around. `parseResponseError()` keeps `body.error` and discards `position`
and `term`, so the position is recovered from the message, whose format is
fixed by `SearchTermSyntaxException::__construct()` as
`<reason> at position <n>.`. When it cannot be recovered the component
shows the message alone. The term needs no payload: dossiq already has it,
because the reader typed it into the box.

## D-6. `_search` is a filter, not a page coordinate

`selectionScope.js` listed `_search` beside `_page`, `_limit` and `_order`
under `NOT_A_FILTER`, with the reason "paging and sorting describe the
page, not the result set". A search term describes the result set. The
existing tests name paging and sorting only, and none of them asserts the
drop.

`BulkSelectionResolver::resolveQuery()` passes the stored query straight
into `ObjectService::searchObjects()`, so `_search` resolves server-side
exactly as it does on the list. Carrying it costs nothing and closes the
gap between the sentence "All 400 cases matching this search are selected"
and the selection actually handed to the job.

## D-7. dossiq rewrites no search term

Checked before removing anything: nothing under `src/` splits, quotes,
escapes, wildcards, lowercases or joins a search term. `useListView`
assigns `params._search = searchTerm.value` verbatim and dossiq's own
callers pass the typed string through. The only normalisation in the tree
is `this.query.trim()` in the initiator picker, which is kept: trimming is
not grammar, and an empty box must not search.

What was left to remove was the filter plumbing that dropped the term
(D-6) rather than any rewriting of it. The two spellings dossiq keeps, and
why:

- `assignee: "IS NULL"` on the Unclaimed lens and the Queue base filter.
  openregister honours the sentinel and the `_isnull` suffix both. The two
  filters are asserted byte-equal by a vitest, and rewriting them is a
  lane of its own.
- `@me`, `@today` and `@today+7d`. Resolved client-side by nextcloud-vue's
  `resolveFilterTokens`, not by openregister, so there is no platform
  grammar to defer to.
