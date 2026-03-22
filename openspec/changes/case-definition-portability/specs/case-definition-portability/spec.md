---
status: proposed
---

# case-definition-portability Specification

## Purpose
Enable export and import of complete case type definitions (zaaktype configurations) as portable archives for DTAP (Development, Test, Acceptance, Production) pipeline deployment and inter-municipality sharing. A case definition package contains the schema, workflow definitions, form configurations, permission rules, and related settings. This eliminates manual recreation of case type configurations across environments and enables a marketplace of reusable zaaktype templates.

## Context
Mature case management platforms package complete case definitions into portable archives for cross-environment deployment. CaseFabric supports both definition migration and live migration of running cases when definitions change, using event-sourced migration with plan item matching, case file migration, and team migration -- all with full audit trails. Flowable exports CMMN/BPMN/DMN models as versioned deployment archives. Our approach focuses on versioned definition packages that map to OpenRegister schemas and n8n workflows, with explicit conflict resolution and environment parameterization.

## Requirements

### Requirement 1: Case definition export as portable package
A complete zaaktype configuration MUST be exportable as a single ZIP archive containing all components.

#### Scenario 1.1: Export a complete case definition
- GIVEN zaaktype `omgevingsvergunning` is fully configured with:
  - OpenRegister schemas (case type fields, property definitions, validations)
  - n8n workflow definitions (intake, assessment, decision flows)
  - Status types and transitions (ordered statuses with `isFinal`, `notifyInitiator` flags)
  - Resultaattypen and besluittypen
  - Role type configuration (roltypen)
  - Document type templates
  - ZGW mapping configuration
- WHEN an admin clicks "Exporteren" on the zaaktype in `CaseTypeDetail.vue`
- THEN a ZIP archive MUST be downloaded containing:
  - `manifest.json` -- version, export date, source environment, Procest version, dependency list
  - `case-type.json` -- the caseType object with all properties
  - `schemas/` directory -- OpenRegister schema definitions for all related schemas
  - `statuses.json` -- ordered status types with transitions
  - `results.json` -- result type definitions
  - `decisions.json` -- decision type definitions
  - `roles.json` -- role type definitions
  - `documents.json` -- document type definitions
  - `properties.json` -- property definitions (custom fields)
  - `workflows/` directory -- n8n workflow JSON exports
  - `mappings.json` -- ZGW mapping configuration
  - `permissions.json` -- role-based access configuration

#### Scenario 1.2: Export includes version information
- GIVEN a case definition has been exported before as version "1.0.0"
- AND the admin has since added 2 new status types and modified a property definition
- WHEN the definition is exported again
- THEN the manifest MUST show version "1.1.0" (auto-incremented minor version)
- AND the manifest MUST include a `changelog` array listing changes since the previous version
- AND the version MUST follow semantic versioning (major.minor.patch)

#### Scenario 1.3: Export captures dependencies
- GIVEN zaaktype `omgevingsvergunning` references a shared `person` schema for zaakbetrokkenen
- WHEN the definition is exported
- THEN the `manifest.json` MUST list `person` as an external dependency with its schema identifier
- AND the `person` schema MUST NOT be included in the archive (it is shared, not owned by this zaaktype)
- AND the manifest MUST specify the minimum compatible version of the `person` schema

#### Scenario 1.4: Export via CLI
- GIVEN an admin with shell access to the Nextcloud server
- WHEN they run `docker exec nextcloud php occ procest:export-definition omgevingsvergunning --output /tmp/export.zip`
- THEN the same ZIP archive MUST be produced as from the UI export
- AND the command MUST support `--version` to set a specific version number

#### Scenario 1.5: Export sanitizes environment-specific data
- GIVEN an n8n workflow contains a webhook URL `https://test.gemeente.nl/api/intake`
- AND the Procest register has ID `42` in the source environment
- WHEN the definition is exported
- THEN the webhook URL MUST be replaced with `{{BASE_URL}}/api/intake`
- AND OpenRegister IDs MUST be replaced with slugs/identifiers (not numeric IDs)
- AND API keys, credentials, and secrets MUST be stripped from workflow definitions

