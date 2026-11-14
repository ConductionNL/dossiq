## 1. Confirm zero usage

- [ ] 1.1 Grep `src/` (and `js/`, `css/` build outputs' source origins) for
      `markercluster`/`MarkerCluster`/`leaflet.markercluster` — confirm no hits outside
      `node_modules` and `package.json`/`package-lock.json`
- [ ] 1.2 Grep webpack config (`webpack.config.js`) for any explicit alias/chunk reference to the
      package
- [ ] 1.3 Confirm no `.vue`/`.js` file dynamically constructs the import path (e.g.
      ``import(`leaflet.${x}`)``) that a static grep would miss

## 2. Remove the dependency

- [ ] 2.1 Remove `leaflet.markercluster` from `package.json` `dependencies`
- [ ] 2.2 Run `npm install` to regenerate `package-lock.json` without the package's subtree
- [ ] 2.3 Run `npm run build` and confirm the build succeeds with no missing-module errors

## 3. Re-run dependent tooling

- [ ] 3.1 Re-run the license report generation (whatever produces `license-report-npm/`) and
      confirm the report no longer lists `leaflet.markercluster`
- [ ] 3.2 Re-run `npm audit` / the security scan that produces `result-security-npm` and confirm
      no regressions
- [ ] 3.3 Fix any pre-existing lint/test warnings encountered in touched files while making this
      change (project convention — do not defer)

## 4. Spec + traceability

- [ ] 4.1 Add the ADDED requirement in
      `openspec/changes/remove-unused-map-clustering-dependency/specs/frontend-build-hygiene/spec.md`
      (this change) and run `openspec validate remove-unused-map-clustering-dependency --strict`

## 5. Verification

- [ ] 5.1 Live-verify: `LocationPicker.vue`'s point-picker and polygon-draw modes both still
      render and function identically after the dependency removal (no visual/behavioral
      regression — `leaflet-draw`, which those modes actually use, is untouched)
