## MODIFIED Requirements

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

## ADDED Requirements

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
@e2e exclude a manifest rule; the rule values are asserted in tests/vitest/manifestDueRules.spec.js

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

## REMOVED Requirements

### Requirement: Termijn-binding per zaaktype (REQ-TERM-001)

**Reason**: Blocking zaak-creation when no TermijnDefinitie exists was never built (the code has only warned) and would lose real applications for every case type an administrator has not configured yet. Replaced by "A term is bound per zaaktype, and a zaaktype without one still creates the case (REQ-TERM-001)" in this change, which keeps the binding and the versioning rules and gives such a case the fallback deadline of REQ-OTE-01.

**Migration**: None. Behaviour on development already matches the replacement.
