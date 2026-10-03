# case-bulk-status-transition Specification

## Purpose
TBD - created by archiving change case-bulk-status-transition. Update Purpose after archive.

## Requirements

### Requirement: Bulk transitions go through the engine

Bulk execution SHALL call `StatusTransitionService::execute()` once per case — evaluating guards and dispatching side effects per case — and SHALL NOT write status by any other path. A request SHALL be rejected with 400 when it contains more than 100 case ids, zero case ids, or no transition id.

#### Scenario: Guards evaluated per case

- **GIVEN** three cases where one is missing a required document for the transition
- **WHEN** bulk execute runs with that transition
- **THEN** two cases transition (side effects fire for each) and the third fails with its guard reasons
- **AND** the response reports per-case outcomes and summary counts (2 succeeded, 1 failed)

@e2e exclude Covered by PHPUnit service tests with a mocked engine (guard-fail path) — the engine's own guard behaviour has its own spec/tests.

#### Scenario: Oversized request rejected

- **WHEN** bulk execute is called with 101 case ids
- **THEN** the response is 400 and no case is transitioned

@e2e exclude PHPUnit-covered input validation; no browser surface.

### Requirement: Preview before execute

`POST /api/cases/bulk-transition/preview` SHALL return, per case, whether the transition is available and whether its guards currently pass (with failure reasons), performing no writes.

#### Scenario: Preview reports blockers without writing

- **GIVEN** a selection where one case would fail a role guard
- **WHEN** preview runs
- **THEN** the response marks that case as blocked with the guard's reason and the others as ready
- **AND** no case status changes

@e2e exclude PHPUnit service test asserts read-only behaviour (engine execute never invoked in preview).

### Requirement: Column-scoped selection on the workflow board

The workflow board SHALL offer a selection mode where cases can be multi-selected within a single column; selecting a case in a different column SHALL clear the previous selection. When one or more cases are selected, a bulk-actions bar SHALL offer "Change status…" opening the bulk-transition dialog scoped to that column's available transitions.

#### Scenario: Cross-column selection resets

- **GIVEN** two cases selected in the "Ontvangen" column
- **WHEN** the user selects a case in "In behandeling"
- **THEN** the selection contains only the newly selected case

@e2e exclude Selection reducer logic extracted to a helper and covered by vitest (node-env suite cannot mount SFCs); board rendering unchanged otherwise.

#### Scenario: Dialog previews then executes

- **GIVEN** three selected cases in one column and a chosen transition
- **WHEN** the user opens the bulk dialog
- **THEN** the dialog shows the preview per case (ready/blocked with reasons)
- **AND** confirming executes and shows per-case results without dismissing failures silently

@e2e exclude Dialog request/response orchestration extracted to a helper covered by vitest; endpoint behaviour covered by PHPUnit.

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

#### Scenario: The index offers the five bulk actions
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** one case selected on the Cases page
- **WHEN** the selection strip renders
- **THEN** it SHALL offer Reassign, Transition, Suspend, Resume and Extend term

The requirement's first sentence already says the `Cases` page carries these
five, and the four scenarios above each drive one of them. None of them says
the gestures are reachable at all, so a build that dropped a handler from
`bulkActions` would take its scenario down with it and leave the others
green. This scenario exists to make the strip itself checkable.

#### Scenario: A case the engine refuses is reported per case
@e2e exclude The refusal needs a transition guard that fails for one case and passes for another, which is a seeded status-type guard; the per-case reporting is covered by the vitest unit test on bulkTransitionHelpers.summariseResults and the existing board e2e.

- **GIVEN** two cases selected, one of which a transition guard refuses
- **WHEN** you execute the transition
- **THEN** the dialog SHALL report 1 of 2 cases transitioned and name the refused case with its reason
