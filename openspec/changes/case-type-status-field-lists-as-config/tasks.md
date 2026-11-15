# Tasks: case-type-status-field-lists-as-config

- [x] 1.1 Statuses and fields as object lists in the manifest
  - Files: src/manifest.json, src/menu-layout.simple.json
  - Test: tests/vitest/caseTypeStatusFieldLists.spec.js, tests/vitest/caseTypePartsPages.spec.js
- [x] 1.2 Remove the rehosted editors and the legacy tabs
  - Files: src/registry.js, src/components/caseType, src/views/settings/tabs, src/views/settings/components/StatusTypeForm.vue, src/utils/statusTypeForm.js
  - Test: tests/vitest/statusFieldRules.spec.js
- [x] 1.3 Dutch for the new strings
  - Files: l10n/en.json, l10n/nl.json, l10n/en.js, l10n/nl.js
- [ ] 1.4 Move the e2e selectors to the lists, after the library release
  - Files: tests/e2e/case-type-status-authoring.spec.ts, tests/e2e/code-lists-from-concepts.spec.ts, tests/e2e/attribute-catalogue-folders.spec.ts
