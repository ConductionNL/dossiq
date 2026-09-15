## ADDED Requirements

### Requirement: A term declares the Algemene termijnenwet roll (REQ-TERM-014)

`deadlineDefinition` SHALL carry `rollToWorkingDay`. When true, the armed
timer and `endDateCalculated` SHALL roll an end date on a Saturday, Sunday
or recognised holiday to the next ordinary day, through the engine's
calendar. The default SHALL be true for `legalBasis` Algemene termijnenwet
only after the recognised-holiday list is confirmed and recorded.

#### Scenario: A term ending on Koningsdag rolls
@e2e exclude covered by the TermijnService fixture pair (D-1) on the seeded calendar

- **GIVEN** a definition with `rollToWorkingDay` true and a term that ends on 27 April
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be the next ordinary day
- **AND** the armed timer SHALL carry the roll rule

#### Scenario: A term without the roll does not move
@e2e exclude same fixture pair

- **GIVEN** the same term with `rollToWorkingDay` false
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be 27 April

### Requirement: dossiq holds no calendar of its own (REQ-TERM-015)

A structural test SHALL fail any file under `lib/` holding a holiday list
or a calendar class that is not in a reason-bearing allowlist naming its
retirement.

#### Scenario: A new holiday list fails the build
@e2e exclude structural; NoLocalCalendarTest over a fixture file

- **GIVEN** a service with a `HOLIDAYS` array
- **WHEN** the test runs
- **THEN** it SHALL fail naming the file

### Requirement: Term dates count in the organisation calendar's zone (REQ-TERM-016)

`TermijnService` SHALL build every term date in the organisation
calendar's time zone as answered by the engine, and SHALL NOT read
`tenantConfiguration.timezone` for it. The zone SHALL be stated in the
termijn settings.

#### Scenario: The zone is the calendar's
@e2e exclude covered by TermijnServiceTest::testDatesAreBuiltInCalendarZone with a stubbed calendar answering Europe/Amsterdam

- **GIVEN** the organisation calendar answers Europe/Amsterdam
- **WHEN** an instance is created at 23:30 UTC on 1 June
- **THEN** its `startDate` SHALL be 2 June

### Requirement: A moved date shows its reason on the case (REQ-TERM-017)

`case-terms` on `#CaseDetail` SHALL list every superseded timer of the
instance with its reason.

#### Scenario: A calendar change is visible
@e2e exclude waits on openregister `calendar-change-recomputes-timers`; the panel reads the history the engine writes

- **GIVEN** a term whose date moved after a holiday was added
- **WHEN** you open the case
- **THEN** Moved dates SHALL list the move with the reason
