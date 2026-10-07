## ADDED Requirements

### Requirement: Every statutory term computes on the engine calendar (REQ-TERM-018)

Every path under `lib/` that computes a statutory term date SHALL reach
the organisation's working calendar for the day the term lands on, and
SHALL NOT decide a statutory end date from raw date arithmetic alone. The
bezwaar term, the ingebrekestelling grace period and the pause credit and
remainder SHALL each roll an end date on a Saturday, Sunday or recognised
holiday to the next ordinary day, through the same rule
`deadlineDefinition.rollToWorkingDay` declares. The arithmetic that
computes `endDateCurrent` SHALL stay case data, as REQ-TOT-002 requires;
only the day it lands on SHALL be rolled.

#### Scenario: The bezwaar term lands on Koningsdag
@e2e exclude unit fixture pair on the seeded calendar; BezwaarTermijnSchedulerTest

- **GIVEN** a bekendmaking six weeks before 27 April
- **WHEN** `BezwaarTermijnScheduler::computeTermijn()` runs with the roll on
- **THEN** the bezwaar term SHALL end on the next ordinary day
- **AND** the reminder a week earlier SHALL also land on an ordinary day

#### Scenario: The ingebrekestelling grace ends on a Sunday
@e2e exclude unit fixture pair; NoticeOfDefaultServiceTest

- **GIVEN** a receipt date whose grace period of the regime ends on a Sunday
- **WHEN** `NoticeOfDefaultService` computes the start of the dwangsom window
- **THEN** the date SHALL move to the following Monday
- **AND** the regime's validity rules SHALL be unchanged

#### Scenario: A pause credit lands on a holiday
@e2e exclude unit fixture pair; DeadlinePauseServiceTest

- **GIVEN** a suspension whose credited days put `endDateCurrent` on Second Christmas Day
- **WHEN** the pause is resolved
- **THEN** `endDateCurrent` SHALL be the next ordinary day
- **AND** the same SHALL hold when an unused remainder is taken off

#### Scenario: A term without the roll is unchanged
@e2e exclude `rollToWorkingDay` is not on a definition until `terms-on-the-engine-calendar` lands, so the off half of every fixture pair is a unit case; TermijnTimerRollTest and BezwaarTermijnSchedulerTest

- **GIVEN** a definition with `rollToWorkingDay` false
- **WHEN** any of the three paths computes a date
- **THEN** the date SHALL be the raw one, as it is today

### Requirement: The date arithmetic under lib is audited with a verdict per file (REQ-TERM-019)

The repository SHALL carry an audit naming every file under `lib/` that
does date arithmetic, with a verdict per file: statutory term, business
date that must roll, or neither, and the reason. A count without verdicts
SHALL NOT stand in for it. The audit SHALL record the patterns it was
built from and the commit it was read at, so it can be repeated.

#### Scenario: A reader asks why a file is not a term
@e2e exclude documentation artefact; asserted by the structural test in REQ-TERM-020

- **GIVEN** a file that does date arithmetic and computes no term
- **WHEN** a reader opens the audit
- **THEN** the file SHALL be listed with the verdict neither
- **AND** the reason SHALL name what the date is for

### Requirement: A statutory path that skips the calendar fails the build (REQ-TERM-020)

A structural test SHALL fail any file the audit marks as a statutory term
path that computes a date without reaching the working calendar, naming
the file and the line. An allowlist entry SHALL carry a reason and the
change that will take the file. An entry without a named owner SHALL NOT
be accepted.

#### Scenario: A new statutory path without the calendar fails
@e2e exclude structural; EveryTermOnTheCalendarTest over a fixture file

- **GIVEN** a service that computes a beslistermijn with `modify('+N days')`
- **WHEN** the test runs
- **THEN** it SHALL fail naming the file and the line

#### Scenario: An allowlisted file passes and says why
@e2e exclude structural, over the repository's own source; EveryTermOnTheCalendarTest

- **GIVEN** a statutory path allowlisted with a reason and a named change
- **WHEN** the test runs
- **THEN** it SHALL pass
- **AND** an entry without a named change SHALL fail the test instead
