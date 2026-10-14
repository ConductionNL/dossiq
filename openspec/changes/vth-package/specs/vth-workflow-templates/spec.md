# vth-workflow-templates delta: vth-package

## ADDED Requirements

### Requirement: The VTH flows ship in the vth package

The `vth` package SHALL carry four published flows: "Aanvraag omgevingsvergunning", "Toezichtbezoek", "Handhavingstraject" (route regulier) and "Spoedig herstel (Awb 5:31)" (route spoedeisend). A flow SHALL name only statuses of its own case type. Handhavingszaak SHALL name the regulier flow as its default.

#### Scenario: Handhavingszaak has two routes
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** an instance with the `vth` package installed
- **WHEN** an administrator opens the case type handhavingszaak
- **THEN** the page SHALL show the flows "Handhavingstraject" and "Spoedig herstel (Awb 5:31)"

#### Scenario: A flow names a status of another case type
@e2e exclude no user interface; tests/Unit/Settings/PackageFlowStatusReferencesTest.php sweeps every shipped package

- **GIVEN** a package flow whose step names a status of another case type
- **WHEN** the unit suite runs
- **THEN** the sweep SHALL fail and name the flow and the status

### Requirement: The VTH flow drafts ship as draft flows

The `vth` package SHALL carry six draft flows: "Omgevingsvergunning Bouwactiviteit (regulier)", "Omgevingsvergunning Bouwactiviteit (uitgebreid)", "Sloopmelding", "Toezichtzaak Bouw", "Toezichtzaak Milieu" and "Handhavingszaak". A draft flow SHALL be stored with `lifecycleStatus` `draft`, `isDraft` true and `isActive` false. A draft flow SHALL NOT be the flow a case follows, on any route, and SHALL NOT be the default flow of its case type, until an administrator publishes it. A draft flow SHALL meet the same guard, action and status rules as a published flow. A draft flow the instance already holds SHALL be left as it is on upgrade.

#### Scenario: A draft flow is stored as a draft
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** an instance with the `vth` package installed
- **WHEN** an administrator reads the flows of sloopmelding
- **THEN** the flow "Sloopmelding" SHALL exist once
- **AND** its `lifecycleStatus` SHALL be `draft`

#### Scenario: A case does not follow a draft flow
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** handhavingszaak holds the published flow "Handhavingstraject" and the draft flow "Handhavingszaak" on the route regulier
- **WHEN** the app is asked which flow a case of handhavingszaak follows
- **THEN** the answer SHALL be "Handhavingstraject"

#### Scenario: A package flow has lifecycle fields that disagree
@e2e exclude no user interface; tests/Unit/Settings/PackageDraftFlowsTest.php sweeps every shipped package

- **GIVEN** a package flow with `lifecycleStatus` `draft` and `isActive` true
- **WHEN** the unit suite runs
- **THEN** the sweep SHALL fail and name the flow and the field

### Requirement: A case type that holds only draft flows keeps its basis flow

The workflow backfill SHALL write the basis flow for a case type whose only flows are drafts. It SHALL leave alone a case type that holds a published or a deprecated flow, or that names a default flow.

#### Scenario: A case type with only a draft flow keeps its basis flow
@e2e tests/e2e/packages/vth.spec.ts

- **GIVEN** a clean instance, where the `vth` package gives sloopmelding one draft flow and no published flow
- **WHEN** the app is asked which flow a case of sloopmelding follows
- **THEN** the answer SHALL be a published flow
- **AND** it SHALL NOT be the draft flow "Sloopmelding"

## REMOVED Requirements

### Requirement: REQ-001: SeedVthWorkflowTemplates repair step SHALL idempotently seed the VTH workflow catalog from bundled JSON files

**Reason**: the flows ship in the `vth` package and the package installer writes them. The repair step and the catalogue files are deleted.

**Migration**: none. The installer adopts the flows the repair step wrote.

### Requirement: VTH workflow template activation service

**Reason**: the service repeated the generic template library for files named `vth-*`, and nothing called its routes. The `vth` package installs the VTH case types and flows.

**Migration**: none. An administrator activates a template through the generic template library, or installs a package.
