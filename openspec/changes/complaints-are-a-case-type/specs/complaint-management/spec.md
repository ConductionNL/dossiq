## ADDED Requirements

### Requirement: A complaint is a case of the shipped complaint case type (REQ-CMP-CT-01)

Dossiq SHALL ship, in its register, the case type "Klacht behandeling" (`klacht-behandeling`) with the steps of board DqKlachten as statuses (Ontvangen, Bevestigd, Hoorgesprek, Oordeel, Antwoordbrief, Afgehandeld), the roles Klager, Betrokken medewerker and Klachtencoördinator, the results gegrond, deels gegrond, ongegrond, niet-ontvankelijk and ingetrokken, the hearing as a declared session (`sessions[]`, key `hoorgesprek`, waivable under Awb 9:10 lid 2) and the term of Awb 9:11 (6 weeks from receipt, 10 weeks when a complaints committee advises, one extension of at most 4 weeks). Registering, handling, hearing and deciding a complaint SHALL use the generic case, session, term and result capabilities; no class or route SHALL be named after complaints.

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

The upgrade SHALL carry every stored `complaint` object into a case of type "Klacht behandeling" through the case type's `recordImports` declaration (run by the generic repair step `RunUpgradeRecordImports`), keeping its receipt date, subject, complainant, handler, intake channel, term end and outcome, the record's id in `externalReference` and its complaint number as a former reference, and a second run SHALL create nothing.

#### Scenario: The carry-over is idempotent

- **GIVEN** three stored `complaint` objects
- **WHEN** the repair step runs twice
- **THEN** three complaint cases SHALL exist, each naming its source in `externalReference`
- @e2e exclude a repair step on upgrade has no browser surface; asserted in PHPUnit
