# Tasks: case-type-navigation

- [x] Add `ManifestController::manifest()` returning a `caseType` → `CasesGroup` children delta (no-op on anonymous / no-OpenRegister / unconfigured / empty)
- [x] Register `GET /api/manifest` → `manifest#manifest` in `appinfo/routes.php`
- [x] Add `ManifestControllerTest` covering the child-per-case-type path and the empty/null-objectService no-op paths
- [x] Route `src/main.js` built manifest through `useAppManifest('procest', built, { mergeStrategy: 'delta' })` and pass the reactive resolved manifest to App.vue
- [x] Add `BezwaarBeroepGroup`, `SubsidiesGroup`, `CaseMap` to `src/menu-layout.json` removals; drop the `CaseMap` relocation
- [x] Delete the `BezwaarDecisions` and `BezwaarAdviceRequests` pages, menu entries and their orphaned groups from `src/manifest.json`
- [x] Add `viewModes` + `mapConfig` (geoField `geometry`, popupField `title`) to the Cases index page config
- [x] Update e2e specs that navigate to removed menu items/pages; keep still-routable index-page assertions
- [x] Verify: PHPUnit (ManifestControllerTest), PHPCS + PHPStan on the controller, and `USE_LOCAL_LIB=false` webpack build all pass

## Acceptance criteria

- The Cases group shows one child per visible case type, each deep-linking to the Cases index filtered by `caseType`.
- Objections/appeals/subsidies have no dedicated menu group; their index pages stay deep-linkable.
- The Cases index offers a Map view; the standalone Case Map menu leaf is gone but `/map` stays reachable.
- `/api/manifest` returns a no-op delta (never an error) when data/OpenRegister is absent.
