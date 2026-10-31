# case-management delta: background-reads-run-as-the-system

## ADDED Requirements

### Requirement: A read or write with nobody signed in runs as the system (REQ-CM-83)

When dossiq reads a case type or writes a timeline entry with nobody signed in (an install, an import, an occ command, cron), it SHALL do so as the system. A request with a signed-in user SHALL run with that user's rights.

#### Scenario: The example set installs without anonymous refusals
@e2e exclude {occ-time behaviour; asserted in tests/Unit/Service/Support/BackgroundReadsRunAsSystemTest.php and CaseTimelineTest with an object service that refuses anonymous reads}

- **GIVEN** an instance installing an example set through occ
- **WHEN** a case is created and its case type and timeline are read
- **THEN** no "User 'Anonymous' does not have permission" line SHALL be logged by dossiq

#### Scenario: A handler is never elevated
- **GIVEN** a signed-in handler
- **WHEN** the same read runs in their request
- **THEN** it SHALL run with the handler's own rights
