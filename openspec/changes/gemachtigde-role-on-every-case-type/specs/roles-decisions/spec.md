## ADDED Requirements

### Requirement: Every case type offers a Gemachtigde role (REQ-ROLE-009)

A generic `roleType` Gemachtigde with `genericRole: gemachtigde` and no
`caseType` SHALL be seeded once and offered on the Add party form of every
case type after the type's own role types. When a type declares its own
`gemachtigde` role, the generic one SHALL NOT be listed twice.

#### Scenario: A representative on a permit case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case of a type that declares no Gemachtigde role
- **WHEN** you press Add party and open the role type list
- **THEN** Gemachtigde SHALL be offered

#### Scenario: Bezwaar keeps one Gemachtigde
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a bezwaar case whose type declares its own Gemachtigde role
- **WHEN** you open the role type list
- **THEN** Gemachtigde SHALL be listed once

### Requirement: The Parties tab shows who is represented (REQ-ROLE-010)

A Gemachtigde row SHALL show the representative as participant and the
represented party in the column Represented by, read from `delegateFrom`.

#### Scenario: Represented party is visible
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a Gemachtigde role added for the requester of a case
- **WHEN** you open the Parties tab
- **THEN** the row SHALL show the representative and the requester under Represented by
