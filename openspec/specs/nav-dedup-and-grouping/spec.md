---
status: done
---

# nav-dedup-and-grouping Specification

## Purpose
Cleans up the dossiq left navigation so no group and its child share the same label, relabelling the duplicate "Cases" and "Analytics" leaves and retiring the duplicate substitution entry while keeping every page routable. It introduces a "Work" group for the operational work-queue surfaces and completes the Cases and Analytics groups with their dossier and reporting surfaces, implemented purely through src/manifest.json and src/menu-layout.json with no backend, schema, or engine changes.
## Requirements
### Requirement: REQ-PNDG-001 — The system SHALL render each navigation label at most once per group

The system SHALL ensure no navigation group container and its own child render an identical label.
The `Cases` leaf menu entry SHALL be relabelled from "Cases" to "All cases" and the `Analytics`
leaf menu entry SHALL be relabelled from "Analytics" to "Doorlooptijd", while the `CasesGroup`
container keeps label "Cases" and the `AnalyticsGroup` container keeps label "Analytics". The
routes (`Cases`, `Doorlooptijd`) and pages of the relabelled leaves SHALL be unchanged. (ADR-012
deduplication; ADR-037 the labels live in `src/manifest.json#menu`.)

#### Scenario: Cases group no longer contains a child labelled "Cases"

- **GIVEN** the merged navigation after `applyMenuRelocations` runs
- **WHEN** a behandelaar opens the "Cases" group in the left nav
- **THEN** the group container SHALL be labelled "Cases"
- **AND** its case-index child SHALL be labelled "All cases", not "Cases"
- **AND** the child SHALL still route to `Cases` (`/cases`)

#### Scenario: Analytics group no longer contains a child labelled "Analytics"

- **GIVEN** the merged navigation after `applyMenuRelocations` runs
- **WHEN** a team lead opens the "Analytics" group in the left nav
- **THEN** the group container SHALL be labelled "Analytics"
- **AND** its doorlooptijd child SHALL be labelled "Doorlooptijd", not "Analytics"
- **AND** the child SHALL still route to `Doorlooptijd` (`/doorlooptijd`)

### Requirement: REQ-PNDG-002 — The system SHALL expose a single substitution navigation entry while keeping the substitution-settings page routable

The system SHALL retire the duplicate `SubstitutionMenu` top-level navigation entry (label
"Substitution") by adding its id to `src/menu-layout.json#removals`, leaving `SubstitutionAdminMenu`
(label "Substitutions & reassignment") as the only substitution navigation entry. The
`SubstitutionSettings` page (`/substitution`, component `SubstitutionSettingsView`) SHALL remain
declared in `src/manifest.json#pages` and SHALL stay routable for deep links and e2e specs.
This change SHALL NOT relocate that page under Settings (that is the sibling
`dossiq-config-to-settings`).

#### Scenario: Only one substitution entry appears in the nav

- **GIVEN** the merged navigation after `applyMenuRemovals` runs with `SubstitutionMenu` in removals
- **WHEN** a user scans the navigation
- **THEN** exactly one substitution-related top-level entry SHALL be present ("Substitutions & reassignment", route `SubstitutionAdmin`)
- **AND** no entry labelled "Substitution" SHALL appear in the navigation

#### Scenario: The per-user substitution settings page stays reachable

- **GIVEN** the `SubstitutionMenu` nav entry has been removed
- **WHEN** a user opens `/substitution` directly (deep link or e2e spec)
- **THEN** the `SubstitutionSettingsView` page SHALL render
- **AND** the page SHALL remain declared in `src/manifest.json#pages`

### Requirement: REQ-PNDG-003 — The system SHALL group the operational work-queue surfaces under a single "Work" group

The system SHALL add a route-less `WorkGroup` container (label "Work") to `src/manifest.json#menu`
and SHALL relocate the work-queue leaves `MyWork`, `Werkvoorraad`, `WorkflowBoard`, and `Transfers`
under it via `src/menu-layout.json#relocations`, so these four surfaces render as children of one
"Work" group rather than as flat top-level entries. The relocations SHALL be applied by the
existing `applyMenuRelocations` engine; no engine code SHALL be added. Each relocated leaf's route
and page SHALL be unchanged.

#### Scenario: Work-queue surfaces render under the Work group

- **GIVEN** the merged navigation after `applyMenuRelocations` runs
- **WHEN** the left nav is rendered
- **THEN** a top-level "Work" group SHALL contain `MyWork`, `Werkvoorraad`, `WorkflowBoard`, and `Transfers` as children
- **AND** none of those four SHALL appear as a flat top-level entry
- **AND** each child SHALL keep its original route

#### Scenario: An empty Work group does not render

- **GIVEN** the `applyMenuRelocations` engine's final route-less-and-childless filter
- **WHEN** the `WorkGroup` container has no children for a user's role
- **THEN** the "Work" group SHALL NOT render (route-less container with no children is filtered out)

### Requirement: REQ-PNDG-004 — The system SHALL complete the Cases and Analytics groups for the operational dossier and reporting surfaces

The system SHALL relocate `LocationsMenu`, `StatusRecordsMenu`, and `ArchiefDashboardMenu` under
`CasesGroup`, and SHALL keep `Cases` under `CasesGroup`; and SHALL keep `Analytics`, `CaseMap`, and
`TermijnDashboardMenu` under `AnalyticsGroup` — all via `src/menu-layout.json#relocations`. The
work-queue leaves (`Werkvoorraad`, `WorkflowBoard`, `Transfers`) SHALL be moved off `CasesGroup`
onto `WorkGroup` (REQ-PNDG-003), so `CasesGroup` holds only case-dossier surfaces. No page SHALL be
deleted and no route SHALL change.

#### Scenario: Cases group holds case-dossier surfaces only

- **GIVEN** the merged navigation after relocations
- **WHEN** the "Cases" group is opened
- **THEN** it SHALL contain "All cases" (`Cases`), `Locations`, `StatusRecords`, and `ArchiefDashboard`
- **AND** it SHALL NOT contain `Werkvoorraad`, `WorkflowBoard`, or `Transfers` (those live under "Work")

#### Scenario: Analytics group holds the reporting surfaces

- **GIVEN** the merged navigation after relocations
- **WHEN** the "Analytics" group is opened
- **THEN** it SHALL contain "Doorlooptijd" (`Analytics`), `CaseMap`, and `TermijnDashboard`
- **AND** each SHALL keep its original route

### Requirement: REQ-PNDG-005 — The system SHALL confine all dedup and grouping edits to manifest.json and menu-layout.json

The system SHALL implement this change using only `src/manifest.json` (relabel the two duplicate
leaves; add the `WorkGroup` container) and `src/menu-layout.json` (relocations + the one removal),
honouring ADR-037's separation: `src/manifest.d/*` fragments own *what exists* and SHALL NOT be
edited here, while `src/menu-layout.json` owns *where entries live*. No backend, schema, repair
step, or `applyMenuRelocations`/`applyMenuRemovals` engine code SHALL be modified.

#### Scenario: No fragment or backend file is touched

- **GIVEN** the diff for this change
- **WHEN** the changed file set is inspected
- **THEN** only `src/manifest.json`, `src/menu-layout.json`, and openspec/test files SHALL be modified
- **AND** no file under `src/manifest.d/` and no PHP under `lib/` SHALL be modified

