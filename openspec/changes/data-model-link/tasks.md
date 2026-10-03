# Tasks: data-model-link

Tier: V1. Kind: config. Rows Q11.32 and 11.17.

- [x] 1.1 `src/manifest.json` `menu[]`: entry `DataModelLink`, `section:
  "integrations"`, href resolved to the dossiq register's schema page,
  `adminOnly`.
  - vitest: entry present in the integrations section, not in main
  - `@spec openspec/changes/data-model-link/specs/admin-settings/spec.md`
- [x] 1.2 `#CaseObjects` and the Objects section of `#CaseDetail`: header
  link Manage object types with the same href, `visibleIf` admin.
- [x] 2.1 `tests/e2e/data-model-link.spec.ts`; `openspec validate
  data-model-link --strict`.
