## ADDED Requirements

### Requirement: REQ-PNDG-006: the navigation carries a Contacts domain at the top level

You reach the people and organisations dossiq knows from the left navigation.
The manifest `menu` SHALL carry one entry with id `Contacts`, label Contacts,
icon `AccountGroupOutline`, route `Contacts` and order 25, so it renders
after the My work group and before the footer. `menu-layout.json` SHALL NOT
relocate, remove or lift it into the settings foldout. The entry SHALL have
no children and SHALL be the only entry whose route leads to the `Contacts`
page. The effective top level SHALL count Dashboard, My work and Contacts,
which stays inside the ADR-097 ceiling of six. This is the full structure
(`menu_structure` = `full`). In the default simple structure (REQ-PNDG-007)
Contacts stands with Organisations under the Relations caption, as board
DqZijbalk draws it.

#### Scenario: Contacts is a top-level entry
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a signed-in handler on the full structure
- **WHEN** you open dossiq
- **THEN** the navigation SHALL show a link labelled Contacts after My work
- **AND** the link SHALL open the Contacts index at `/contacts`

#### Scenario: Contacts stays alone at the top level
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** the full structure
- **AND** the effective menu after `menu-layout.json` is applied
- **WHEN** you count the top-level entries outside the footer and the settings foldout
- **THEN** they SHALL be Dashboard, My work and Contacts and nothing else
- **AND** Contacts SHALL render as a leaf, not a group

#### Scenario: The layout file leaves Contacts where the manifest put it
@e2e exclude a unit test over src/menu-layout.json asserts that `Contacts` appears in neither `relocations`, `removals` nor `settingsSection`; no browser is needed

- **GIVEN** `src/menu-layout.json`
- **WHEN** the unit test reads its relocation, removal and settings lists
- **THEN** none of them SHALL name `Contacts`
