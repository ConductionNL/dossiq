## ADDED Requirements

### Requirement: Time in a status is held on the case, sortable and filterable (REQ-SDW-01)

The case SHALL hold the time spent in its current status and the total time
spent in each status it has visited, written as the status changes and
counted on the organisation's working calendar. The work list SHALL sort and
filter on those numbers. The process mining page SHALL read the same numbers
rather than computing a second set.

#### Scenario: A handler sorts the work list by time in status
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** a queue of cases in the same status for different lengths of time
- **WHEN** the handler sorts by time in the current status
- **THEN** the longest-standing case SHALL be first

#### Scenario: The filter finds cases over a threshold
@e2e tests/e2e/what-a-status-declares.spec.ts

- **GIVEN** four cases more than twenty working days in their current status
- **WHEN** the work list is filtered on that threshold
- **THEN** exactly those four SHALL be returned

#### Scenario: The page and the list agree
@e2e exclude unit; DwellTimeAnalyzerTest

- **GIVEN** a case with three status visits
- **WHEN** the process mining page and the case are read
- **THEN** the per-status totals SHALL be identical
- **AND** both SHALL be counted on the working calendar
