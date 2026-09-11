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
case. Suspend SHALL register a pause on each case's running term with the reason;
Resume SHALL end that pause; Extend term SHALL take a new end date and write
it with the reason. All three SHALL go through `CaseLifecycleService`'s
single-case `suspend()` / `resume()` / `extend()`, which own the case type's
`suspensionAllowed` and `extensionAllowed` rules, the already-suspended and
not-suspended checks, the journal entry on the case and the term-instance
write — a bulk gesture SHALL NOT reach past them to `DeadlinePauseService`,
which would mean either reimplementing every guard or shipping without them.

**Extend term does NOT move the Deadline COLUMN.** `case.deadline` is
`readOnly` and materialised by OpenRegister from
`startDate + caseType.processingDeadline`, recomputed on every save, so
nothing outside that calculation can write it. An extension writes
`plannedEndDate` and the term instance's `endDateCurrent`, which is what the
statutory clock, the daily scan and the dwangsom engine read. Making the
column follow an extension is a schema change (teaching the calculation to
prefer `plannedEndDate`), which this change rules out. Every mode SHALL preview per case before executing and SHALL
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

#### Scenario: Extend term writes the new end date
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** one open case due in 5 days selected on the Cases page
- **WHEN** you choose Extend term, set the new deadline to 20 days from today, enter a reason and execute
- **THEN** the dialog SHALL report 1 of 1 cases extended
- **AND** the case page SHALL show the extended term with that reason
- **AND** the Deadline column SHALL be unchanged, because `case.deadline` is a read-only calculation off the case type's processing deadline

#### Scenario: A case the engine refuses is reported per case
@e2e exclude The refusal needs a transition guard that fails for one case and passes for another, which is a seeded status-type guard; the per-case reporting is covered by the vitest unit test on bulkTransitionHelpers.summariseResults and the existing board e2e.

- **GIVEN** two cases selected, one of which a transition guard refuses
- **WHEN** you execute the transition
- **THEN** the dialog SHALL report 1 of 2 cases transitioned and name the refused case with its reason
