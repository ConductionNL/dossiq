## ADDED Requirements

### Requirement: Progress and days left are computed at read time (REQ-TERM-068)

A case SHALL carry a progress figure derived from the phases completed and
the term consumed, and a days-left count, both computed at read time from
the bound terms. Neither SHALL be stored. Both SHALL be on the case page,
and the same computation SHALL feed the list column when the library
offers one.

#### Scenario: a handler triages by progress
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case two phases into four with half its term consumed
- **WHEN** a handler opens it
- **THEN** it SHALL show a progress figure and a days-left count

#### Scenario: an extension moves the figure without a write
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case showing 4 days left
- **WHEN** its term is extended by 14 days
- **THEN** the next read SHALL show 18 days left
- **AND** no progress value SHALL have been stored

#### Scenario: the list and the case page agree

- **GIVEN** a case rendered in a list and on its page
- **WHEN** both are read
- **THEN** the progress figure SHALL be the same in both

### Requirement: The age of the open workload is answerable per status (REQ-TERM-069)

dossiq SHALL report how old the open workload is now, grouped by status,
over cases that are still open. It SHALL be one aggregation over the
object store and SHALL NOT be a stored report or a nightly job. It SHALL
NOT be computed from closed cases.

#### Scenario: a teamleider sees the age of what is still standing
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** open cases sitting in three statuses
- **WHEN** the workload age report is read
- **THEN** it SHALL give an age per status over the open cases

#### Scenario: closed cases do not enter the number

- **GIVEN** a status holding both open and closed cases
- **WHEN** the workload age is read
- **THEN** only the open cases SHALL be counted

#### Scenario: the report is read live

- **GIVEN** a case that has just changed status
- **WHEN** the workload age is read again
- **THEN** the case SHALL be counted under its new status
