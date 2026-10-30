## ADDED Requirements

### Requirement: A complaint is a case of the shipped complaint case type (REQ-CMP-CT-01)

Dossiq SHALL ship a package `klachtbehandeling` whose case type "Klacht" carries the Awb chapter 9 statuses, roles, result types and term (6 weeks from receipt, 10 weeks when a complaints committee advises, one extension of at most 4 weeks). Registering, handling, hearing and deciding a complaint SHALL use the generic case, session, term and result capabilities; no class or route SHALL be named after complaints.

#### Scenario: A complaint starts as a case with its term

- **GIVEN** the package `klachtbehandeling` is installed
- **WHEN** a handler starts a case of type "Klacht" with receipt date 1 March
- **THEN** the case SHALL carry a decision term ending 12 April
- **AND** the complaint list (board DqKlachten) SHALL show it
- @e2e tests/e2e/complaint-case-type.spec.ts

#### Scenario: The hearing is a session on the case

- **GIVEN** a complaint case in status "in behandeling"
- **WHEN** the handler plans the hearing with the complainant and the employee concerned
- **THEN** a session with those parties SHALL be linked to the case and appear in its history
- @e2e tests/e2e/complaint-case-type.spec.ts

### Requirement: Existing complaint records move onto cases once (REQ-CMP-CT-02)

The upgrade SHALL carry every stored `complaint` object into a case of type "Klacht", keeping its dates, parties, outcome and a `legacyRef`, and a second run SHALL create nothing.

#### Scenario: The carry-over is idempotent

- **GIVEN** three stored `complaint` objects
- **WHEN** the repair step runs twice
- **THEN** three complaint cases SHALL exist, each naming its source in `legacyRef`
- @e2e exclude a repair step on upgrade has no browser surface; asserted in PHPUnit