### Requirement 2: Case definition import into another environment
An exported package MUST be importable into a different Nextcloud instance with validation and conflict resolution.

#### Scenario 2.1: Import into clean environment
- GIVEN a target environment has OpenRegister and Procest installed but no case types configured
- WHEN an admin uploads the `omgevingsvergunning.zip` package via the import wizard in `CaseTypeAdmin.vue`
- THEN the system MUST create:
  - The caseType object in OpenRegister
  - All status types, result types, decision types, role types, document types, and property definitions
  - n8n workflows via the n8n API (`n8n_create_workflow` MCP tool)
  - ZGW mapping configuration
- AND the import MUST report success/failure for each component in a results table
- AND all created objects MUST reference each other correctly (no broken links)

#### Scenario 2.2: Import with existing dependency resolution
- GIVEN the package depends on a `person` schema that already exists in the target environment
- WHEN importing, the system detects the existing `person` schema by matching on slug/identifier
- THEN it MUST map the reference to the existing schema
- AND it MUST NOT create a duplicate `person` schema
- AND the mapping MUST be shown in the import preview: "person schema -> existing (ID: 78)"

#### Scenario 2.3: Import conflict detection and resolution
- GIVEN the target environment already has an `omgevingsvergunning` zaaktype
- WHEN importing a package with the same zaaktype identifier
- THEN the system MUST show a conflict report with a side-by-side diff of differences
- AND offer resolution options per conflicting field:
  - **Keep existing** -- retain the target's value
  - **Use imported** -- overwrite with the package's value
  - **Merge** -- for array fields (e.g., status types), combine both sets
- AND the admin MUST explicitly confirm each resolution before import proceeds

#### Scenario 2.4: Import prompts for environment variables
- GIVEN the package contains parameterized values (`{{BASE_URL}}`, `{{SMTP_HOST}}`)
- WHEN the import wizard reaches the environment configuration step
- THEN it MUST prompt the admin to provide values for each parameter
- AND provide sensible defaults where detectable (e.g., current instance URL for `{{BASE_URL}}`)
- AND validate that all parameters are filled before allowing import

#### Scenario 2.5: Import rollback on failure
- GIVEN an import is in progress and has created 5 of 8 components
- WHEN the 6th component fails (e.g., n8n workflow creation fails due to missing node type)
- THEN the system MUST roll back all 5 previously created components
- AND report the specific failure with actionable error message
- AND leave the target environment in its pre-import state

### Requirement 3: Package validation before import
Before applying an import, the package MUST be validated for completeness, compatibility, and correctness.

#### Scenario 3.1: Structural validation
- GIVEN an admin uploads a case definition package
- WHEN the system validates the package structure
- THEN it MUST verify:
  - `manifest.json` is present and valid JSON
  - All files referenced in the manifest exist in the archive
  - JSON files are syntactically valid
  - Required fields are present in each component file

#### Scenario 3.2: Dependency validation
- GIVEN the package references a `subsidy-rules` schema as an external dependency
- AND `subsidy-rules` does not exist in the target environment
- THEN the validation MUST report: "Ontbrekende afhankelijkheid: schema 'subsidy-rules'"
- AND the import MUST be blocked until the dependency is resolved (install the schema or remove the reference)

#### Scenario 3.3: Version compatibility validation
- GIVEN the package was exported from Procest v2.5.0
- AND the target environment runs Procest v2.3.0
- THEN the validation MUST check the `minProcestVersion` field in the manifest
- AND if incompatible, report: "Pakket vereist Procest v2.5.0 of hoger. Huidige versie: v2.3.0"

#### Scenario 3.4: n8n workflow validation
- GIVEN the package contains 3 n8n workflow JSON files
- WHEN validating
- THEN the system MUST verify each workflow JSON is a valid n8n workflow structure
- AND check that all referenced n8n node types are available in the target n8n instance (via `search_nodes` MCP tool)
- AND report missing node types as warnings (not blocking)

