## ADDED Requirements

### Requirement: A case carries the identifier its case type chain shares

A case SHALL carry `caseTypeIdentifier`, the `identifier` of its case type, materialised by OpenRegister on save. Every version of a case type shares the identifier, so a list, quick filter or action that means every version of a case type SHALL filter on `caseTypeIdentifier` and not on the uuid of one version. A repair step SHALL fill the value on cases saved before it existed, writing one field in system context, only where the case type carries an identifier and the case differs, counting what it did and never stopping the upgrade.

#### Scenario: a Woo request on version 2
- **GIVEN** a case filed on version 2 of the Woo request case type
- **WHEN** a handler opens the "Woo requests" view
- **THEN** the case is listed, as a case on version 1 is

#### Scenario: an existing case
- **GIVEN** a case saved before the field existed
- **WHEN** the app is upgraded
- **THEN** the case carries its case type's identifier, and a second run writes nothing

### Requirement: A chosen case type follows its version chain

The case types a user keeps in the menu, and the default the menu offers before a user chooses (the Woo request case type), SHALL name the version in use when the chosen version has been superseded since, found along `supersededBy`, and SHALL list it once.

#### Scenario: the default after version 2
- **GIVEN** a user who never chose, and the first Woo version superseded by the second
- **WHEN** the menu is built
- **THEN** it lists version 2 of the Woo request case type
