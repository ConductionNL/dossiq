## Why

Zaaktype (Case Type) Management is the highest-demand capability across Dutch municipality tenders —
required by 199 tender mentions with a demand score of 603. The MVP tier (core case type CRUD,
status type management, draft/published lifecycle) is already functional, but the V1 admin tabs for
Result Types, Role Types, Property Definitions, Document Types, and Decision Types are missing from
the UI. Without these tabs, administrators cannot configure:

- Archival rules required by the Archiefwet / Selectielijst (retained vs. destroyed outcomes)
- Allowed participant roles per case type (treatment, advisory, decision-maker)
- Custom mandatory fields with format validation (text, number, date)
- Required document checklists tied to status gates
- Allowed decision types with objection and publication periods

Additionally, the "Select case type to initiate the correct BPMN process" capability (demand 18,
6 tender mentions) makes case types the entry point for workflow engine initiation — a key
integration point for the `workflowTemplate` entity already defined in ADR-000.

Backend publish validation is also missing: the publish guard currently only runs in the frontend,
meaning the API can publish incomplete case types, bypassing validation entirely.

## What Changes

- **REQ-CT-07**: Result type management tab — CRUD for result types with archival rules
  (`archiveAction`, `retentionPeriod`, `retentionDateSource`)
- **REQ-CT-08**: Role type management tab — CRUD for role types with generic role classification
- **REQ-CT-09**: Property definition management tab — CRUD for custom field definitions
  with format, `maxLength`, `allowedValues`, and `requiredAtStatus` gating
- **REQ-CT-10**: Document type management tab — CRUD for document types with `direction`,
  `order`, `confidentiality`, and status gating
- **REQ-CT-11**: Decision type management tab — CRUD for decision types with `objectionPeriod`
  and `publicationRequired` rules
- **REQ-CT-02b**: Backend publish validation — server-side enforcement of: at least one status
  type defined, at least one `isFinal` status, `validFrom` is set
- **REQ-CT-01d**: Active case deletion blocking — prevent deletion when active (non-final)
  cases reference the type; warn for closed-case references
- **REQ-CT-17**: Case type seed data — 4 realistic Dutch case types with full sub-entity
  configuration, imported via repair step

## Capabilities

### New Capabilities
- `result-type-management`: Admin tab for configuring case outcome types with Archiefwet
  retention rules per case type
- `role-type-management`: Admin tab for defining allowed participant roles per case type
  with generic role classification
- `property-definition-management`: Admin tab for custom field definitions with format
  validation, allowed value lists, and status-based required gates
- `document-type-management`: Admin tab for required document checklists with direction
  (incoming/internal/outgoing) and status gating
- `decision-type-management`: Admin tab for allowed decision types with objection periods
  and publication requirements
- `case-type-seed-data`: Pre-seeded realistic Dutch case types for development and demo
  environments, loaded via the standard `importFromApp()` repair step

### Modified Capabilities
- `case-type-publish-validation`: Extends existing frontend validation with server-side
  enforcement in the backend service layer
- `case-type-deletion`: Adds active-case count check before allowing deletion; returns
  structured error when active cases are found; warns for closed-case references

## Impact

- **Schema**: All sub-entity schemas already exist in `procest_register.json`
  (resultType, roleType, propertyDefinition, documentType, decisionType) — no schema changes
- **Frontend**: Five new Vue tab components in `src/views/settings/tabs/`:
  `ResultTypesTab.vue`, `RoleTypesTab.vue`, `PropertiesTab.vue`,
  `DocumentTypesTab.vue`, `DecisionTypesTab.vue`
- **Frontend**: `src/views/settings/CaseTypeDetail.vue` — add five new tab entries
- **Frontend**: `src/store/store.js` — register entity types for the five sub-entity schemas
- **Backend**: `lib/Service/ZgwZtcRulesService.php` — add publish validation and
  deletion guard logic
- **Seed data**: `lib/Settings/procest_register.json` — add 4 mock caseType objects
  plus associated statusType, resultType, and roleType mock objects
- **No new schemas** — all entities are pre-defined in ADR-000 and already registered
