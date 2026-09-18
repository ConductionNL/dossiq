# Tasks: case-definition-export-is-real

Tier: V1. Kind: code. Row 11.2.

- [ ] 1.1 `CaseDefinitionExportService::exportComponent()` reads from
  OpenRegister per component: `schema` (the case type object and its property
  definitions), `statuses` (status types and transitions of the case type),
  `permissions` (role types and their `ncGroupId` bindings), `documents`
  (document types and templates), `metadata` (result types and decision
  types), `workflows` (the workflow templates bound to the case type).
  - Unit test per component: a seeded case type exports non-empty data
  - `@spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md`
- [ ] 1.2 `buildManifest()` takes `caseType.slug` and `caseType.title` from
  the object and fills `dependencies` with every object ref the components
  point at.
- [ ] 1.3 An export of an unknown case type throws rather than writing a ZIP
  of empty components.
- [ ] 2.1 `CaseDefinitionImportService::importComponent()` creates or updates
  the objects of its component under `conflictResolution`, and returns the
  created and replaced ids.
- [ ] 2.2 `importWorkflows()` deploys each workflow file through the existing
  workflow path, or returns `status: 'error'` naming what it could not deploy.
  Counting files is never a success.
- [ ] 2.3 A component whose objects cannot all be written leaves none of them
  written, so a half imported case type cannot exist.
- [ ] 3.1 Round trip test: seed a case type with two statuses, one role, one
  document type and one workflow template; export; import into an empty
  register; compare the two case types field by field.
- [ ] 3.2 Remove the two placeholder comments. They were the only honest part
  of the old code and they must not survive the change that makes them false.
