# Tasks: a-saved-view-is-not-a-place

Tier: V1. Kind: code. Retracts `cases-views-are-places` (archived
2026-09-23). Consumes nextcloud-vue schema 2.40.0, which removes the
`savedViewPlaces` key.

- [x] 1.1 Re-vendor `tests/schemas/app-manifest-v2.schema.json` from
  `node_modules/@conduction/nextcloud-vue`. DO THIS BEFORE 1.2. The gate
  forgives an unknown key as a library lag whenever the vendored copy
  declares it, so with a stale copy the manifest edit cannot be seen to
  matter: `check:manifest` prints PASS either way. Re-vendored first, it
  goes red with the two errors in the `real` bucket, which is the signal
  that the edit below is doing something.
  - `tests/vitest/validateManifestLag.spec.js`
- [x] 1.2 `src/manifest.json`: drop `savedViewPlaces` from `#Cases` and
  `#Queue`. `allowSavedViews` stays true on both — the dropdown is not
  what went, the address layer is.
  - `tests/vitest/savedViewIsNotAPlace.spec.js`
  - `npm run check:manifest` → PASS (0 errors)
- [x] 1.3 `tests/vitest/casesViewsArePlaces.spec.js` →
  `savedViewIsNotAPlace.spec.js`. Three assertions are salvaged rather
  than dropped: Tasks keeps saved views OFF, no menu entry points at a
  `__view` route (which gets STRONGER — no such route is registered at
  all now), and both page entries stay top level with no children.
- [x] 1.4 `tests/vitest/validateManifestLag.spec.js`: invert the
  `savedViewPlaces` assertions to `.not.toHaveProperty`, and record the
  third state in the header — a REMOVED key looks exactly like a lagging
  one to the classifier, and only re-vendoring tells them apart.
- [x] 1.5 `src/utils/selectionScope.js`: doc block only. Say that the
  list denies by NAME and not by the `_` prefix, that `_sortKey`/
  `_sortOrder` reached `searchObjects()` as filters on a whole-result
  bulk selection because of it, and that the leak closed when the
  spelling was retired rather than when a name was added.
- [x] 1.6 `tests/validate-manifest.js`: doc block only. The measured
  `savedViewPlaces` example stays because it is the measured one; add
  that the key was later removed and that the classifier cannot see a
  removal.
- [ ] 2.1 `tests/e2e/a-saved-view-is-not-a-place.spec.ts`, replacing
  `cases-views-are-places.spec.ts`: apply a view and assert the address
  stays the page's own and carries `_order`; reload it and assert the
  sort survives; clear the filters and assert nothing of the view is
  left. The pinning and presentation-switch tests go with the feature.
  NOT RUN HERE — e2e is the owner's to run against a live instance.
