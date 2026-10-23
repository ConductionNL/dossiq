# Tasks: advanced-holds-the-catalogue-folders

- [x] 1.1 Catalogue entry and page retired; Case types, Attributes, Templates, Object types under Advanced in that order
  - Files: src/manifest.json, src/manifest.d/64-catalogue.json
  - Test: tests/vitest/advancedHoldsCatalogueFolders.spec.js
- [x] 1.2 Object types page carries the catalogue's object types table and actions, admin-only
  - Files: src/manifest.d/64-catalogue.json
  - Test: tests/vitest/cataloguePage.spec.js
- [x] 1.3 `/settings/catalogue` redirects to the matching entry
  - Files: src/utils/manifestRoutes.js
  - Test: tests/vitest/advancedHoldsCatalogueFolders.spec.js
- [x] 1.4 Store reads Marketplace / Marktplaats in the menu and as the page title
  - Files: src/manifest.json, l10n/*
  - Test: tests/vitest/advancedHoldsCatalogueFolders.spec.js
- [x] 1.5 Tour, docs and e2e name Case types under Advanced
  - Files: src/manifest.json, docs/Features/besluitvorming-workflow.md, tests/e2e/app-chrome.spec.ts, tests/e2e/spec-coverage/store-surface.spec.ts
- [ ] 1.6 design-system: DqCatalogus redrawn as the Object types page (separate PR)
- [ ] 1.7 Live check on :8080 once integrated
