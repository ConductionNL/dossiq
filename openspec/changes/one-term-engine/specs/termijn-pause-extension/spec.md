## ADDED Requirements

### Requirement: An extension's end date is rolled and reaches the case (REQ-OTE-03)

`DeadlineExtensionService` SHALL roll a requested end date with the
Algemene termijnenwet roll (`rollTermEndFor()`, with the instance's
definition) before it checks the declared extension period and before it
stores `endDateCurrent`. It SHALL keep the requested date as
`endDateBeforeRoll` on the instance. The days impact SHALL be counted to the
rolled date. The rolled date SHALL reach the case's `deadline` (REQ-OTE-01), as
the new end of a pause and of a resume does.

#### Scenario: A Sunday sent to termijn#verleng lands on Monday
@e2e exclude a date roll behind the verleng route; covered by tests/Unit/Service/DeadlineExtensionLimitTest.php

- **GIVEN** a running term instance with `endDateCurrent` 2026-11-02
- **WHEN** POST `/api/termijn/instances/{id}/verleng` is called with `newEinddatum` 2026-11-15, a Sunday, and a rationale
- **THEN** the instance's `endDateCurrent` SHALL be 2026-11-16
- **AND** `endDateBeforeRoll` SHALL be 2026-11-15
- **AND** the case `deadline` SHALL be 2026-11-16
