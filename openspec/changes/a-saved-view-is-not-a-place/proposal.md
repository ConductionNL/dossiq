---
kind: code
depends_on: []
---

# Proposal: a-saved-view-is-not-a-place

Retracts `cases-views-are-places` (archived 2026-09-23, requirement REQ-CM-44),
which gave a saved view of the Cases and Queue pages a route of its own. The
nextcloud-vue half, `saved-view-as-a-place`, is withdrawn in the same pass.

## Why

Sorting a column, saving it as a view, clearing the filter and applying the view
again produced two different addresses for one list:

```
/apps/dossiq/queue?_order=[{"key":"title","order":"desc"}]
/apps/dossiq/queue/views/9?_sortKey=title&_sortOrder=desc
```

The second spelling is read by nothing. `parseSortKeysFromQuery`, which seeds a
list's sort on load, reads `_order` and only `_order`, and so does
OpenRegister's `ObjectsController::normalizeOrderParameter`. Three measured
consequences:

- A saved view's sort survived only in memory. Clicking Apply looked right
  because the same branch set the list's state directly; a reload, or the link
  opened by the colleague it was sent to, came back in the page's default
  order.
- `readListFilters` (`src/utils/selectionScope.js`) denies by NAME, not by the
  `_` prefix, and neither `_sortKey` nor `_sortOrder` was on the list. So
  "Select all 400 cases matching this search" handed `searchObjects()` two keys
  matching no column as filters, on the query that decides what four hundred
  cases get reassigned.
- Clearing the filters could remove neither the sort nor the view's own filter
  keys, because the code that clears the address had never heard of the first
  and had never recorded the second. That is the report this change started
  from.

The route was not the defect. But a view stores filters, a search term and a
sort, and that is not enough to be somewhere you go — so rather than teach two
addresses to agree, there is one.

## What changes

- `src/manifest.json`: `#Cases` and `#Queue` stop declaring `savedViewPlaces`.
  `allowSavedViews` stays on both — the dropdown is not what went.
- `tests/schemas/app-manifest-v2.schema.json` re-vendored from the library,
  which no longer declares the key (schema 2.40.0).
- `src/utils/selectionScope.js`: the deny list is unchanged and the doc block
  says why — the leak closed because the spelling was retired, not because a
  name was added.
- `tests/validate-manifest.js`: its lag classifier cannot tell a REMOVED key
  from a lagging one, so the doc block says so and names the guard.
- `tests/vitest/casesViewsArePlaces.spec.js` → `savedViewIsNotAPlace.spec.js`.
- `tests/vitest/validateManifestLag.spec.js` inverts its `savedViewPlaces`
  assertions.

## Ownership

The format and the capability removal are nextcloud-vue's. dossiq stops
declaring.

## Out of scope

- A redirect for `/cases/views/9`. The key shipped on 2026-09-18 and appears
  only in `-unstable` tags from 2026-09-20; the last non-unstable tag predates
  it, so nobody outside the dev team holds such a link.
- `savedViewTree` and `saved-views-shared-by-role`, which are about which views
  a reader sees, not about where a view lives.

## Capabilities

- Modified: `case-search-and-lists`: a saved list of cases is a lens applied at
  the page's own address, not a place with one of its own.

## Impact

`src/manifest.json`, `src/utils/selectionScope.js` (comment),
`tests/schemas/app-manifest-v2.schema.json`, `tests/validate-manifest.js`
(comment), two vitest specs, one e2e spec. No PHP.
