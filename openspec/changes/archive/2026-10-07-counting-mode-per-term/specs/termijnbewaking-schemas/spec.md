## ADDED Requirements

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
