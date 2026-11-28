---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-binding Specification

## Purpose
Binds every zaak to a matching TermijnDefinitie and auto-creates its TermijnInstance on case creation, calculating the legal deadline from the configured duration and recording a start event. Case creation is blocked when no definition exists for the zaaktype, and definition versioning never retroactively changes deadlines on existing instances.

## Requirements

### Requirement: A term ends on a day, not at a moment (REQ-TERM-DAY-001)

A term's end SHALL be stored as a calendar date in `Y-m-d` form, counted and
rolled in one call (`lib/Service/TermijnService.php`, `endDateFor()` and the
`format('Y-m-d')` that follows it). When the end falls on a Saturday, a Sunday
or a public holiday it SHALL move to the first ordinary day after it, on the
working calendar the organisation administers in OpenRegister
(`lib/Service/Termijn/TermEndRoll.php`, Algemene termijnenwet art. 1), unless
the definition switches the roll off. The terms on a case SHALL be judged in
whole days from midnight today (`lib/Service/CaseTermsService.php`,
`daysLeft()`), so a term SHALL count as overdue only from the day after its end
day, and only while its clock still runs (`lopend`, `verlengd`, `paused`,
`exceeded`). The result SHALL be shown in the Terms tab of the case
(`CaseTermsTab`) and on the Deadline tile.

#### Scenario: A term is not overdue on its last day
@e2e exclude a date comparison with an injected clock; covered by the term unit tests under tests/Unit/Service/Termijn

- **GIVEN** a running term whose end date is today
- **WHEN** the terms of its case are read at 23:00
- **THEN** the term SHALL have 0 days left
- **AND** it SHALL NOT be overdue

#### Scenario: A term is overdue the day after its end
@e2e exclude a date comparison with an injected clock; covered by the term unit tests under tests/Unit/Service/Termijn

- **GIVEN** a running term whose end date was yesterday
- **WHEN** the terms of its case are read
- **THEN** the term SHALL have -1 days left
- **AND** it SHALL be overdue

#### Scenario: A term ending on a Saturday ends on Monday
@e2e exclude a calendar roll over the working calendar; covered by tests/Unit/Service/Termijn/WorkingDayRollTest.php

- **GIVEN** a term definition that leaves the Awt roll on, and a count that lands on a Saturday
- **WHEN** the term is created
- **THEN** its end date SHALL be the Monday after, or the next ordinary day when that Monday is a public holiday

#### Scenario: A completed term is never overdue
@e2e exclude a status branch with an injected clock; covered by the term unit tests under tests/Unit/Service/Termijn

- **GIVEN** a term with status `completed` whose end date passed a week ago
- **WHEN** the terms of its case are read
- **THEN** the term SHALL NOT be overdue

### Requirement: Every clock on a case is a term instance with a kind (REQ-TERM-060)

Every deadline on a case SHALL be a term instance carrying a kind of
`statutory`, `planned`, `internal` or `phase`. Every instance SHALL be
bound to the administered working calendar and SHALL be computed by the
shared calculator. The case's `deadline` field SHALL NOT be an independent
date: while the case has a statutory instance it SHALL be a copy of that
instance's current end date (REQ-OTE-01), and only a case without one SHALL
carry the fallback. A term instance whose calendar cannot be resolved SHALL
refuse to bind and SHALL NOT fall back to calendar days.

#### Scenario: four clocks on one case are four instances
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case with a statutory term, a planned end, an internal target and a phase term
- **WHEN** its terms are read
- **THEN** four term instances SHALL be returned
- **AND** each SHALL name its kind

#### Scenario: a missing calendar refuses rather than guesses
@e2e exclude a refusal inside the bind with no screen; covered by tests/Unit/Service/Termijn/WorkingDayRollTest.php

- **GIVEN** a case type naming a calendar that does not resolve
- **WHEN** a term is bound
- **THEN** it SHALL refuse
- **AND** the refusal SHALL name the calendar

