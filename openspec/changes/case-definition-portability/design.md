# Design: case-definition-portability

## Architecture

### Export Flow
1. Admin selects a case type and components to export (schema, statuses, permissions, workflows)
2. `CaseDefinitionExportService` queries OpenRegister for the case type and related objects
3. Service creates a manifest.json with version info, export date, environment, dependencies
4. Each component is serialized to JSON and placed in the ZIP structure
5. n8n workflows are fetched via n8n API and environment-specific URLs are parameterized
6. ZIP archive is returned as a download

### Import Flow
1. Admin uploads a ZIP archive
2. `CaseDefinitionImportService` extracts and validates the manifest
3. Dependency check: verify all referenced schemas exist or are included
4. Conflict check: detect existing case types with same identifier
5. If validation passes, present a report to the admin
6. On confirmation, create/update all components in OpenRegister
7. n8n workflows are deployed via n8n API with environment variables resolved

### ZIP Archive Structure
```
case-definition-{slug}-v{version}.zip
+-- manifest.json          # Version, export date, source, dependencies, components
+-- schema.json            # OpenRegister schema definition
+-- statuses.json          # Status types and allowed transitions
+-- permissions.json       # Role-based access configuration
+-- documents.json         # Document type definitions
+-- metadata.json          # Besluittypen and resultaattypen
+-- workflows/             # n8n workflow JSON exports
|   +-- intake.json
|   +-- assessment.json
```

### API Endpoints
- `POST /api/case-definitions/export` -- Export a case definition
- `POST /api/case-definitions/import` -- Import a case definition
- `POST /api/case-definitions/validate` -- Validate a package before import

### Key Classes
- `CaseDefinitionExportService` -- Handles export logic
- `CaseDefinitionImportService` -- Handles import with validation
- `CaseDefinitionController` -- REST API endpoints
- `ManifestBuilder` -- Creates/parses manifest.json

## Dependencies
- OpenRegister ObjectService for schema/object CRUD
- n8n API for workflow export/import
- PHP ZipArchive for archive creation/extraction
