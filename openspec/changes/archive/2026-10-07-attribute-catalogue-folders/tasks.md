# Tasks: attribute-catalogue-folders

Tier: V1. Kind: config. Row 11.23.

- [x] 1.1 `lib/Settings/dossiq_register.json` `propertyDefinition.category`
  (string, facetable, title Category); mock register follows.
  - `@spec openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md`
- [x] 1.2 `src/manifest.json` property definitions index: `folderSidebar` on
  `category`, column Category.
  - `tests/vitest/caseTypeAuthoringManifest.spec.js`
- [x] 1.3 `#CaseTypeDetail` property picker: `groupBy: category`.
- [x] 2.1 `tests/e2e/attribute-catalogue-folders.spec.ts`; `openspec
  validate attribute-catalogue-folders --strict`.
