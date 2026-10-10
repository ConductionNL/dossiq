## ADDED Requirements

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
