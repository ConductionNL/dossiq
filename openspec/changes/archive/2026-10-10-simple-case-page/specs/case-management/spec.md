## ADDED Requirements

### Requirement: The case carries the role of its status (REQ-CM-74)
The case MUST carry `statusRole`, the role of the status type it sits in,
computed by OpenRegister. A status type without a role MUST leave it empty.

#### Scenario: A case in a status with a role
@e2e exclude A declaration in the register, asserted in simpleCasePage.spec.js; OpenRegister's calculation engine fills it, and the coordinator checks it on a live instance after rematerialising.
- **GIVEN** a Woo request in the status Besluit, whose role is `review`
- **WHEN** the case is saved
- **THEN** `statusRole` MUST read `review`

#### Scenario: A case in a status without a role
@e2e exclude Same declaration test; the expression has no default.
- **GIVEN** a case whose status type declares no role
- **WHEN** the case is saved
- **THEN** `statusRole` MUST be empty

### Requirement: Existing cases get the role of their status (REQ-CM-77)
A repair step MUST write `statusRole` on every existing case whose status type
declares a role and whose `statusRole` differs from it. It MUST write in system
context, MUST write nothing else, MUST be safe to run again, and MUST NOT fail
the upgrade when one case refuses.

#### Scenario: An instance with cases from before the field
@e2e exclude A repair step, asserted in BackfillCaseStatusRoleTest against a store fake; the coordinator runs it on a live instance.
- **GIVEN** a case saved before `statusRole` existed, in a status whose role is `intake`
- **WHEN** the repair step runs
- **THEN** the case MUST carry `statusRole` `intake`
- **AND** a second run MUST write nothing

#### Scenario: One case refuses
@e2e exclude Asserted in BackfillCaseStatusRoleTest.
- **GIVEN** two cases to fill, one of which the store refuses
- **WHEN** the repair step runs
- **THEN** the other case MUST be filled
- **AND** the output MUST count one filled and one failed

### Requirement: The simple case page puts its actions on four levels (REQ-CM-75)
In the simple structure the case page MUST show one next-step button that
follows `statusRole`, three quick actions, and a More menu grouped as Case,
Publication and Dossier with the admin actions last and for administrators
only. Refresh and the help links MUST NOT be in that menu. Every one of the 25
header actions MUST stay reachable. The full structure MUST keep the page
unchanged.

#### Scenario: A Woo request in handling
@e2e exclude Needs a live instance on library 2.60.0 with rematerialised cases; the overlay, the stage logic and the grouping are asserted in simpleCasePage.spec.js with the library's own resolvers.
- **GIVEN** the simple structure and a Woo request whose status role is `in-progress`
- **WHEN** a handler opens the case
- **THEN** the page MUST show the card "What now? Handle the case" with its checklist
- **AND** the button Next step MUST open the Lifecycle dialog
- **AND** Send digital post, Generate document and Log contact MUST be visible buttons

#### Scenario: A case in intake that already has a handler
@e2e exclude Found on the live check of 5 October 2026 and asserted in simpleCasePage.spec.js: every stage with a card has a button without a condition a working case can fail.
- **GIVEN** the simple structure and a case in a status with role `intake` that has a handler
- **WHEN** the handler opens the case
- **THEN** the what-now card MUST show the button Next step

#### Scenario: A case whose status has no role
@e2e exclude Asserted in simpleCasePage.spec.js with the library's stage resolver.
- **GIVEN** the simple structure and a case with an empty `statusRole`
- **WHEN** a handler opens the case
- **THEN** the page MUST show no what-now card and no stage button
- **AND** Lifecycle MUST be the first entry of the More menu

#### Scenario: A handler does not see admin actions
@e2e exclude Asserted in simpleCasePage.spec.js with the library's grouping function.
- **GIVEN** the simple structure and a handler who is not an administrator
- **WHEN** they open More on a case
- **THEN** Inspect raw data and Inspect flow runs MUST NOT be listed
- **AND** Change type or version MUST be listed when the permission endpoint
  answers `mayRebind`, exactly as in the full structure

#### Scenario: The full structure
@e2e exclude An equality between the built page and the manifest, asserted in simpleCasePage.spec.js.
- **GIVEN** the full structure
- **WHEN** the case page is built
- **THEN** its config MUST equal the manifest's

### Requirement: The simple case page shows five tabs and More (REQ-CM-76)
In the simple structure the tab strip MUST show Overview, Documents, Contact,
Tasks and History, and MUST keep every other tab under More.

#### Scenario: Thirteen tabs become five and More
@e2e exclude Asserted in simpleCasePage.spec.js; the strip's rendering is library behaviour.
- **GIVEN** the simple structure
- **WHEN** a handler opens a case
- **THEN** the strip MUST show the five tabs in that order
- **AND** More MUST list the other eight
