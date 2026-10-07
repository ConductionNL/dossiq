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
