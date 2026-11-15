# Design: a-saved-view-is-not-a-place

## D-1. `_order` is the one spelling, with no compatibility read

`_sortKey`/`_sortOrder` are dropped outright rather than read for a while. The
usual reason to keep a compatibility read is persisted data, and there is none:
a saved view stores its sort in `query.sort`, a structured field, never as a
query string. Grepped 2026-09-23, dossiq contains zero occurrences of either
name — the spelling only ever existed in the address bar, written by the
library and read back by nothing.

## D-2. Old addresses are not redirected

They could be, and it would cost a guard that resolves the view and expands it.
It is not worth building: `savedViewPlaces` landed on 2026-09-18 and ships only
in `-unstable` tags from 2026-09-20, and the last non-unstable tag predates the
feature. An old link reaches the router's catch-all and lands on the dashboard.

`?view=<id>` goes the same way, and its docblock in the library was wrong about
why it existed: `LEGACY_VIEW_QUERY_KEY` was introduced in the same commit as the
feature it claimed to predate, so no link has ever been written in that spelling
either.

## D-3. Read-compatibility on stored data, none on the query string

D-1 and the library's `extractViewState` look contradictory and are not. A view
stored before sorts could be chained holds `sort` as one `{ key, order }`
object, and those views exist in every instance, so that shape is still read.
The query string has no equivalent: nothing persists one.

## D-4. The presentation a view declares was never built here

`cases-views-are-places` design D-2 said Overdue would open as a list and the
desk view as a board. Its own `tasks.md` records why it did not happen —
`ConfigurationService` imports registers, schemas, pages and objects, not views,
so dossiq seeds no saved view at all. There is no `viewType` anywhere in this
repo. Nothing observable is lost with the capability.

## D-5. The manifest gate cannot see a removal

`tests/validate-manifest.js` forgives an unknown-property error as a library lag
whenever the vendored schema declares the property. A key the library REMOVED
has exactly that shape, so a stale vendored copy turns "this page declares a key
that no longer exists" into `PASS (… pending a library release)`. Nothing in the
classifier can separate the two. Re-vendoring is the fix, and
`validateManifestLag.spec.js` is what insists on it.
