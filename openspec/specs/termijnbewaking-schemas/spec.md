---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijnbewaking-schemas Specification

## Purpose
Declares the six OpenRegister schemas for the termijn/dwangsom engine — TermijnDefinitie, TermijnInstance, TermijnGebeurtenis, Ingebrekestelling, DwangsomBerekening, and DwangsomUitbetaling — with their documented properties, enums, and relations so every consumer reads the same canonical shape. It also seeds three demo TermijnDefinities (Omgevingsvergunning-regulier, Wmo-aanvraag, and Woo-verzoek) via the register repair step so the engine has working configuration out of the box.

## Requirements

### Requirement: Termijn and dwangsom register schemas (REQ-TERM-SCHEMA-001)

The system SHALL declare six OpenRegister schemas — `TermijnDefinitie`, `TermijnInstance`, `TermijnGebeurtenis`, `Ingebrekestelling`, `DwangsomBerekening`, and `DwangsomUitbetaling` — with the documented properties, enums, and relations, registered through the dossiq register template so every consumer reads the same canonical shape.

#### Scenario: Schemas materialise with documented properties

- **GIVEN** the dossiq register template is imported into OpenRegister
- **WHEN** the six termijn/dwangsom schemas are materialised
- **THEN** each schema SHALL expose its documented required properties (e.g. `TermijnInstance` SHALL expose `zaak`, `termijnDefinitie`, `startDatum`, `einddatumBerekend`, `einddatumActueel`, and a `status` enum of {lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken})
- **AND** `TermijnGebeurtenis` SHALL be modelled as an append-only audit schema with a `type` enum of {start, pauze, hervat, verleng, voltooi, overschreden, ingebrekestelling-ontvangen, dwangsom-gestart}

#### Scenario: Relations between schemas are declared

- **GIVEN** the schemas are registered
- **WHEN** the relations are inspected
- **THEN** `TermijnInstance` SHALL relate to one `TermijnDefinitie`, to many `TermijnGebeurtenis`, and to many `Ingebrekestelling`
- **AND** `Ingebrekestelling` SHALL relate one-to-one to `DwangsomBerekening`, and `DwangsomBerekening` one-to-one to `DwangsomUitbetaling`

### Requirement: Seed TermijnDefinities for demo zaaktypen (REQ-TERM-SCHEMA-002)

The system SHALL seed three `TermijnDefinitie` rows — Omgevingsvergunning-regulier (56 days), Wmo-aanvraag (42 days), and Woo-verzoek (28 days, custom €15/day regime) — via the OpenRegister repair-step import so the engine has working configuration out of the box.

#### Scenario: Seed definitions load via repair step

- **GIVEN** the dossiq app is enabled and the register repair step runs
- **WHEN** the seed import completes
- **THEN** the three `TermijnDefinitie` rows SHALL be queryable via the OpenRegister REST API
- **AND** the Woo-verzoek definition SHALL carry `afwijkendDwangsomRegime` describing the €15/day, max €500 regime

#### Scenario: Integration test verifies materialised fields

- **GIVEN** the schemas and seed data are imported
- **WHEN** the integration test queries each schema and the seed rows
- **THEN** the test SHALL assert the documented required properties are present
- **AND** the test SHALL assert the three seed `TermijnDefinitie` rows exist with their documented durations

### Requirement: A term declares whether it counts calendar or working days (REQ-TERM-013)

`deadlineDefinition` SHALL carry `countingMode` with values `calendarDays`
(default) and `workingDays`. The armed timer SHALL use it as its SLA unit
and `endDateCalculated` SHALL be computed in the same mode, so the two
agree at day granularity.

#### Scenario: A working-day term skips the weekend
@e2e exclude covered by the TermijnService fixture pair (D-3); the calendar is seeded, not driven through the UI

- **GIVEN** a definition of 5 `workingDays` and a case started on a Thursday
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be the next Thursday
- **AND** the armed timer SHALL carry `unit: businessDays`

#### Scenario: A calendar-day term is unchanged
@e2e exclude covered by the same fixture pair

- **GIVEN** a definition without `countingMode` of 56 days
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be start plus 56 days as before

#### Scenario: The mode is visible in settings
@e2e tests/e2e/termijn-counting-mode.spec.ts

- **GIVEN** the termijn settings tab
- **WHEN** you open a definition
- **THEN** Counting mode SHALL be shown and editable

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

### Requirement: A case type declares a first-response term, and the overrun is stored (REQ-TCF-01)

A case type SHALL be able to declare a first-response term as an ordinary
term definition. The outcome SHALL be recorded on the case whether it was met
or missed, and a miss SHALL store the size of the overrun in the term's own
counting mode. A flag alone SHALL NOT stand in for the size.

