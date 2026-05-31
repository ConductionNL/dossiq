---
retrofit_extensions:
  - REQ-CT-17
  - REQ-CT-18
---

# Case Types — export/import surface (retrofit)

## Requirements

### REQ-CT-17: Procest SHALL expose case-definition export endpoints + ZIP package format

`OCA\Procest\Controller\CaseDefinitionController` SHALL provide `GET /api/case-definitions/{id}/export` that returns a ZIP package (via `DataDownloadResponse`) containing the case type and all linked dependencies (workflow templates, role/group mappings, document templates) needed for round-trip portability to another procest instance. The ZIP SHALL be produced by `CaseDefinitionExportService::exportCaseDefinition()` and SHALL embed a `manifest.json` describing the package schema version, source instance, and contained object refs.

#### Scenario: Export a published case type
- **GIVEN** a published case type with workflow templates + roles
- **WHEN** a behandelaar calls `GET /api/case-definitions/{id}/export`
- **THEN** the response SHALL be a ZIP download containing `case-type.json`, all linked `workflow-template-*.json` files, role/group mapping definitions, and a top-level `manifest.json`

### REQ-CT-18: Procest SHALL validate + import case-definition packages with explicit conflict reporting

`CaseDefinitionImportService::validatePackage()` SHALL inspect a ZIP package, parse `manifest.json`, and return a structured report of: (a) missing required files, (b) schema-version compatibility, (c) name/slug collisions against existing case types and templates, and (d) cross-reference integrity. Validation SHALL be a pure read — no side effects on the procest instance.

`CaseDefinitionImportService::importCaseDefinition()` SHALL run validation first, then create the case type and all linked objects atomically. On collision, the importer SHALL accept a caller-provided `conflictResolution` mode (`reject`, `rename`, `replace`) and SHALL surface its decisions in the response so the admin can audit what was created versus replaced.

`CaseDefinitionController::validate()` SHALL expose validation-only HTTP access (`POST /api/case-definitions/import?dryRun=true`) so admins can review a package before committing.

#### Scenario: Dry-run validates without persisting
- **WHEN** an admin calls `POST /api/case-definitions/import?dryRun=true` with a ZIP body
- **THEN** the response SHALL include the structured validation report and no objects SHALL be created
