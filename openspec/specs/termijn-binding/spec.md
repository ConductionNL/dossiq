---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-binding Specification

## Purpose
Binds every zaak to a matching TermijnDefinitie and auto-creates its TermijnInstance on case creation, calculating the legal deadline from the configured duration and recording a start event. Case creation is blocked when no definition exists for the zaaktype, and definition versioning never retroactively changes deadlines on existing instances.

## Requirements

### Requirement: Termijn-binding per zaaktype (REQ-TERM-001)

The system SHALL require every zaak to have a matching `TermijnDefinitie` and SHALL auto-create a `TermijnInstance` on zaak-creation; explicit configuration SHALL prevent silent deadline-handling failures.

#### Scenario: Zaak-creation blocked when no TermijnDefinitie exists

- **GIVEN** a gemeente has configured TermijnDefinities for "Omgevingsvergunning-regulier" and "Wmo-aanvraag" but NOT for "Horeca-exploitatievergunning"
- **WHEN** a new "Horeca-exploitatievergunning" zaak is created
- **THEN** the system SHALL block zaak-creation with an admin-facing error directing the administrator to configure a `TermijnDefinitie` before creating cases of this type

#### Scenario: Auto-create TermijnInstance on zaak-creation

- **GIVEN** a zaak of type "Omgevingsvergunning-regulier" (56 days) is registered
- **WHEN** the `TermijnInstance` is auto-created
- **THEN** `einddatumBerekend` SHALL be set to `startDatum + standaardDuurDagen`
- **AND** `status` SHALL be `lopend`
- **AND** a `TermijnGebeurtenis` of type `start` SHALL be recorded with `tijdstip` = zaak-creation time and `grondslag` = "AWB 4:13"

#### Scenario: TermijnDefinitie versioning does not affect existing instances

- **GIVEN** a `TermijnDefinitie` is updated (e.g. duration 56 → 70 days)
- **WHEN** the change takes effect
- **THEN** new cases created after the change SHALL use the new duration
- **AND** existing `TermijnInstance` rows SHALL retain their original `einddatumBerekend` with no retroactive change
- **AND** if the `TermijnDefinitie` is marked `validUntil = today`, new cases of that zaaktype SHALL NOT be created while existing ones continue

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
shared calculator. dossiq SHALL NOT hold a deadline as a bare date field
on the case. A term instance whose calendar cannot be resolved SHALL
refuse to bind and SHALL NOT fall back to calendar days.

#### Scenario: four clocks on one case are four instances
@e2e tests/e2e/phase-terms-and-the-internal-target.spec.ts

- **GIVEN** a case with a statutory term, a planned end, an internal target and a phase term
- **WHEN** its terms are read
- **THEN** four term instances SHALL be returned
- **AND** each SHALL name its kind

#### Scenario: a missing calendar refuses rather than guesses

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
