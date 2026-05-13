# Tasks

## 1. Sub-store migration

- [x] 1.1 Migrate `src/store/modules/bezwaar.js`: rewrite 5 `getObjects` calls to `fetchCollection` with `_filters[case]=…&_limit=…` shape.
- [x] 1.2 Migrate `src/store/modules/gis.js`: rewrite `getAll`/`create`/`update`/`delete` calls to `fetchCollection`/`saveObject`/`deleteObject`.
- [x] 1.3 Migrate `src/store/modules/inspection.js`: rewrite `uploadFile(caseId, file)` to use `filesPlugin`'s `uploadFiles('case', caseId, formData)`.

## 2. View migration

- [x] 2.1 `src/views/CaseMapView.vue`: replace `getAll('case')` and `getAll('caseType')` with `fetchCollection(t, {})`.
- [x] 2.2 `src/views/dashboard/CaseMapWidget.vue`: replace `getAll('case')` with `fetchCollection('case', {})`.
- [x] 2.3 `src/views/voorstellen/VoorstelDetail.vue`: replace `fetchOne('voorstel', id)` with `fetchObject('voorstel', id)`.

## 3. Validation

- [x] 3.1 Run `npx eslint` on touched files — clean.
- [x] 3.2 Run `node tests/validate-manifest.js` — passes.
- [x] 3.3 Run `npx webpack --config webpack.config.js --mode production` — builds.
- [x] 3.4 Verify `grep -rEn '\.(getObjects|getAll|fetchOne)\b' src/store/ src/views/` returns zero hits in migrated files.