#### Scenario 3.5: Validation report presentation
- GIVEN validation completes with 2 errors and 3 warnings
- THEN the import wizard MUST show a validation report with:
  - Errors (blocking): red, with explanation and suggested fix
  - Warnings (non-blocking): yellow, with explanation
  - Passed checks: green, collapsed by default
- AND the "Import" button MUST be disabled until all errors are resolved

### Requirement 4: Environment-agnostic packaging
Connection strings, URLs, and environment-specific values MUST be parameterized in exported packages.

#### Scenario 4.1: URL parameterization in workflows
- GIVEN an n8n workflow contains webhook URL `https://test.gemeente.nl/api/intake`
- WHEN the workflow is exported
- THEN URLs matching known patterns (the current instance URL) MUST be auto-detected and replaced with `{{BASE_URL}}/api/intake`
- AND the manifest MUST list `BASE_URL` as a required parameter with a description

#### Scenario 4.2: Credential stripping
- GIVEN an n8n workflow references a credential named "SMTP Production"
- WHEN the workflow is exported
- THEN the credential reference MUST be preserved as a named placeholder
- AND the actual credential values (passwords, API keys) MUST be stripped
- AND the import wizard MUST prompt the admin to map the credential to an existing credential in the target environment

#### Scenario 4.3: OpenRegister ID remapping
- GIVEN the source environment has register ID `42` and schema IDs `101, 102, 103`
- WHEN the definition is exported
- THEN all numeric IDs MUST be replaced with stable identifiers (slugs)
- AND during import, the system MUST resolve slugs to the target environment's IDs
- AND if a slug cannot be resolved, the import MUST report the specific unresolvable reference

#### Scenario 4.4: Multi-environment parameter profiles
- GIVEN a municipality has DTAP environments (Development, Test, Acceptance, Production)
- WHEN importing the same package into each environment
- THEN the import wizard MUST support saving parameter profiles (e.g., "Test", "Production")
- AND previously used parameter values MUST be pre-filled when re-importing an updated package version

### Requirement 5: Selective component export and import
Admins MUST be able to choose which parts of a definition to export or import.

#### Scenario 5.1: Export only schema and statuses
- GIVEN zaaktype `omgevingsvergunning` has schemas, workflows, statuses, results, decisions, and permissions
- WHEN an admin opens the export dialog and deselects workflows, results, decisions, and permissions
- THEN the ZIP MUST contain only `case-type.json`, `schemas/`, `statuses.json`, `properties.json`, and `manifest.json`
- AND the manifest MUST note which components were excluded
- AND excluded components MUST NOT appear as dependencies

#### Scenario 5.2: Import only workflows into existing definition
- GIVEN an existing `omgevingsvergunning` zaaktype in the target environment
- AND a package containing updated workflow definitions
- WHEN the admin imports with only "Workflows" selected
- THEN only the n8n workflows MUST be created/updated
- AND the existing statuses, schemas, and other components MUST NOT be modified

#### Scenario 5.3: Import individual component from package
- GIVEN a package with 8 components
- WHEN the import wizard shows the component list
- THEN each component MUST have a checkbox (selected by default)
- AND the admin MUST be able to deselect individual components
- AND the system MUST warn if deselecting a component that others depend on

#### Scenario 5.4: Export as ZGW Catalogi format
- GIVEN an admin wants to share the zaaktype with a non-Procest system
- WHEN they select "Exporteren als ZGW Catalogi" in the export dialog
- THEN the export MUST produce a JSON file conforming to the ZGW Catalogi API schema (ZaakType, StatusType, ResultaatType, etc.)
- AND this format MUST be importable by any ZGW-compatible system

### Requirement 6: Definition versioning and change tracking
Case definitions MUST be versioned with a change history to support controlled DTAP deployment.

