# Proposal: case-type-navigation

kind: code — objections, appeals and subsidies are CASE TYPES, not standalone navigation areas. This change replaces their dedicated menu groups and workflow pages with one dynamic navigation child per case type under the "Cases" group (resolved from live OpenRegister data via a backend `/api/manifest` delta), and gives the Cases index a map view.

## Why

The dossiq navigation grew a dedicated menu group and workflow index pages for each administrative-law flavour of case (Objections & Appeals, Subsidies, plus standalone `Objection decisions` and `Committee advice` pages, and a standalone `Map`). But an objection, an appeal and a subsidy application are all just CASES of a particular case type — they share the `case` schema and the `Cases` route, differing only by `caseType`. Modelling each as its own nav area duplicates navigation, hard-codes the case-type taxonomy into the bundled manifest, and drifts the moment an administrator adds or renames a case type.

The right model is: the "Cases" group carries one child per case type, resolved from the live `caseType` objects the user may see, each deep-linking into the shared `Cases` index filtered by that type. The taxonomy lives in the data, not the manifest.

## What Changes

- **Backend `ManifestController`** — new `GET /api/manifest` endpoint returning a `mergeStrategy: 'delta'` menu payload that adds one child per visible `caseType` under `CasesGroup` (id `ct-<uuid>`, `route: Cases`, `query.caseType: <uuid>`), sorted by name. No-op delta (`{ menu: [] }`) on anonymous / no-OpenRegister / unconfigured / empty so it can never break the shell.
- **Frontend adopts the backend delta** — `src/main.js` routes the built manifest through `useAppManifest('dossiq', built, { mergeStrategy: 'delta' })` and passes the reactive resolved manifest to `App.vue`, so the nav updates in place when the delta lands.
- **Dedicated groups/pages retired** — the `BezwaarBeroepGroup` and `SubsidiesGroup` menu groups, and the standalone `BezwaarDecisions` / `BezwaarAdviceRequests` workflow pages (page objects, menu entries and routes) are removed. The `Bezwaren` / `Beroepen` / `Subsidies` INDEX pages stay routable for deep links and e2e; only their menu leaves are dropped.
- **Cases index map view** — the `Cases` page opts into `viewModes: ["table", "cards", "map"]` with a `mapConfig` block plotting cases by their `geometry` GeoJSON. The standalone `CaseMap` menu leaf is retired (its `/map` route stays reachable).

## Capabilities

### New Capabilities
- `case-type-navigation`: The Cases group carries one navigation child per case type, resolved from live OpenRegister data via the backend `/api/manifest` delta; objections/appeals/subsidies have no dedicated menu group; the Cases index offers a map view.

## Impact

- **Backend**: `lib/Controller/ManifestController.php` (new), `appinfo/routes.php` (route), `tests/Unit/Controller/ManifestControllerTest.php` (new).
- **Frontend**: `src/main.js` (delta wiring), `src/manifest.json` (drop pages/menus, add Cases map view), `src/menu-layout.json` (removals).
- **Tests**: e2e specs asserting the removed menu items/pages updated.
- Depends on two nc-vue features carried by the combined build: `CnIndexPage` `viewMode: 'map'` and `mergeManifestDelta` keyed `children[]` merge.
