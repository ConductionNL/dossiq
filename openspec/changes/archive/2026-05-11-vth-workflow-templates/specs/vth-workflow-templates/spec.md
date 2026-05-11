---
status: draft
---
# vth-workflow-templates Specification Delta

## ADDED Requirements

### Requirement: Canonical VTH Workflow Catalog (REQ-VWT-1)

The system SHALL ship a canonical catalog of exactly six VTH workflow templates as JSON files under `lib/Settings/seed/vth-workflow-templates/`: `aanvraag-omgevingsvergunning`, `toezichtbezoek`, `handhavingstraject`, `bezwaar`, `klacht-toezicht`, `spoedig-herstel`. Each file SHALL conform to the `workflowTemplate` schema in `procest_register.json`.

**Feature tier**: V1

#### Scenario: Catalog contains exactly six templates

- **GIVEN** a fresh checkout of `procest` at the version that introduces this change
- **WHEN** the `lib/Settings/seed/vth-workflow-templates/` directory is listed
- **THEN** the directory SHALL contain exactly six JSON files corresponding to the six template slugs
- **AND** every JSON file SHALL validate against the `workflowTemplate` JSON schema in `procest_register.json`

#### Scenario: Each template references a seeded VTH caseType

- **GIVEN** the VTH base register has been seeded by `base-register-seed-data`
- **WHEN** a catalog file is loaded
- **THEN** its `caseType` slug SHALL resolve to a `caseType` UUID that exists in the VTH register
- **AND** every `steps[].status`, `transitions[].fromStatus`, and `transitions[].toStatus` slug SHALL resolve to a `statusType` UUID belonging to that caseType

### Requirement: Statutory Deadlines on Transitions (REQ-VWT-2)

Each catalog template SHALL encode statutory deadlines (where applicable) on the relevant `transitions[].deadline` block, with `duration` as an ISO-8601 period, `source` as the citing law article, and `escalationRole` for the user role notified when the deadline elapses.

**Feature tier**: V1

#### Scenario: Aanvraag omgevingsvergunning carries Awb 4:13 deadline

- **GIVEN** the `aanvraag-omgevingsvergunning.json` catalog file
- **WHEN** the transition from `In behandeling` to `Besluit` is inspected
- **THEN** the transition SHALL carry `deadline: { duration: "P56D", source: "Awb 4:13", escalationRole: "afdelingsmanager" }`

#### Scenario: Klacht over toezicht carries Awb 9:11 deadline

- **GIVEN** the `klacht-toezicht.json` catalog file
- **WHEN** the transition from `In behandeling` to `Beoordeeld` is inspected
- **THEN** the transition SHALL carry `deadline: { duration: "P42D", source: "Awb 9:11", escalationRole: "klachtcoördinator" }`

### Requirement: Idempotent Seed-Data Repair Step (REQ-VWT-3)

The system SHALL provide a repair step `lib/Migration/SeedVthWorkflowTemplates.php` that imports the catalog as published `workflowTemplate` v1 objects via `WorkflowDefinitionService::createDraft()` + `publish()`. The repair step SHALL be idempotent and SHALL NEVER write directly to `workflowTemplate` rows.

**Feature tier**: V1

#### Scenario: Repair step seeds all templates on fresh install

- **GIVEN** a Procest tenant with no existing VTH `workflowTemplate` objects and the VTH caseTypes seeded
- **WHEN** the repair step runs
- **THEN** six `workflowTemplate` objects SHALL exist
- **AND** each SHALL be `lifecycleStatus: published`, `isActive: true`, `version: 1`
- **AND** each SHALL be linked from the corresponding `caseType.workflowDefinition` (only when that caseType had no pinned definition beforehand)

#### Scenario: Repair step is idempotent on re-run

- **GIVEN** the repair step has already run once and seeded all six templates
- **WHEN** the repair step runs again
- **THEN** no new `workflowTemplate` objects SHALL be created
- **AND** no existing `workflowTemplate` SHALL be modified
- **AND** the step SHALL complete without raising errors

#### Scenario: Repair step routes mutations through WorkflowDefinitionService

- **GIVEN** the repair step is implementing the seed
- **WHEN** it creates a new `workflowTemplate`
- **THEN** it SHALL call `WorkflowDefinitionService::createDraft()` followed by `WorkflowDefinitionService::publish()`
- **AND** it SHALL NEVER call `ObjectService::saveObject()` directly with a `workflowTemplate` payload

#### Scenario: Repair step skips templates with unresolved status slugs

- **GIVEN** a catalog file references a `statusType` slug that does not exist on the linked caseType
- **WHEN** the repair step processes that file
- **THEN** it SHALL log a warning via `$this->logger->error()`
- **AND** it SHALL skip the template entirely (no partial seed)
- **AND** it SHALL continue processing the remaining catalog files

### Requirement: Admin-Triggered Catalog Import Endpoint (REQ-VWT-4)

The system SHALL expose `POST /api/vth-workflow-templates/import` accepting a body `{ slugs: [...] }` for ad-hoc selective re-import of catalog entries. The endpoint SHALL be restricted to administrators.

**Feature tier**: V1

#### Scenario: Admin can selectively import a subset of templates

- **GIVEN** a tenant administrator on a system with no `klacht-toezicht` template installed
- **WHEN** they POST `/api/vth-workflow-templates/import` with body `{ slugs: ["klacht-toezicht"] }`
- **THEN** the system SHALL invoke the repair-step seeding path for that one slug only
- **AND** the response SHALL be `{ installed: ["klacht-toezicht"], skipped: [], failed: [] }`

