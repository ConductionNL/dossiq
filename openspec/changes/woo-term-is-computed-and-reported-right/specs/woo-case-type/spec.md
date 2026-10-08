## ADDED Requirements

### Requirement: The case deadline is the term engine's rolled date (REQ-WTR-001)

A case's `deadline` SHALL be its start date plus the case type's `processingDeadline`, rolled to
the next working day on the organisation's calendar by `TermijnTimerService::rollTermEndFor()`,
as the Algemene termijnenwet art. 1 requires. It SHALL be rolled unless the term definition sets
`rollToWorkingDay` to false. The materialised OpenRegister calculation that adds the duration
without rolling SHALL NOT be the source of the stored `deadline`. Where the case has a statutory
term instance, the case `deadline` SHALL equal that instance's `endDateCurrent` after every change
to it. The unrolled date SHALL be stored as `deadlineBeforeRoll`.

#### Scenario: A Woo term that ends on Christmas Day rolls past the weekend
- **GIVEN** the Woo request case type with `processingDeadline` P28D and an organisation calendar that marks 2026-12-25 and 2026-12-26 as holidays
- **WHEN** a Woo request is started on Friday 2026-11-27, a working day
- **THEN** the case `deadline` SHALL be Monday 2026-12-28
- **AND** `deadlineBeforeRoll` SHALL be Friday 2026-12-25

#### Scenario: A Woo term that ends on King's Day rolls past it
- **GIVEN** the organisation calendar marks 2027-04-27 as a holiday
- **WHEN** a Woo request is started on 2027-03-30
- **THEN** the case `deadline` SHALL be 2027-04-28

#### Scenario: The case and its term agree
- **GIVEN** a Woo case with a running statutory term instance
- **WHEN** the term is suspended for five days and resumed
- **THEN** the case `deadline` SHALL equal the term instance's `endDateCurrent`

### Requirement: An end date accepted through the API is rolled (REQ-WTR-002)

`DeadlineExtensionService` SHALL roll a caller-supplied `newEinddatum` with `rollTermEndFor()`,
using the term instance's definition, before it stores `endDateCurrent`. It SHALL store the
supplied date as `endDateBeforeRoll` on the term instance. The ceiling check and the days-impact
SHALL use the rolled date.

#### Scenario: A Sunday sent to termijn#verleng lands on Monday
- **GIVEN** a running term instance with `endDateCurrent` 2026-11-02
- **WHEN** POST `/api/termijn/instances/{id}/verleng` is called with `newEinddatum` 2026-11-15, a Sunday, and a rationale
- **THEN** the instance's `endDateCurrent` SHALL be 2026-11-16 and `endDateBeforeRoll` SHALL be 2026-11-15

### Requirement: A Woo extension goes through the term engine, once (REQ-WTR-003)

Woo art. 4.4 lid 2 allows one extension of at most two weeks. POST
`/api/cases/{id}/woo/extend-deadline` SHALL call `DeadlineExtensionService::requestExtension()` on
the case's Woo term instance. The new end date SHALL be the current `endDateCurrent` plus the case
type's `extensionPeriod` (P14D), rolled. A second extension SHALL be refused by the term
definition's ceiling, and the refusal SHALL reach the caller as HTTP 409 with the Awb sentence.
The route SHALL NOT write `expectedResolution`, `deadlineVerlengd` or `verdagingReden` on the case.
The reason SHALL be recorded as the `verleng` event's rationale.

#### Scenario: One extension, rolled
- **GIVEN** a Woo case whose term instance has `endDateCurrent` 2026-11-02 and `countExtensions` 0
- **WHEN** the handler extends with the reason "Zienswijzen van derden"
- **THEN** `endDateCurrent` SHALL be 2026-11-16, `countExtensions` SHALL be 1 and the case `deadline` SHALL be 2026-11-16
- **AND** the term's event list SHALL hold a `verleng` event with that rationale

#### Scenario: A second extension is refused
- **GIVEN** the same case after one extension
- **WHEN** the handler extends again
- **THEN** the response SHALL be 409 and name the one-extension rule
- **AND** `endDateCurrent` SHALL be unchanged

#### Scenario: The cap holds when the case schema drops undeclared keys
- **GIVEN** an OpenRegister that discards properties the case schema does not declare
- **WHEN** a Woo case is extended twice
- **THEN** the second extension SHALL be refused, because the cap is read from the term instance
