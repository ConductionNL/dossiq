## 1. Component

- [x] 1.1 Create `src/components/export/CaseListExportAction.vue`: NcActions menu labeled "Export" (aria/inputLabel set) with two NcActionButton entries "Export as CSV" and "Export as Excel". On click, build the URL with `generateUrl('/apps/openregister/api/objects/procest/case/export')` + `format` param + the current `$route.query` entries passed through, then trigger the download via `window.location.assign(url)`. Use Options API and `t('procest', ...)` for labels (English source strings). Follow the modal/dialog isolation and component conventions of neighbouring components (e.g. look at `src/components/` peers for import style).
- [x] 1.2 Register the component in `src/registry.js` as `CaseListExportAction` (follow the existing registry entry pattern and its comment style).

## 2. Manifest

- [x] 2.1 Add `"actionsComponent": "CaseListExportAction"` to the `Cases` page entry in `src/manifest.json` (top-level page field, sibling of `config` — see the nc-vue page schema; verify with `npm run check:manifest` or the project's manifest validation).

## 3. Tests

- [x] 3.1 Add `tests/vitest/caseListExportAction.spec.js`. Deviation: `vitest.config.js` runs in the `node` environment with no `@vue/test-utils`/jsdom/vue-loader plugin registered (confirmed — no such devDependency, and `tests/vitest/deelzaakComponentLogic.spec.js` documents the same constraint), so the `.vue` SFC cannot be mounted in this suite. Followed the project's established alternative (`pdokService.spec.js`, `caseRelationApi.spec.js`): extracted the URL-building logic into `src/utils/caseExportHelpers.js` (`buildCaseExportUrl`, imported by the real component) and unit-tested that real function directly via the `@nextcloud/router` stub alias — covering CSV/Excel `format`, route-query passthrough (single/multiple/array-valued), null/undefined skipping, and the `$route`-less fallback. The component's own logic is a thin one-line call to this tested function plus static template markup (two NcActionButton entries), so no behaviour is left unverified.
- [x] 3.2 Ran `USE_LOCAL_LIB=false npx vitest run tests/vitest/caseListExportAction.spec.js` and then the full `npm test` to confirm no regressions.

## 4. Verify

- [x] 4.1 `openspec validate case-list-export-via-or-export-leaf` passes.
- [x] 4.2 Manifest validation passes (`npm run check:manifest` if present).
- [x] 4.3 Lint the new files with the project's eslint config (`npx eslint src/components/export/CaseListExportAction.vue tests/vitest/caseListExportAction.spec.js`).

## Acceptance Criteria

- Cases page manifest entry declares `actionsComponent: CaseListExportAction`; component registered in the registry.
- Export menu produces OR export-leaf URLs (csv + excel) with route-query passthrough; no procest-side serialization.
- New vitest spec passes; full suite green; eslint clean on new files.

## Quality Checklist

- No new npm dependencies.
- i18n source strings English via `t('procest', ...)`.
- ADR-022: serialization/access control stay in openregister.
- NcActions accessibility props set (no bare icon buttons without labels).
