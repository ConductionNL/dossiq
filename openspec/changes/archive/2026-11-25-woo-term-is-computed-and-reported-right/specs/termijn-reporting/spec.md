## ADDED Requirements

### Requirement: The quarterly term report groups by the case type the term belongs to (REQ-WTR-004)

`DeadlineReportingService::generateQuarterlyReport()` SHALL resolve each term instance's case type
from the case it names (`deadlineInstance.case` to `case.caseType`). When that fails, it SHALL use
the case type of the instance's `deadlineDefinition`. Each `perType` key SHALL be the case type's
identifier, with its title beside it. A term whose case type cannot be resolved either way SHALL
be counted under `unresolved`, and the report SHALL list those instance ids in
`metadata.unresolvedInstances`. No term SHALL be counted under `unknown`.

#### Scenario: Woo terms are reported under the Woo case type
- **GIVEN** three Woo cases and two omgevingsvergunning cases, each with a term instance in 2026 Q4
- **WHEN** GET `/api/termijn/reports/kwartaal?period=2026-Q4` is called
- **THEN** `perType` SHALL hold the Woo request case type with `totaal` 3 and the omgevingsvergunning type with `totaal` 2
- **AND** `perType` SHALL have no key `unknown`

#### Scenario: A term without a resolvable case is visible, not hidden
- **GIVEN** a term instance whose case was deleted and whose definition names no case type
- **WHEN** the quarterly report is generated
- **THEN** it SHALL be counted under `unresolved` and its id SHALL be listed in `metadata.unresolvedInstances`

### Requirement: The report says how many requests arrived and how many terms were met (REQ-WTR-005)

For each case type, the quarterly report SHALL add these counts to the existing keys:
`received` (terms whose `startDate` falls in the quarter), `met` (completed on or before
`endDateCurrent`), `missed` (status `exceeded`, or completed after `endDateCurrent`), `running`
and `suspended`, and `metShare` (met over met plus missed, as a percentage with one decimal). The
existing keys SHALL keep their meaning, because the dashboard reads them.

#### Scenario: Met, missed, running and suspended for Woo
- **GIVEN** in 2026 Q4 four Woo terms: one completed on time, one completed two days late, one running and one suspended for clarification
- **WHEN** the quarterly report is generated for 2026-Q4
- **THEN** the Woo case type SHALL report `received` 4, `met` 1, `missed` 1, `running` 1, `suspended` 1 and `metShare` 50.0
