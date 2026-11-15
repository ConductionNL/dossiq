## ADDED Requirements

### Requirement: Dwell and processing time count working hours, and say so [V1]

`DwellTimeAnalyzer` SHALL report each interval as working hours on the
organisation calendar and as wall-clock hours. The Process mining and
Processing time pages SHALL show working hours as the headline column,
wall-clock hours beside it, and SHALL name the clock in each column header.
Dwell SHALL be reported per phase and per assignee.

#### Scenario: A weekend is not working time
@e2e exclude covered by ElapsedBusinessHoursTest (openregister) and DwellOnTheWorkingCalendarTest: Friday 16:00 to Monday 09:00 on the seeded nl-national calendar

- **GIVEN** a phase entered Friday 16:00 and left Monday 09:00
- **WHEN** dwell is computed on the organisation calendar
- **THEN** working hours SHALL be 1 and wall-clock hours SHALL be 65

#### Scenario: The page says which clock it counted on
@e2e tests/e2e/dwell-time-clock.spec.ts

- **GIVEN** an instance without the engine calendar
- **WHEN** the report is drawn
- **THEN** the working-hours column SHALL be titled so that it names the
  approximation, and SHALL NOT be titled Working hours

#### Scenario: The page names the clock
@e2e tests/e2e/dwell-time-clock.spec.ts

- **GIVEN** the Process mining page
- **WHEN** you read the dwell table
- **THEN** the headline column SHALL be titled Working hours
- **AND** a second column SHALL be titled Wall-clock hours

#### Scenario: Per assignee
@e2e tests/e2e/dwell-time-clock.spec.ts

- **GIVEN** status records by two handlers
- **WHEN** you read the dwell table by handler
- **THEN** both handlers SHALL be listed with their working hours
- **AND** time no status record attributed SHALL be listed under its own row
  rather than dropped