#### Scenario 6.1: Automatic version tracking
- GIVEN zaaktype `omgevingsvergunning` at version "1.2.0"
- WHEN the admin modifies a status type (changes the name from "Beoordeling" to "Inhoudelijke beoordeling")
- AND saves the zaaktype
- THEN the definition version MUST auto-increment to "1.2.1" (patch for minor change)
- AND the change MUST be recorded: `{"field": "statusType.name", "old": "Beoordeling", "new": "Inhoudelijke beoordeling", "user": "admin", "date": "..."}`

#### Scenario 6.2: Version comparison
- GIVEN two exported packages: `omgevingsvergunning-v1.2.0.zip` and `omgevingsvergunning-v1.3.0.zip`
- WHEN an admin uploads both for comparison
- THEN the system MUST show a structured diff:
  - Added components (green)
  - Removed components (red)
  - Modified components (yellow, with field-level diff)

#### Scenario 6.3: Version pinning for running cases
- GIVEN 50 active cases using zaaktype `omgevingsvergunning` v1.2.0
- WHEN the admin imports v1.3.0 (which adds a new required status)
- THEN existing running cases MUST continue using v1.2.0 rules
- AND only new cases MUST use v1.3.0
- AND the admin MUST be able to manually migrate individual running cases to v1.3.0

#### Scenario 6.4: Version rollback
- GIVEN zaaktype `omgevingsvergunning` was updated from v1.2.0 to v1.3.0
- AND issues are discovered with v1.3.0
- WHEN the admin triggers rollback
- THEN v1.3.0 MUST be deactivated (no new cases can use it)
- AND v1.2.0 MUST be re-activated as the current version
- AND running v1.3.0 cases MUST be flagged for review

#### Scenario 6.5: Export version history
- GIVEN zaaktype `omgevingsvergunning` has versions 1.0.0 through 1.5.0
- WHEN the admin views the version history
- THEN all versions MUST be listed with: version number, date, author, and change summary
- AND any historical version MUST be downloadable as a ZIP package

### Requirement 7: Live case migration between definition versions
Running cases MUST be migratable to a new definition version without data loss.

#### Scenario 7.1: Migrate case to new definition version
- GIVEN case `zaak-1` is running on zaaktype `omgevingsvergunning` v1.2.0
- AND v1.3.0 adds a new required property "milieu_categorie" and renames status "Beoordeling" to "Inhoudelijke beoordeling"
- WHEN the admin triggers migration of `zaak-1` to v1.3.0
- THEN the case's current status MUST be mapped to the new status name
- AND the new required property MUST be added with a null/default value (flagged for case worker to fill)
- AND removed properties from v1.3.0 MUST be archived (preserved but hidden)
- AND the migration MUST be recorded in the case audit trail

#### Scenario 7.2: Bulk migration with preview
- GIVEN 50 cases running on v1.2.0
- WHEN the admin triggers bulk migration to v1.3.0
- THEN the system MUST first show a preview: "50 zaken worden gemigreerd. 3 zaken hebben status 'Beoordeling' die wordt hernoemd. 12 zaken missen het nieuwe veld 'milieu_categorie'."
- AND the admin MUST confirm before migration proceeds
- AND migration MUST be executed as a background job with progress tracking

#### Scenario 7.3: Migration conflict for removed status
- GIVEN case `zaak-2` has status "Vooronderzoek" which was removed in v1.3.0
- WHEN migration is attempted
- THEN the system MUST flag `zaak-2` as requiring manual intervention
- AND the admin MUST map the removed status to an existing v1.3.0 status before migration can proceed

#### Scenario 7.4: Migration preserves task state
- GIVEN case `zaak-1` has 3 active tasks
- WHEN migrated to v1.3.0
- THEN existing tasks MUST be preserved with their current state and assignees
- AND tasks referencing removed properties or statuses MUST be flagged for review

### Requirement 8: Inter-municipality sharing
Case definitions MUST be shareable between municipalities via a registry or direct exchange.

#### Scenario 8.1: Publish to shared registry
- GIVEN a municipality has a well-tested `woo-verzoek` zaaktype
- WHEN the admin clicks "Publiceren naar bibliotheek" (publish to library)
- THEN the definition package MUST be uploaded to a shared registry (OpenCatalogi or a dedicated Procest template registry)
- AND the listing MUST include: name, description, version, municipality of origin, and screenshot

