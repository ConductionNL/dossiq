# Tasks: cases-views-are-places

Tier: V1. Kind: config. Row Q9.16. Waits on nextcloud-vue
`saved-view-as-a-place` (open on nextcloud-vue `development`).

- [x] 1.1 `src/manifest.json`: declare saved-view places on `#Cases` and
  `#Queue`, with the pinned views hanging under each page's own navigation
  entry (D-1). `#Tasks` is left out and the exclusion is asserted: its
  saved views are OFF (`remove-casetask` made the list the engine's task
  inbox rather than a search over a register), and a place on a page with
  no views is a route that can only open an empty state.
  - `tests/vitest/caseListLenses.spec.js`
  - `@spec openspec/changes/cases-views-are-places/specs/case-management/spec.md`
- [x] 1.2 Not built, and the reason is mechanical rather than a choice:
  dossiq seeds no saved view. A view is an OpenRegister View object owned
  by a user, and no channel seeds one. `ConfigurationService` imports
  registers, schemas, pages and objects, not views, so a seeded view in
  `lib/Settings/dossiq_register.json` would be dropped in silence. The
  presentation a view opens in is read off the view itself by the host
  (`resolveViewPresentation`), so there is nothing for this manifest to
  declare. Seeding views belongs to `saved-views-shared-by-role`, which
  needs OpenRegister to carry views in a configuration first.
- [x] 1.3 Seed no pinned view, so the navigation on a fresh install is
  unchanged (D-3). Asserted over the whole menu tree, not just the top
  level.
- [x] 2.1 `tests/e2e/cases-views-are-places.spec.ts`: open a view by its
  own URL, switch presentation, follow an old `?view=` link, pin it, find
  it under Cases, and be refused it as another user;
  `tests/vitest/casesViewsArePlaces.spec.js` for the declaration itself;
  `openspec validate cases-views-are-places --strict`.

## The dependency, said out loud

The renderer is nextcloud-vue `saved-view-as-a-place`
(ConductionNL/nextcloud-vue#1200, open on that repo's `parity/round2`),
which adds the `savedViewPlaces` key to schema 2.34.0, the view route, the
presentation and the pin. Until a release carrying it is published and
this app bumps `@conduction/nextcloud-vue`, `npm run check:manifest` reads
the INSTALLED 2.33.0 schema and rejects these two pages. That is the one
known red on this change, it is named here rather than discovered in CI,
and it clears with the dependency bump, not with a manifest edit. The
vendored `tests/schemas/app-manifest-v2.schema.json` is updated to 2.34.0
so the copy the gate falls back to already knows the key.
