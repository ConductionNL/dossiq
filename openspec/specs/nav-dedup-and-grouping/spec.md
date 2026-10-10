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

### Requirement: The simple structure is the default and an administrator can bring the full one back (REQ-PNDG-007)
dossiq MUST show the simple structure unless the app setting `menu_structure`
holds the word `full`. Admin settings MUST offer the choice between Simple and
Full. The page controller MUST provide the setting as initial state, so the menu
is right on the first render.

#### Scenario: A case handler opens dossiq on a new instance
- **GIVEN** an instance where `menu_structure` was never set
- **WHEN** a case handler opens dossiq
- **THEN** the main menu MUST show eight entries, the first group without a
  caption and the others under the captions Cases and Relations, with the
  caption My case types between them (amended by case-types-in-my-menu)
- **AND** the entries MUST be Dashboard, My work, Team queue, All cases, Board,
  Tasks, Contacts and Organisations, in that order
- **AND** the case types the user chose MUST stand under My case types

#### Scenario: An administrator brings the full menu back
@e2e exclude The e2e instance runs on the full structure for the whole suite (tests/e2e/ci-seed.sh sets it), and MenuStructureTest plus structureProfile.spec.js cover the setting itself.
- **GIVEN** an administrator on the admin settings page
- **WHEN** they choose Full under Menu structure
- **THEN** `menu_structure` MUST be stored as `full`
- **AND** the next time somebody opens dossiq the menu MUST be the full one

#### Scenario: A stored value that is not a structure
@e2e exclude A unit rule on a string, covered by MenuStructureTest and structureProfile.spec.js.
- **GIVEN** `menu_structure` holds `ful`
- **WHEN** somebody opens dossiq
- **THEN** the menu MUST be the simple one

### Requirement: One manifest carries both structures (REQ-PNDG-008)
Both structures MUST be built from the same manifest and the same fragments.
The full structure MUST be exactly what it was before profiles existed. Neither
structure may remove a page or a route.

#### Scenario: The full structure is unchanged
@e2e exclude An equality between two built manifests, asserted in structureProfile.spec.js against the library's real buildManifest.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the manifest is built
- **THEN** it MUST equal what `buildManifest` makes from the manifest, the
  fragments and `src/menu-layout.json`
- **AND** the menu MUST count 34 entries

#### Scenario: Every page keeps its route
@e2e exclude A comparison of two page lists, asserted in structureProfile.spec.js.
- **GIVEN** either structure
- **WHEN** the manifest is built
- **THEN** it MUST hold the same 66 pages, with the same ids

### Requirement: What leaves the simple menu stays one step away (REQ-PNDG-009)
An entry the full menu offers MUST, in the simple structure, be in the menu, in
settings, in the footer or integrations, or be linked from a page the simple
menu opens.

#### Scenario: A page that left the menu is one link away
- **GIVEN** the simple structure
- **WHEN** a case handler opens My work
- **THEN** the page MUST offer links to Your queue, Assigned to me and Close out
  your day
- **AND** each of those pages MUST still open by its own address

#### Scenario: Set-up entries move to settings
@e2e exclude A reading of the built menu, asserted in structureProfile.spec.js.
- **GIVEN** the simple structure
- **WHEN** the menu is built
- **THEN** Deleted cases, Objects and Mail intake log MUST be in settings
- **AND** every entry the full structure has in settings MUST still be there

#### Scenario: Woo requests open the cases list narrowed to Woo
@e2e exclude The Woo default and the entry's target are asserted in MenuCaseTypesServiceTest and ManifestControllerTest; the list reading `?caseType=` is library behaviour.
- **GIVEN** the simple structure and a user who never chose their menu case types
- **WHEN** a case handler chooses Woo requests under My case types
- **THEN** the cases list MUST open filtered on the Woo request case type that
  `register.d/81-woo-verzoek.json` seeds
- **AND** the entry MUST come from the user's menu choice (case-types-in-my-menu), not from a fixed entry in the profile