#### Scenario: Non-admin import attempt is rejected

- **GIVEN** a user without the `admin` group membership
- **WHEN** they POST `/api/vth-workflow-templates/import`
- **THEN** the request SHALL be rejected with HTTP 403

#### Scenario: Endpoint returns no raw exception messages

- **GIVEN** the import handler raises an unexpected exception
- **WHEN** the response is built
- **THEN** the response body SHALL contain only a static error string
- **AND** the original exception SHALL be logged via `$this->logger->error()`
- **AND** `$e->getMessage()` SHALL NEVER appear in the response

### Requirement: Catalog List UI (REQ-VWT-5)

The system SHALL render a "Sjablonen-catalogus" tab in admin settings (`VthWorkflowTemplatesTab.vue`) listing the canonical catalog with title, caseType, status count, deadline summary, and an installed-yes-no badge per template.

**Feature tier**: V1

#### Scenario: Catalog tab is visible only to admins

- **GIVEN** a user without admin group membership opens the Procest settings page
- **WHEN** the tabs are rendered
- **THEN** the `VTH-sjablonen` tab SHALL NOT be visible

#### Scenario: Catalog rows show installed state

- **GIVEN** the catalog tab is opened on a tenant where `toezichtbezoek` is installed but `klacht-toezicht` is not
- **WHEN** the row for each template is rendered
- **THEN** the `toezichtbezoek` row SHALL carry the badge `Geïnstalleerd`
- **AND** the `klacht-toezicht` row SHALL carry the badge `Niet geïnstalleerd`

### Requirement: Per-Template Detail and Preview (REQ-VWT-6)

The system SHALL render a per-template detail dialog (`VthWorkflowTemplateDetailDialog.vue`) with read-only tabs for `Steps`, `Transitions`, `Deadlines`, and `Diff vs canonical v1`.

**Feature tier**: V1

#### Scenario: Detail dialog renders steps and transitions read-only

- **GIVEN** the detail dialog is opened for `aanvraag-omgevingsvergunning`
- **WHEN** the `Steps` and `Transitions` tabs are inspected
- **THEN** both tabs SHALL reuse the read-only mode of `WorkflowStepsEditor` / `WorkflowTransitionsEditor` from `workflow-definition-model`
- **AND** no edit, save, or delete controls SHALL be present in the dialog
- **AND** the only mutating actions SHALL be the footer `Installeren` (if not installed) or `Klonen voor wijziging` (if installed)

#### Scenario: Deadlines tab renders statutory deadlines as readable rows

- **GIVEN** the detail dialog is opened for `aanvraag-omgevingsvergunning`
- **WHEN** the `Deadlines` tab is inspected
- **THEN** at least one row SHALL render the deadline as `8 weken (Awb 4:13) → afdelingsmanager`

### Requirement: Selective Enable per Tenant (REQ-VWT-7)

The system SHALL allow a tenant administrator to install only the catalog templates relevant to their service portfolio, including a multi-select toolbar action. Uninstall SHALL be offered only when the template's active version has zero open cases bound to it; otherwise the action SHALL be replaced with a link to the WDM `Deprecate` flow.

**Feature tier**: V1

#### Scenario: Multi-select install triggers a single import call

- **GIVEN** the admin selects rows for `toezichtbezoek` and `handhavingstraject` and clicks `Installeer geselecteerde`
- **WHEN** the action fires
- **THEN** a single `POST /api/vth-workflow-templates/import` SHALL be dispatched with `{ slugs: ["toezichtbezoek", "handhavingstraject"] }`
- **AND** the catalog list SHALL refresh after the response

#### Scenario: Uninstall is blocked when open cases reference the template

- **GIVEN** the `handhavingstraject` template has at least one case in a non-final status bound to its active version
- **WHEN** the admin opens the row's action menu
- **THEN** the `Uninstall` action SHALL NOT be present
- **AND** instead a link `Deprecate via beheer` SHALL deep-link into the WDM admin tab

### Requirement: Diff Against Canonical Published Version (REQ-VWT-8)

The system SHALL render a read-only diff view comparing the tenant's currently active `workflowTemplate` for a given caseType against the canonical v1 from the shipped catalog, listing added, removed, and modified steps / transitions / deadlines.

**Feature tier**: V1

#### Scenario: Diff reports added and removed steps

- **GIVEN** the tenant has cloned `aanvraag-omgevingsvergunning` v1 into v2 and added one extra step "Externe consultatie"
- **WHEN** the diff tab is opened for that template
- **THEN** the diff SHALL list `steps.added: ["Externe consultatie"]`
- **AND** the diff SHALL list `steps.removed: []`

#### Scenario: Diff reports modified deadlines

- **GIVEN** the tenant has cloned `aanvraag-omgevingsvergunning` v1 into v2 and changed the Awb 4:13 deadline from 8 weken to 6 weken
- **WHEN** the diff tab is opened
- **THEN** the diff SHALL list a `deadline.changed` entry for the `In behandeling → Besluit` transition
- **AND** the entry SHALL carry the canonical value `P56D` and the tenant value `P42D`

#### Scenario: Diff view is strictly read-only

- **GIVEN** the diff tab is open and shows non-empty changes
- **WHEN** the rendered DOM is inspected
- **THEN** no `Revert`, `Apply canonical`, or other write action SHALL be present
- **AND** the only path to restore canonical behaviour SHALL be via clone-and-publish in the WDM admin UI