### Requirement: A phase carries its own term (REQ-TERM-061)

A `statusType` SHALL declare a term in days. Entering that phase SHALL
start a term instance of kind `phase`; leaving it SHALL stop that instance.
A phase over its term SHALL be visible on the case and in the working list
before the case term expires. A phase term SHALL NOT move the case term.

#### Scenario: an ontvankelijkheidstoets has two weeks inside eight
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type whose ontvankelijkheidstoets phase declares 14 days
- **WHEN** a case enters that phase
- **THEN** a phase term SHALL start with an end 14 working days later

#### Scenario: an overrunning phase is visible before the case is
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case 20 days into a 14 day phase, with 40 days of case term left
- **WHEN** a handler reads the case
- **THEN** the phase SHALL read overdue
- **AND** the case term SHALL read on time

#### Scenario: a phase never extends the case term

- **GIVEN** a case whose phase term runs past the case term
- **WHEN** the phase ends
- **THEN** the case term end SHALL be unchanged

### Requirement: A planned end and a planned start sit beside the statutory term (REQ-TERM-062)

A case SHALL carry a planned end date as a term instance of kind `planned`,
beside its statutory term, and MAY carry a planned start. The two SHALL be
warned on separately, reported separately and displayed distinctly. A case
late against its plan and on time against its statutory term SHALL read as
both.

#### Scenario: late against the plan, on time against the law
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case past its planned end and inside its statutory term
- **WHEN** a handler reads it
- **THEN** the planned end SHALL read overdue
- **AND** the statutory term SHALL read on time

#### Scenario: the two warnings are separate
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type warning at 5 days on the plan and 10 on the statutory term
- **WHEN** each threshold is passed
- **THEN** each SHALL raise its own warning

#### Scenario: work is scheduled from a planned start

- **GIVEN** a case carrying a planned start in two weeks
- **WHEN** the working list is read
- **THEN** the case SHALL carry that start

### Requirement: An internal target is clocked apart and never shown to the citizen (REQ-TERM-063)

A case type SHALL be able to declare an internal target in days. It SHALL
be a term instance of kind `internal`, clocked separately from the
statutory term, reported separately, and SHALL NOT appear on any
citizen-facing surface or in any message to a citizen.

#### Scenario: a teamleider steers on a number the citizen never sees
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type with a statutory term of 56 days and an internal target of 30
- **WHEN** a case is created
- **THEN** both terms SHALL run
- **AND** the internal target SHALL NOT be in the portal view of that case

#### Scenario: the internal target is not quoted in a message

- **GIVEN** a case with an internal target
- **WHEN** an automatic message to the applicant is rendered
- **THEN** it SHALL NOT name the internal target

### Requirement: A case type's term is a lead time or a fixed date (REQ-TERM-064)

`caseType.processingDeadline` SHALL take either a lead time in days or a
fixed calendar date. Everything downstream SHALL read the bound end date
and SHALL NOT depend on which form produced it. A fixed date already past
at creation SHALL bind an expired term visibly and SHALL NOT refuse the
case.

#### Scenario: a subsidy round closes on a date
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type whose term is the fixed date 1 March
- **WHEN** cases are created in January and in February
- **THEN** both SHALL carry an end of 1 March

#### Scenario: an application to a closed round is still a case
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case type whose fixed date has passed
- **WHEN** a case is created
- **THEN** it SHALL be created
- **AND** its term SHALL read expired

### Requirement: A chain term is declared once and split over its steps (REQ-TERM-065)

A chain of cases or steps SHALL be able to carry one term, split over its
steps as declared shares. When a step ends early or late, the remaining
shares SHALL be recomputed. The chain's own end SHALL NOT move. A chain
whose steps have consumed it SHALL report zero days remaining rather than
moving the end.

#### Scenario: one deadline over four steps
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a chain of four steps carrying a term of 40 working days
- **WHEN** the chain starts
- **THEN** each step SHALL carry its declared share

