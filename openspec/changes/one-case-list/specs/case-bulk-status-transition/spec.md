## ADDED Requirements

### Requirement: Bulk actions on the case index

You select cases and move, suspend, resume or extend them in one go. The
`Cases` page `bulkActions` SHALL hold, next to Reassign: Transition
(handler `transitionSelection`), Suspend (`suspendSelection`), Resume
(`resumeSelection`) and Extend term (`extendTermSelection`). Each handler
SHALL open `BulkTransitionDialog` for the selected case ids in the matching
mode with a reason field, and the dialog SHALL refuse to execute while the
reason is empty. Transition SHALL list the transitions available to the
selection and post through the bulk-transition endpoints the workflow board
uses, so the engine, its guards and its automatic actions run for every
case. Suspend SHALL register a pause on each case's running term through
`DeadlinePauseService` with the reason; Resume SHALL end that pause; Extend
term SHALL take a new deadline and write it with the reason on the case's
status record. Every mode SHALL preview per case before executing and SHALL
report per case which succeeded and which did not, the way the board's
dialog does today; a partial failure SHALL never read as success.

#### Scenario: Transition moves the selection with a reason
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** two open cases of one case type selected on the Cases page
- **WHEN** you choose Transition, pick a transition, enter a reason and execute
- **THEN** the dialog SHALL report 2 of 2 cases transitioned
- **AND** each case SHALL show the new status on its page

#### Scenario: No reason, no execute
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** one open case selected and the Suspend dialog open
- **WHEN** the reason field is empty
- **THEN** the Execute button SHALL be disabled

#### Scenario: Suspend and resume through the term
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** one open case with a running term selected on the Cases page
- **WHEN** you choose Suspend, enter a reason and execute
- **THEN** the case page SHALL show the term as paused with that reason
- **AND** choosing Resume for the same case and executing SHALL show the term as running again

#### Scenario: Extend term writes the new deadline
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** one open case due in 5 days selected on the Cases page
- **WHEN** you choose Extend term, set the deadline to 20 days from today, enter a reason and execute
- **THEN** the Deadline column SHALL show 20 days left for that case

#### Scenario: A case the engine refuses is reported per case
@e2e exclude The refusal needs a transition guard that fails for one case and passes for another, which is a seeded status-type guard; the per-case reporting is covered by the vitest unit test on bulkTransitionHelpers.summariseResults and the existing board e2e.

- **GIVEN** two cases selected, one of which a transition guard refuses
- **WHEN** you execute the transition
- **THEN** the dialog SHALL report 1 of 2 cases transitioned and name the refused case with its reason