#### Scenario 8.2: Browse and install from registry
- GIVEN the Procest template library shows 15 available zaaktype templates
- WHEN an admin searches for "WOO" and finds the published `woo-verzoek` template
- THEN they MUST be able to preview the template's components (statuses, properties, workflows)
- AND install it into their environment using the standard import flow

#### Scenario 8.3: Template rating and feedback
- GIVEN a municipality installed a shared template
- THEN they MUST be able to rate the template (1-5 stars) and leave feedback
- AND the rating MUST be visible to other municipalities browsing the registry

### Requirement 9: Import/export audit trail
All import and export operations MUST be logged for compliance and troubleshooting.

#### Scenario 9.1: Export audit entry
- GIVEN an admin exports zaaktype `omgevingsvergunning`
- THEN an audit entry MUST be created with: user, timestamp, zaaktype, version, and components included

#### Scenario 9.2: Import audit entry
- GIVEN an admin imports a case definition package
- THEN an audit entry MUST record: user, timestamp, package name, version, source environment, components imported, and conflict resolutions applied

#### Scenario 9.3: Migration audit entry
- GIVEN 50 cases are migrated from v1.2.0 to v1.3.0
- THEN an audit entry MUST record: user, timestamp, source version, target version, number of cases migrated, number of cases requiring manual intervention, and any errors

## Dependencies
- OpenRegister (for case type and schema storage, ConfigurationService for import)
- n8n MCP (for workflow export/import via `n8n_get_workflow`, `n8n_create_workflow`)
- OpenCatalogi (optional, for shared template registry)
- ZGW Catalogi API (optional, for interoperable export format)
- Nextcloud background jobs (for bulk migration processing)

---

### Current Implementation Status

**Not yet implemented.** No export/import functionality for case type definitions exists in the codebase. There are no controllers, services, or UI components for definition portability.

**Foundation available:**
- `SettingsService::loadConfiguration()` (`lib/Service/SettingsService.php`) imports register configuration from `procest_register.json` via OpenRegister's `ConfigurationService::importFromApp()`. This import/auto-configure pattern serves as a model for case definition import.
- The `procest_register.json` file (`lib/Settings/procest_register.json`) defines the complete schema structure for all case type entities, providing a reference format for portable definitions.
- The repair steps `InitializeSettings` (`lib/Repair/InitializeSettings.php`) and `LoadDefaultZgwMappings` (`lib/Repair/LoadDefaultZgwMappings.php`) demonstrate import/initialization patterns.
- OpenRegister's `ConfigurationService` has version-aware import with force-reimport capability.
- n8n workflows can be exported/imported via n8n API (n8n MCP tools: `n8n_get_workflow`, `n8n_create_workflow`).
- `CaseTypeDetail.vue` provides the UI integration point for export/import buttons.
- `CaseTypeAdmin.vue` provides the list view where import and template library buttons would be added.

**Partial implementations:** None.

### Standards & References

- **DTAP (Development, Test, Acceptance, Production)**: Standard software deployment pipeline that portability supports.
- **ZGW Catalogi API (VNG)**: Case type definitions (ZaakType, StatusType, ResultaatType, etc.) follow ZGW Catalogi API schemas, which serve as an interoperable export format.
- **GEMMA**: Dutch municipal architecture standard promoting reusable configurations across municipalities.
- **CaseFabric Live Migration**: Reference architecture for migrating running cases between definition versions using event-sourced migration with plan item matching.
- **Flowable Deployment Archives**: Reference for CMMN/BPMN/DMN model versioning and deployment packaging.
- **OpenRegister Configuration Format**: The existing `procest_register.json` format provides a well-structured configuration exchange format.
- **Common Ground**: Emphasizes configuration portability across municipalities via standardized APIs.
- **Semantic Versioning (semver)**: Version numbering standard for definition packages.
- **CMMN 1.1**: Case definitions map to CasePlanModel; export format should preserve CMMN semantics.
