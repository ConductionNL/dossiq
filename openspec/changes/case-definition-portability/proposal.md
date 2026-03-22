# Proposal: case-definition-portability

## Summary
Implement export and import of complete case type definitions (zaaktype configurations) as portable ZIP archives for DTAP pipeline deployment. A case definition package contains the schema, workflow definitions, status types, permission rules, and related settings.

## Motivation
Municipalities need to deploy case type configurations across Development, Test, Acceptance, and Production environments. Currently there is no way to export a configured case type from one Procest instance and import it into another.

## Affected Projects
- [x] Project: `procest` -- Export/import services, controller, and admin UI

## Scope

### In Scope
- `CaseDefinitionExportService` for ZIP archive creation
- `CaseDefinitionImportService` for ZIP import with validation
- `CaseDefinitionController` for API endpoints
- Manifest.json schema with version tracking
- Environment variable parameterization
- Selective component export
- Conflict detection on import
- Dependency resolution

### Out of Scope
- Live migration of running cases
- ZGW Catalogi API export format
- CLI export/import commands
- Sample/test data in packages