#### Scenario: a step that overran shrinks the ones after it
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a chain whose first step took 5 days more than its share
- **WHEN** the second step starts
- **THEN** the remaining shares SHALL be recomputed
- **AND** the chain end SHALL be unchanged

#### Scenario: an exhausted chain says so

- **GIVEN** a chain whose steps have consumed the whole term
- **WHEN** the next step starts
- **THEN** it SHALL report zero days remaining

### Requirement: A service norm never arms the statutory term (REQ-TERM-065)

The statutory term SHALL be started only from a definition of kind `statutory` (an
absent kind reads as statutory). A case type whose only definitions are of another kind
SHALL start no statutory term.

#### Scenario: A case type with only a planned definition gets no statutory term
@e2e exclude engine-internal selection; proven by tests/Unit/Service/Termijn/TermDefinitionsKindTest.php

- **GIVEN** a case type whose only active definition has `kind: planned`
- **WHEN** the statutory definition for it is asked for
- **THEN** none SHALL be answered

### Requirement: A planned definition binds the planned clock (REQ-TERM-066)

When a case is created and its case type declares no `plannedLeadTime`, the planned
clock SHALL be bound from the `planned` definition that resolves for the case, for its
`standardDurationDays`, counted in the mode the definition declares. A case type that
declares its own `plannedLeadTime` SHALL keep it.

#### Scenario: A case of an English demo type gets a planned end and no statutory term
@e2e exclude bound on the case-created event; proven by tests/Unit/Service/CaseTermsServicePlannedDefinitionTest.php and tests/Unit/Listener/DeadlineCaseCreatedListenerPlannedTest.php

- **GIVEN** a case type with no `plannedLeadTime` and a `planned` definition of 56 calendar days
- **WHEN** a case of that type is created
- **THEN** a term instance of kind `planned` SHALL be bound ending 56 calendar days after the start, rolled off a closed day

#### Scenario: The case type's own planned lead time wins
@e2e exclude engine-internal precedence; proven by tests/Unit/Service/CaseTermsServicePlannedDefinitionTest.php

- **GIVEN** a case type declaring `plannedLeadTime` of 10 days and a `planned` definition of 56 days
- **WHEN** a case of that type is created
- **THEN** the planned clock SHALL run 10 working days

### Requirement: A term written with nobody signed in is written as the system (REQ-TERM-SYS-01)

A term instance written while nobody is signed in (an install, an example set, a cron job) MUST be written as the system, so the case gets its term. A term written by a signed-in user MUST be written with that user's rights and never elevated.

#### Scenario: The example set's cases get their terms
@e2e exclude {asserted in tests/Unit/Service/Termijn/TermInstanceStoreSystemWriteTest.php with an object service that refuses an anonymous create}

- **GIVEN** the example set install creating cases from occ, with no user
- **WHEN** a case binds its statutory term
- **THEN** the term instance MUST be saved
- **AND** nothing MUST log "TermInstanceStore persist failed"

### Requirement: The calculated deadline is the fallback for a case without a term

The case `deadline` calculation SHALL be declared a fallback, so a deadline its statutory term keeps is not reported as a calculation that failed to materialise.

#### Scenario: rematerialising a case with a term
- **GIVEN** a case whose statutory term ends 29 okt while startDate plus the term gives 29 jun
- **WHEN** the case calculations are rematerialised
- **THEN** the case keeps 29 okt and counts as touched

### Requirement: A term is bound per zaaktype, and a zaaktype without one still creates the case (REQ-TERM-001)

The system SHALL auto-create a statutory `TermijnInstance` on zaak-creation
for every zaaktype that has an active `TermijnDefinitie` or declares a fixed
closing date. A zaaktype with neither SHALL NOT block zaak-creation: the case
SHALL be created, the system SHALL log a warning naming the zaaktype, and the
case SHALL carry the fallback deadline of REQ-OTE-01 instead of a statutory
term.

