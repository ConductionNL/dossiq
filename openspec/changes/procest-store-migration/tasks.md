# Tasks

## 1. Sub-store migration

- [ ] 1.1 Migrate `src/store/modules/bezwaar.js`: rewrite 5 `getObjects` calls to `fetchCollection` with `_filters[case]=…&_limit=…` shape.
- [ ] 1.2 Migrate `src/store/modules/gis.js`: rewrite `getAll`/`create`/`update`/`delete` calls to `fetchCollection`/`saveObject`/`deleteObject`.
- [ ] 1.3 Migrate `src/store/modules/inspection.js`: rewrite `uploadFile(caseId, file)` to use `filesPlugin`'s `uploadFiles('case', caseId, formData)`.

## 2. View migration

- [ ] 2.1 `src/views/CaseMapView.vue`: replace `getAll('case')` and `getAll('caseType')` with `fetchCollection(t, {})`.
- [ ] 2.2 `src/views/dashboard/CaseMapWidget.vue`: replace `getAll('case')` with `fetchCollection('case', {})`.
- [ ] 2.3 `src/views/voorstellen/VoorstelDetail.vue`: replace `fetchOne('voorstel', id)` with `fetchObject('voorstel', id)`.

## 3. Validation

- [ ] 3.1 Run `npx eslint` on touched files — clean.
- [ ] 3.2 Run `node tests/validate-manifest.js` — passes.
- [ ] 3.3 Run `npx webpack --config webpack.config.js --mode production` — builds.
- [ ] 3.4 Verify `grep -rEn '\.(getObjects|getAll|fetchOne)\b' src/store/ src/views/` returns zero hits in migrated files.