#### Scenario: A missed first response stores how late it was
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a case type with a first-response term of three working days
- **WHEN** the first response is sent on the sixth working day
- **THEN** the case SHALL record the term as missed
- **AND** the overrun SHALL be stored as three working days

#### Scenario: A met first response is recorded too
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** the same case type
- **WHEN** the first response is sent on the second working day
- **THEN** the case SHALL record the term as met with no overrun

#### Scenario: The complaint acknowledgement keeps its dates
@e2e exclude behaviour parity over the existing service, asserted in tests/Unit/Service/ComplaintServiceTest.php

- **GIVEN** a complaint case
- **WHEN** its acknowledgement deadline is computed from the declaration
- **THEN** the date SHALL be the one the private constant produces today

#### Scenario: The overrun is reportable
@e2e exclude asserted in tests/Unit/Service/Term/FirstResponseOutcomeTest.php::testTheOverrunIsReportedFromTheStoredNumbers

- **GIVEN** eleven missed first responses across a quarter
- **WHEN** the overrun is reported per case type
- **THEN** the count and the average overrun SHALL be returned from the stored numbers

### Requirement: A term resolves from the organisation, the service and the priority (REQ-TCF-02)

A term SHALL resolve in a declared order: the organisation, then the
service, then the priority, falling back to the case type's own term. One
case type SHALL therefore carry different terms for different participating
organisations without being duplicated. The resolution that produced a
case's term SHALL be recorded on the case.

#### Scenario: One case type, two municipal norms
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** one case type shared by two municipalities with different agreed norms
- **WHEN** a case is created for each
- **THEN** each SHALL receive its own municipality's term

#### Scenario: The fallback is the case type
@e2e exclude asserted in tests/Unit/Service/Term/TermResolutionTest.php::testAnOrganisationThatDeclaresNothingFallsBackToTheCaseType

- **GIVEN** an organisation that declares no term for a case type
- **WHEN** a case is created there
- **THEN** the case type's own term SHALL be used

#### Scenario: A disputed date can be explained
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a case whose term was resolved a year ago
- **WHEN** the term is read
- **THEN** the case SHALL name which resolution produced it
- **AND** the explanation SHALL not depend on the configuration as it stands now

#### Scenario: Priority resolves a shorter lead time
@e2e exclude asserted in tests/Unit/Service/Term/TermResolutionTest.php::testPriorityResolvesAShorterLeadTime

- **GIVEN** a service declaring a shorter term for urgent cases
- **WHEN** a case derives the priority urgent through REQ-PRI-02
- **THEN** the shorter term SHALL be resolved
- **AND** no second priority field SHALL be read

### Requirement: A term declares the statuses its clock runs in, with thresholds as days or shares (REQ-TCF-03)

A term SHALL be able to declare the statuses in which its clock runs.
Entering a status outside that set SHALL suspend the engine timer and leaving
it SHALL resume, through the platform's own suspend and resume. An
escalation threshold SHALL be expressible as a number of days or as a share
of the term, and a share SHALL be resolved to a date when the timer is armed.

#### Scenario: The clock stops while the case sits with an adviser
@e2e tests/e2e/term-configuration-beyond-the-case-type.spec.ts

- **GIVEN** a term declaring that its clock runs only in the handling statuses
- **WHEN** the case moves to a status outside that set for ten working days
- **THEN** the timer SHALL be suspended for those ten days
- **AND** the end date SHALL move by the same amount on resuming

#### Scenario: A quarter of the term means two different dates
@e2e exclude asserted in tests/Unit/Service/Term/ThresholdSharesTest.php::testAQuarterOfTwoTermsIsTwoDifferentOffsets

- **GIVEN** a threshold declared at 25 per cent of the term
- **WHEN** it is armed on a six-week term and on a twenty-six-week term
- **THEN** the two dates SHALL differ
- **AND** both SHALL be a quarter of their own term

#### Scenario: An extension re-resolves the shares
@e2e exclude asserted in tests/Unit/Service/Term/ThresholdSharesTest.php::testAnExtendedTermMovesTheShareRung

- **GIVEN** a term with a threshold declared as a share
- **WHEN** the term is extended under Awb 4:14
- **THEN** the timer SHALL be re-armed
- **AND** the threshold date SHALL be recomputed from the new term

#### Scenario: A ladder may mix days and shares
@e2e exclude asserted in tests/Unit/Service/Term/ThresholdSharesTest.php::testALadderMixesDaysAndShares

- **GIVEN** a ladder with a threshold at 50 per cent and one at two days before the end
- **WHEN** the timer is armed
- **THEN** both rungs SHALL be armed on the engine ladder
- **AND** no second escalation mechanism SHALL be introduced
