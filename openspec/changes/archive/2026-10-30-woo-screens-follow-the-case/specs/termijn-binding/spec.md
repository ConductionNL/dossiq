# termijn-binding delta: woo-screens-follow-the-case

## ADDED Requirements

### Requirement: A term written with nobody signed in is written as the system (REQ-TERM-SYS-01)

A term instance written while nobody is signed in (an install, an example set, a cron job) MUST be written as the system, so the case gets its term. A term written by a signed-in user MUST be written with that user's rights and never elevated.

#### Scenario: The example set's cases get their terms
@e2e exclude {asserted in tests/Unit/Service/Termijn/TermInstanceStoreSystemWriteTest.php with an object service that refuses an anonymous create}

- **GIVEN** the example set install creating cases from occ, with no user
- **WHEN** a case binds its statutory term
- **THEN** the term instance MUST be saved
- **AND** nothing MUST log "TermInstanceStore persist failed"
