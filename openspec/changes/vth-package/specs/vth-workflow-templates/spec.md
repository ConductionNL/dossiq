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

## REMOVED Requirements

### Requirement: REQ-001: SeedVthWorkflowTemplates repair step SHALL idempotently seed the VTH workflow catalog from bundled JSON files

**Reason**: the flows ship in the `vth` package and the package installer writes them. The repair step and the catalogue files are deleted.

**Migration**: none. The installer adopts the flows the repair step wrote.

### Requirement: VTH workflow template activation service

**Reason**: the service repeated the generic template library for files named `vth-*`, and nothing called its routes. The `vth` package installs the VTH case types and flows.

**Migration**: none. An administrator activates a template through the generic template library, or installs a package.
