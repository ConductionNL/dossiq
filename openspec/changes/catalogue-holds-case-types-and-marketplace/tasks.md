# Tasks: catalogue-holds-case-types-and-marketplace

- [x] 1.1 Case types leave an administrator's menu in both structures, stay for everyone else
  - Files: src/manifest.json
  - Test: tests/vitest/catalogueHoldsCaseTypes.spec.js
- [x] 1.2 The tour's case types step opens the catalogue
  - Files: src/manifest.json, l10n/en.json, l10n/nl.json, l10n/en.js, l10n/nl.js
  - Test: tests/vitest/tourMenuLabels.spec.js, tests/vitest/tourTellsTheTruth.spec.js, tests/vitest/catalogueHoldsCaseTypes.spec.js
- [x] 1.3 Store reads Marketplace / Marktplaats in the menu and as the page title
  - Files: src/manifest.json, l10n/en.json, l10n/nl.json, l10n/en.js, l10n/nl.js
  - Test: tests/vitest/catalogueHoldsCaseTypes.spec.js
- [x] 1.4 e2e and docs follow the new labels
  - Files: tests/e2e/app-chrome.spec.ts, tests/e2e/spec-coverage/store-surface.spec.ts, docs/Features/besluitvorming-workflow.md
- [ ] 1.5 Live check on :8080 once the branch is integrated (admin: no Case types entry, catalogue folder opens the list; non-admin: entry still there; footer reads Marktplaats)
