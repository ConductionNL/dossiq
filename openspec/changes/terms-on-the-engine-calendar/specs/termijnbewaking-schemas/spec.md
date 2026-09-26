## ADDED Requirements

### Requirement: A term declares the Algemene termijnenwet roll (REQ-TERM-014)

`deadlineDefinition` SHALL carry `rollToWorkingDay`. When true, the armed
timer and `endDateCalculated` SHALL roll an end date on a Saturday, Sunday
or recognised holiday to the next ordinary day, through the engine's
calendar.

A definition that does not carry the flag SHALL roll. Awt art. 1 applies by
law rather than by configuration, and the flag switches the roll OFF for a
term the Awt does not govern. The condition this requirement used to place on
that default, that the recognised-holiday list be confirmed and recorded
first, is met: which days this organisation does not work is administered on
the working calendar in OpenRegister, on a screen an administrator reads,
beside the working weekdays and the opening hours. A default nobody could
change was the risk; a default somebody administers is a decision.

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

### Requirement: Every working-day answer reads the administered calendar (REQ-TERM-026)

Which weekdays the organisation works, and which days it is closed, SHALL be
read from the working calendar it administers in OpenRegister, by every
caller in this app and not only by the statutory roll. `WorkingDayCalculator`
SHALL ask that calendar first and answer from its built-in Dutch list only
when no calendar is answering, SHALL say so once in the log when it does, and
SHALL be able to report which of the two decided.

An administered closure day SHALL be a non-working day, and a day the
calendar works SHALL be a working day even when the built-in list names it a
holiday. Configuration wins in both directions: a reader that could only add
closure days would honour a local closure and still refuse to work on a day
the organisation has declared open, and both mistakes produce a date nobody
on the page can tell from a correct one.

#### Scenario: Every working-day answer reads the administered calendar
@e2e tests/e2e/the-working-week-is-administered.spec.ts

- **GIVEN** a complaint received on Monday 3 May 2027, whose Awb acknowledgement term is five working days
- **AND** the administered calendar works 5 May, which the built-in Dutch list treats as a closure
- **WHEN** the complaint is created
- **THEN** the acknowledgement deadline SHALL be Monday 10 May
- **AND** it SHALL NOT be Tuesday 11 May, which is the answer only the built-in list gives

#### Scenario: A day the calendar works is a working day
@e2e exclude {the discriminating pair in both directions is `WorkingDaysAreAdministeredTest`, which doubles the reader with `onlyMethods`; the browser half is the scenario above}

- **GIVEN** a calendar that works 25 December
- **WHEN** a working-day answer is asked for that date
- **THEN** it SHALL be a working day
- **AND** the built-in list SHALL NOT override it

#### Scenario: An instance with no calendar keeps the dates it had
@e2e exclude {the assertion is that nothing changed on an instance without OpenRegister, which has no screen; `WorkingDaysAreAdministeredTest::testWithoutACalendarTheBuiltInListStillAnswers` is the coverage}

- **GIVEN** an instance where no working calendar answers
- **WHEN** a working-day answer is asked for
- **THEN** the built-in Dutch list SHALL decide, exactly as it did before
- **AND** the fall back SHALL be said once in the log rather than per question

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
