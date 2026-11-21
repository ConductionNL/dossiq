## MODIFIED Requirements

### Requirement: Card Display [MVP]

Each case card MUST present the case in human-readable form, implemented in
`src/views/MyWorkCaseCard.vue`. Whether the deadline is past MUST be decided by
the shared front-end helper (REQ-OTE-07), in whole local days, so a case is not
overdue on its last day.

@e2e exclude Requires an assigned case with a case type + status; card field
rendering is data-dependent.

#### Scenario: Card fields
@e2e exclude card field rendering is data-dependent; the overdue rule is the shared helper, covered by tests/vitest/deadlineCountdown.spec.js

- GIVEN an assigned case with a caseType and a status
- THEN the card MUST display:
  - The case title
  - A truncated description (when present)
  - The identifier (e.g. "2026-0118")
  - The **case-type name** (not its raw UUID) resolved from the caseType map
  - The **status name** (not its raw UUID) resolved from the statusType map
  - The deadline date when set
- AND a case whose deadline was yesterday or earlier MUST show the deadline in
  an error colour (overdue), not relying on colour alone (the "Deadline:" label
  remains)
- AND a case whose deadline is today MUST NOT show the overdue colour

#### Scenario: Case-type / status name resolution
@e2e exclude unchanged by this change; name resolution is a component prop wiring

- GIVEN card view does not apply column formatters
- WHEN My Work renders its cards
- THEN the parent index MUST load the `caseType` and `statusType` collections
  once and pass UUID→name maps to each card so names render, never raw UUIDs

## ADDED Requirements

### Requirement: The urgency score reads every open term (REQ-OTE-06)

The work queue's urgency score SHALL take a case's deadline from the nearest
end date among its term instances that are `lopend`, `verlengd`, `paused` or
`exceeded`, so a paused or extended term counts with the end date it has
now. Only when the case has none SHALL it read the case's `deadline`.

#### Scenario: A paused term keeps its case in the queue
@e2e exclude a scoring query; covered by tests/Unit/Service/WorkQueueServiceTest.php

- **GIVEN** a case whose only statutory term is `paused`, ending in 4 working days
- **WHEN** the work queue is computed
- **THEN** the case's days until the deadline SHALL be 4, read from that term

### Requirement: One front-end helper decides days left and overdue (REQ-OTE-07)

Every surface that shows days left or overdue for a case or a term SHALL get
both from `src/utils/deadlineCountdown.js`: whole days between the reader's
local today and the deadline's day, overdue only when that is below zero. No
component SHALL subtract dates for this itself.

#### Scenario: Every surface agrees on the last day
@e2e exclude a pure function; covered by tests/vitest/deadlineCountdown.spec.js

- **GIVEN** a deadline of today
- **WHEN** the case list, the case helpers, the terms panel, the dashboard and the Woo panel ask for days left
- **THEN** each SHALL get 0 and not overdue
