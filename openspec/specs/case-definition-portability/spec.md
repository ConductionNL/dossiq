# case-definition-portability Specification

## Purpose
Enable export and import of complete case type definitions (zaaktype configurations) as portable archives for DTAP (Development, Test, Acceptance, Production) pipeline deployment. A case definition package contains the schema, workflow definitions, form configurations, permission rules, and related settings. This eliminates manual recreation of case type configurations across environments.

Mature case management platforms package complete case definitions (schema, process definitions, forms, plugins, permissions, dashboards) into portable archives for cross-environment deployment, and some support both definition migration and live migration of running cases when definitions change. The approach of versioned definition packages is most applicable to our architecture.

## Requirements

### Requirement: Case definitions MUST be exportable as a portable package
A complete zaaktype configuration can be exported as a single archive.

#### Scenario: Export a case definition
- GIVEN zaaktype `omgevingsvergunning` is fully configured with:
  - OpenRegister schema (field definitions, validations)
  - n8n workflow definitions (intake, assessment, decision flows)
  - Status types and transitions
  - Resultaattypen and besluittypen
  - Role/permission configuration
  - Document type templates
- WHEN an admin exports the case definition
- THEN a ZIP archive MUST be created containing:
  - `manifest.json` with version, export date, source environment, and dependency list
  - `schema.json` with the complete OpenRegister schema definition
  - `workflows/` directory with n8n workflow JSON exports
  - `statuses.json` with status types and allowed transitions
  - `permissions.json` with role-based access configuration
  - `documents.json` with document type definitions
  - `metadata.json` with besluittypen and resultaattypen

#### Scenario: Export includes version information
- GIVEN a case definition has been exported before (version 1.0)
- WHEN changes are made and the definition is exported again
- THEN the manifest MUST show version 1.1 (auto-incremented)
- AND the manifest MUST list changes since the previous version

### Requirement: Case definitions MUST be importable into another environment
An exported package can be imported into a different Nextcloud instance.

#### Scenario: Import a case definition into clean environment
- GIVEN a target environment has OpenRegister and Procest installed but no case types configured
- WHEN an admin imports the `omgevingsvergunning` ZIP package
- THEN the system MUST create:
  - The OpenRegister schema with all field definitions
  - The n8n workflows (via n8n API)
  - Status types and transitions
  - Permission configurations
- AND the import MUST report success/failure for each component

#### Scenario: Import with dependency resolution
- GIVEN the package depends on schema `person` (for zaakbetrokkenen) that already exists in the target
- WHEN importing, the system detects the existing `person` schema
- THEN it MUST map the reference to the existing schema by matching on schema name/identifier
- AND it MUST NOT create a duplicate `person` schema

#### Scenario: Import conflict detection
- GIVEN the target environment already has a `omgevingsvergunning` zaaktype
- WHEN importing a package with the same zaaktype identifier
- THEN the system MUST show a conflict report listing differences
- AND offer options: skip, overwrite, or merge (field-by-field)

### Requirement: Package validation MUST prevent broken imports
Before applying an import, the package must be validated.

#### Scenario: Validate package before import
- GIVEN an admin uploads a case definition package
- WHEN the system validates it
- THEN it MUST check:
  - All referenced schemas exist or are included in the package
  - n8n workflow JSON is valid
  - Permission roles referenced exist in the target Nextcloud
  - No circular dependencies
- AND present a validation report before allowing import

#### Scenario: Validation failure blocks import
- GIVEN a package references a schema `subsidy-rules` that does not exist in the target
- WHEN validation runs
- THEN the import MUST be blocked with error: "Missing dependency: schema 'subsidy-rules'"

### Requirement: Packages MUST be environment-agnostic
Connection strings, URLs, and environment-specific values must be parameterized.

#### Scenario: Environment variables in workflows
- GIVEN an n8n workflow contains a webhook URL pointing to `https://test.gemeente.nl/api/...`
- WHEN the workflow is exported
- THEN the URL MUST be replaced with a placeholder `{{BASE_URL}}/api/...`
- AND during import, the admin MUST be prompted to provide the target environment's base URL

### Requirement: Import/export MUST support selective components
Admins can choose which parts of a definition to export or import.

#### Scenario: Export only schema and statuses
- GIVEN zaaktype `omgevingsvergunning` has schema, workflows, statuses, and permissions
- WHEN an admin exports with only `schema` and `statuses` selected
- THEN the ZIP MUST contain only `schema.json`, `statuses.json`, and `manifest.json`
- AND the manifest MUST note that workflows and permissions were excluded

### Current Implementation Status

**Not yet implemented.** No export/import functionality for case type definitions exists in the codebase. There are no controllers, services, or UI components for definition portability.

**Foundation available:**
- `SettingsService::loadConfiguration()` (`lib/Service/SettingsService.php`) imports register configuration from `procest_register.json` via OpenRegister's `ConfigurationService::importFromApp()`. This import/auto-configure pattern could serve as a model for case definition import.
- The `procest_register.json` file (`lib/Settings/procest_register.json`) already defines the complete schema structure for all case type entities, providing a reference format for portable definitions.
- The repair step `InitializeSettings` (`lib/Repair/InitializeSettings.php`) and `LoadDefaultZgwMappings` (`lib/Repair/LoadDefaultZgwMappings.php`) demonstrate import/initialization patterns.
- OpenRegister's `ConfigurationService` has version-aware import with force-reimport capability.
- n8n workflows can be exported/imported via n8n API (n8n MCP tools: `n8n_get_workflow`, `n8n_create_workflow`).

**Partial implementations:** None.

### Standards & References

- **DTAP (Development, Test, Acceptance, Production)**: Standard software deployment pipeline that portability supports.
- **ZGW Catalogi API (VNG)**: Case type definitions (ZaakType, StatusType, ResultaatType, etc.) follow ZGW Catalogi API schemas, which could serve as an interoperable export format.
- **GEMMA**: Dutch municipal architecture standard promoting reusable configurations across municipalities.
- **OpenRegister Configuration Format**: The existing `procest_register.json` format provides a proprietary but well-structured configuration exchange format.
- **Common Ground**: Emphasizes configuration portability across municipalities via standardized APIs.

### Specificity Assessment

This spec is at a design level -- it clearly describes what the feature should do but lacks implementation-level detail.

**What's missing:**
- No specification of the manifest.json schema (exact fields, version format, dependency encoding).
- No API endpoints for export/import operations.
- No UI wireframes for the import wizard (conflict resolution, validation report, progress).
- No specification of how n8n workflow URLs are parameterized (which fields are environment-specific).
- No specification of the merge strategy for field-by-field conflict resolution.
- No specification of how OpenRegister schema IDs are remapped during import (IDs differ across environments).

**Open questions:**
1. Should case definitions be exportable via the admin UI, CLI, or API (or all three)?
2. How are OpenRegister object IDs (references between schemas) handled during import -- UUID-based or slug-based matching?
3. Should the package include sample data (test cases) for validation?
4. How does this interact with the ZGW Catalogi API -- should export also produce a ZGW-compatible catalog export?
