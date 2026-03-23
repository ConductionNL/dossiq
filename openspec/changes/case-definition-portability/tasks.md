# Tasks: case-definition-portability

## 1. Backend Services

### Task 1: Create CaseDefinitionExportService
- **spec_ref**: `openspec/specs/case-definition-portability/spec.md#requirement-case-definitions-must-be-exportable-as-a-portable-package`
- **files**: `lib/Service/CaseDefinitionExportService.php`
- **acceptance_criteria**:
  - GIVEN a configured case type WHEN an admin exports it THEN a ZIP archive is created with manifest.json, schema.json, statuses.json, permissions.json, documents.json, metadata.json
  - GIVEN a case definition exported before WHEN exported again THEN the manifest version auto-increments
- [x] Create CaseDefinitionExportService with exportCaseDefinition() method

### Task 2: Create CaseDefinitionImportService
- **spec_ref**: `openspec/specs/case-definition-portability/spec.md#requirement-case-definitions-must-be-importable-into-another-environment`
- **files**: `lib/Service/CaseDefinitionImportService.php`
- **acceptance_criteria**:
  - GIVEN a ZIP package WHEN imported into a clean environment THEN all components are created
  - GIVEN existing schemas WHEN importing THEN dependencies are resolved by slug matching
  - GIVEN a conflict WHEN importing THEN a conflict report is generated
- [x] Create CaseDefinitionImportService with importCaseDefinition() and validatePackage() methods

### Task 3: Create CaseDefinitionController
- **spec_ref**: `openspec/specs/case-definition-portability/spec.md`
- **files**: `lib/Controller/CaseDefinitionController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin user WHEN POST /api/case-definitions/export THEN a ZIP file is returned
  - GIVEN an admin user WHEN POST /api/case-definitions/import with ZIP THEN import is processed
  - GIVEN an admin user WHEN POST /api/case-definitions/validate THEN validation report is returned
- [x] Create CaseDefinitionController with export, import, validate endpoints
- [x] Register routes in routes.php