#### Scenario: A zaaktype without a TermijnDefinitie still creates the case
@e2e exclude a listener branch on a missing definition with no screen of its own; covered by tests/Unit/Listener/DeadlineCaseCreatedListenerTest.php

- **GIVEN** a gemeente has configured TermijnDefinities for "Omgevingsvergunning-regulier" and "Wmo-aanvraag" but NOT for "Horeca-exploitatievergunning"
- **WHEN** a new "Horeca-exploitatievergunning" zaak is created
- **THEN** the zaak SHALL be created
- **AND** no statutory `TermijnInstance` SHALL be bound to it
- **AND** a warning SHALL be logged naming the zaaktype
- **AND** the case `deadline` SHALL be the fallback of REQ-OTE-01

#### Scenario: Auto-create TermijnInstance on zaak-creation
@e2e exclude the bind runs in a create listener; covered by tests/Unit/Listener/DeadlineCaseCreatedListenerTest.php

- **GIVEN** a zaak of type "Omgevingsvergunning-regulier" (56 days) is registered
- **WHEN** the `TermijnInstance` is auto-created
- **THEN** its `startDate` SHALL be the case's `termStartsAt` (REQ-OTE-02)
- **AND** `endDateCalculated` SHALL be that start plus 56 days, rolled per REQ-TERM-DAY-001
- **AND** `status` SHALL be `lopend`
- **AND** a `TermijnGebeurtenis` of type `start` SHALL be recorded with the term's start as its moment and "AWB 4:13" as its basis

#### Scenario: TermijnDefinitie versioning does not affect existing instances
@e2e exclude a versioning rule over stored rows; covered by the TermijnService unit tests

- **GIVEN** a `TermijnDefinitie` is updated (e.g. duration 56 → 70 days)
- **WHEN** the change takes effect
- **THEN** new cases created after the change SHALL use the new duration
- **AND** existing `TermijnInstance` rows SHALL retain their original `endDateCalculated` with no retroactive change

### Requirement: The case deadline is the statutory term's current end (REQ-OTE-01)

While a case has a statutory term instance, its `deadline` SHALL equal the
`endDateCurrent` of its newest statutory instance that is not `completed`, or
of its newest statutory instance when all are completed. Creating, pausing,
resuming, extending, re-binding and rolling that instance SHALL write the new
date onto the case in the same operation. Every later save of the case SHALL
keep that date, whatever the payload or the OpenRegister calculation says.
Planned, internal and phase instances SHALL NOT write `deadline`. A case
without a statutory instance SHALL keep the calculated fallback: its start date
plus the case type's own or inherited `processingDeadline`.

#### Scenario: A pause moves the deadline on the list
@e2e exclude the write happens in TermijnService behind the pause route; covered by tests/Unit/Service/Termijn/CaseDeadlineMirrorTest.php and tests/Unit/Service/TermijnServiceTest.php

- **GIVEN** a case whose statutory term ends on 2026-11-02
- **WHEN** the term is paused for 10 days
- **THEN** the instance's `endDateCurrent` SHALL be 2026-11-12
- **AND** the case `deadline` SHALL be 2026-11-12

#### Scenario: A later save does not bring the calculated date back
@e2e exclude a pre-persist listener with no screen; covered by tests/Unit/Listener/CaseDeadlineFollowsTermListenerTest.php

- **GIVEN** a case whose statutory term was extended to 2026-11-16
- **WHEN** a handler edits the case title and saves
- **THEN** the case `deadline` SHALL still be 2026-11-16

#### Scenario: The list and the case page agree
@e2e exclude the two surfaces read the same stored field; the agreement is asserted on the server in tests/Unit/Listener/CaseDeadlineFollowsTermListenerTest.php

- **GIVEN** a case with a statutory term
- **WHEN** the case list and the case page's Terms tab are read
- **THEN** the list's deadline SHALL be the statutory term's end date in the Terms tab

#### Scenario: A case type without a term keeps the fallback
@e2e exclude no statutory instance means no write; covered by tests/Unit/Listener/CaseDeadlineFollowsTermListenerTest.php

- **GIVEN** a case whose case type has no term definition and no fixed closing date
- **WHEN** the case is saved
- **THEN** its `deadline` SHALL be the calculated start date plus `processingDeadline`

### Requirement: The statutory term counts from receipt (REQ-OTE-02)

The statutory instance, the planned end and the internal target of a new case
SHALL start at the case's `termStartsAt`: the first working moment on or after
`receivedAt`, on the same calendar the term counts on (REQ-TERM-040). When the
stamp is not yet on the case, the same calendar call SHALL be made from the
arrival moment (`receivedAt`, else `registrationDate`, else `requestedDate`,
else the creation moment). When no calendar answers, the term SHALL start at
the arrival moment itself. The term SHALL NOT start at the moment the case row
was written when an earlier arrival is known.

#### Scenario: A request received on Sunday starts on Monday
@e2e exclude the start is chosen in a create listener; covered by tests/Unit/Listener/DeadlineCaseCreatedListenerTest.php

- **GIVEN** a working calendar whose week runs Monday to Friday
- **AND** a request received on Sunday 2026-10-04 at 20:00 and registered on Tuesday 2026-10-06
- **WHEN** the case is created
- **THEN** the statutory instance's `startDate` SHALL be Monday 2026-10-05, start of the working day
- **AND** its end date SHALL be counted from that Monday

#### Scenario: A back-dated receipt counts from the receipt
@e2e exclude as above

- **GIVEN** a case registered today with `receivedAt` ten days ago, a working day
- **WHEN** the case is created
- **THEN** the statutory instance SHALL start ten days ago

### Requirement: A term is late only from the day after its last day, everywhere (REQ-OTE-05)

The rule of REQ-TERM-DAY-001 SHALL hold on every surface: the term instance's
status, the engine timer's breach, the case list column, the board card, the
week strip and the My Work card. On its last day a term SHALL read as due
today, never as late. The beslistermijn timer SHALL be anchored at the start of
the day after the term's start day, where the Algemene termijnenwet counts a
term from, with the term's own length as its SLA, so it breaches at the start of
the day after the end day.

#### Scenario: The timer does not mark a term exceeded on its last day
@e2e exclude an engine timer configuration; covered by tests/Unit/Service/TermijnTimerServiceTest.php

- **GIVEN** a statutory term starting 2026-10-01 at 14:00 and ending 2026-10-29
- **WHEN** its beslistermijn timer is armed
- **THEN** the timer SHALL be anchored at 2026-10-02 00:00 with an SLA of 28 calendar days
- **AND** it SHALL breach at 2026-10-30 00:00, the day after the end day

#### Scenario: The last day is not red in the list, on the board or in the week strip
@e2e exclude a manifest rule; the rule values are asserted in tests/vitest/simpleListAndDashboard.spec.js

- **GIVEN** a case whose deadline is today
- **WHEN** it is shown in the simple structure's case list, on the workflow board and in the week strip
- **THEN** it SHALL NOT carry the late (error) variant

### Requirement: Existing cases are repaired once (REQ-OTE-08)

On upgrade a repair step SHALL write every case's `deadline` from its
statutory instance (REQ-OTE-01) and SHALL re-arm, once, the beslistermijn timer
of every running statutory instance armed before REQ-OTE-05, marking the
instance `timerBreachesAfterLastDay`. It SHALL be idempotent, SHALL report how
many cases it followed, left unchanged, re-armed and failed, and SHALL NOT fail
the upgrade.

#### Scenario: A case whose list date disagreed is repaired
@e2e exclude a repair step with no screen; covered by tests/Unit/Repair/ReconcileCaseDeadlinesWithTermsTest.php

- **GIVEN** a case whose `deadline` reads 2026-11-02 and whose extended statutory instance reads 2026-11-16
- **WHEN** the repair step runs
- **THEN** the case `deadline` SHALL be 2026-11-16
- **AND** a second run SHALL report it unchanged

