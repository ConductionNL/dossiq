---
status: in-progress
---

## OpenSpec changes

- `case-flow-human-steps` (active) — a status move performed by a flow is
  attributable to the node that performed it, and each move in a case flow is
  its own step rather than a side effect of another one: the status is the
  applicant-facing signal, so it must be moved deliberately.

## Purpose

@e2e exclude Status transition guard engine is V1; guard evaluation logic is covered by unit tests, not browser E2E.

## Requirements

### Requirement: Guard Evaluation Engine

The system SHALL evaluate all guards on a status transition before allowing the transition to proceed. Guards are evaluated in the frontend by querying the case's current state against the guard conditions.

**Feature tier**: V1

#### Scenario: Checklist guard evaluation

- **WHEN** a transition has a checklist guard with 5 items
- **AND** the case handler has checked 4 of 5 items
- **THEN** the transition button SHALL be disabled
- **AND** the tooltip SHALL display: "1 checklistitem niet afgevinkt: 'Besluit opgesteld'"

#### Scenario: Required field guard evaluation

- **WHEN** a transition requires field "resultaat" to be filled
- **AND** the case has no value for "resultaat"
- **THEN** the transition button SHALL be disabled
- **AND** the system SHALL highlight the missing field in the case form

#### Scenario: Required document guard evaluation

- **WHEN** a transition requires document type "Besluit" to be uploaded
- **AND** no document of type "Besluit" is attached to the case
- **THEN** the transition button SHALL be disabled
- **AND** the system SHALL display: "Vereist document ontbreekt: Besluit"

#### Scenario: Role guard evaluation

- **WHEN** a transition is restricted to role "Afdelingshoofd"
- **AND** the current user has role "Behandelaar" on the case
- **THEN** the transition button SHALL NOT be visible to this user

#### Scenario: All guards pass

- **WHEN** all guards on a transition are satisfied
- **THEN** the transition button SHALL be enabled
- **AND** clicking it SHALL execute the status change and trigger any configured automatic actions

### Requirement: Transition Execution

The system SHALL execute status transitions atomically: the case status changes, automatic actions are triggered, and an audit trail entry is created in a single logical operation.

**Feature tier**: V1

#### Scenario: Successful transition with audit trail

- **WHEN** a case handler executes transition "Afronden" from "In behandeling" to "Afgehandeld"
- **THEN** the case `status` property SHALL be updated to the target StatusType UUID
- **AND** an audit entry SHALL be created with: timestamp, user, fromStatus, toStatus, transitionLabel
- **AND** the case `updatedAt` timestamp SHALL be refreshed

#### Scenario: Transition triggers automatic actions

- **WHEN** transition "Goedkeuren" has automatic actions configured (send email, create task)
- **AND** the transition is executed
- **THEN** all automatic actions SHALL be triggered in the order they are defined
- **AND** failure of an automatic action SHALL NOT roll back the status change
- **AND** failed actions SHALL be logged with error details

### Requirement: Available Transitions for Current User

You see, on the case page, only the transitions you may take from the case's
current status. The system SHALL compute the available transitions from the
case type's workflow template, the current status, the signed-in user's role
and guard satisfaction, and SHALL offer them on `CaseDetail` as the reachable
stages of the timeline widget, so the move is asked for where the status is
shown. The transitions SHALL reach the page through OpenRegister's
`available-actions`, which dossiq answers with `CaseActionProvider` off the
same engine the write path validates against, so no second derivation can
offer a move the write refuses.

**Feature tier**: V1

#### Scenario: Display available transitions on case detail
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in status "In behandeling"
- **AND** the workflow defines transitions "Goedkeuren" (requires role Afdelingshoofd) and "Terugsturen" (any role)
- **AND** the user has role "Behandelaar"
- **WHEN** the user opens the case page
- **THEN** only the stage "Terugsturen" leads to SHALL be offered on the timeline
- **AND** the stage "Goedkeuren" leads to SHALL be refused with a reason

#### Scenario: No transitions available
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in a final status "Afgehandeld"
- **WHEN** the user opens the case page
- **THEN** no stage on the timeline SHALL be choosable
- **AND** the timeline SHALL mark "Afgehandeld" as the stage the case is on

### Requirement: A status brings its checklist tasks with it (REQ-STE-01)

Every status brings its checklist tasks with it when a case reaches it. The
`statusType` schema SHALL carry `checklist`, a list of items with a `title`
and a `required` flag. When a case enters a status, over a transition or an
admin's free-form move, the engine SHALL create one task per item on the
case through the existing `createTask` handler, with `workflowStepId` set to
the status type's id. The tasks SHALL show in the Tasks pane on the case
page.

**Feature tier**: MVP

#### Scenario: The tasks arrive with the status
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case type whose status In behandeling lists two checklist items
- **AND** a case of that type in Intake with no tasks
- **WHEN** you move the case to In behandeling
- **THEN** the Tasks pane SHALL show two tasks with the items' titles
- **AND** each SHALL be at status available

#### Scenario: An admin's free-form move brings them too
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** the same case type and a case in Intake
- **WHEN** an admin moves the case to In behandeling with the free-form move
- **THEN** the Tasks pane SHALL show the two tasks

#### Scenario: A status without a list creates nothing
@e2e exclude The absence of a task is asserted by StatusChecklistTest; the browser cannot tell nothing from not yet.

- **GIVEN** a status whose `checklist` is empty or absent
- **WHEN** a case enters it
- **THEN** the engine SHALL create no task
- **AND** the transition's own automatic actions SHALL run as before

### Requirement: Entering a status twice creates the tasks once (REQ-STE-02)

You send a case back and forward without getting its tasks twice. Before
creating an item's task the engine SHALL look for a task on the case with the
same title and the status type's id as `workflowStepId`, open or completed,
and SHALL skip the item when one exists.

**Feature tier**: MVP

#### Scenario: Back to Intake and forward again
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case that reached In behandeling and holds its two checklist tasks, one completed
- **WHEN** you move the case back to Intake and forward to In behandeling
- **THEN** the Tasks pane SHALL still show two tasks for that status
- **AND** the completed one SHALL still be completed

### Requirement: A required item holds the case in its status (REQ-STE-03)

A required item keeps the case where it is until its task is done. On every
transition out of a status the engine SHALL evaluate a `statusChecklist`
guard: each item of the current status with `required` true SHALL have a
task on the case at status completed. A required item with no task SHALL
count as not done. The failed guard SHALL name the item, and the case page
SHALL show that reason beside the stage the refused move leads to.

**Feature tier**: MVP

#### Scenario: The button says which item is open
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case in Intake whose required item Check the objection is on time has an open task
- **WHEN** you open the case page
- **THEN** the stage In behandeling SHALL carry the guard's reason
- **AND** that reason SHALL read Checklist item not done: Check the objection is on time
- **AND** the move SHALL be refused when it is attempted

#### Scenario: Completing the task frees the case
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** the same case
- **WHEN** you complete that task
- **THEN** the stage In behandeling SHALL be offered with no reason beside it
- **AND** the move SHALL succeed

#### Scenario: An optional item does not hold the case
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case in Intake whose only open task is the optional item Confirm receipt to the objector
- **WHEN** you open the case page
- **THEN** the stage In behandeling SHALL be offered with no reason beside it

#### Scenario: The guard fails server-side too
@e2e exclude A direct API call past the page is asserted by StatusChecklistGuardTest and StatusTransitionServiceRouteSeamTest.

- **GIVEN** a case in Intake with a required item's task still open
- **WHEN** the transition is posted to the API
- **THEN** the engine SHALL refuse it with the `statusChecklist` guard failed
- **AND** the case SHALL still be in Intake

### Requirement: Closing a case settles its end date and its archival future (REQ-STE-13)

A closing transition SHALL write the case's `endDate` and, when a result type was
chosen, its `archiveNomination` and `archiveActionDate`, in the SAME save as the
status. The archival pair SHALL be derived by
`Service\Archival\ArchivalNominationDeriver`, which is also what the ZGW API
closing path calls, so a case closed at the desk and the same case closed over
the API end in the same archival state. Reopening SHALL withdraw all three.

Dossiq does not destroy anything and does not schedule a destruction. Retention
and destruction are OpenRegister's, declared as `x-openregister-archival` on the
case schema (ADR-022). This requirement is about the case being NOMINATED
correctly and carrying the right date, which is what a ZGW consumer reads off
the zaak and what an archivist works from.

**Feature tier**: MVP

#### Scenario: A case closed at the desk carries the same archival future as one closed over the API
@e2e exclude The derivation has no browser surface: no widget renders archiveNomination or archiveActionDate, so a Playwright assertion would have to read the case object back over the API, which is what ArchivalNominationDeriverTest and StatusTransitionServiceResultTest already assert directly.

- **GIVEN** a case whose result type nominates for vernietigen after P5Y from afgehandeld
- **WHEN** the case is closed through the in-app transition
- **THEN** the case SHALL carry `endDate` of today
- **AND** `archiveNomination` vernietigen
- **AND** `archiveActionDate` five years after the end date
- **AND** the same case closed over the ZGW API SHALL come out with the same three values

#### Scenario: A case type with no result types still closes and still gets an end date
@e2e exclude Same absent surface; asserted by StatusTransitionServiceResultTest.

- **GIVEN** a case whose case type declares no result types
- **WHEN** the case is closed
- **THEN** the transition SHALL succeed
- **AND** the case SHALL carry an `endDate`
- **AND** the case SHALL carry no archival nomination, because there is no result to derive one from

#### Scenario: Reopening withdraws the archival claim
@e2e exclude Same absent surface; asserted by CaseLifecycleServiceTest.

- **GIVEN** a closed case nominated for vernietigen with an archiefactiedatum
- **WHEN** the case is reopened
- **THEN** its `endDate`, `archiveNomination` and `archiveActionDate` SHALL all be cleared
- **AND** the case SHALL NOT appear in an archivist's due list while it is being worked

### Requirement: A status move performed by a flow names the node that performed it @e2e exclude attribution is asserted in openregister; this requirement fixes dossiq's side of the contract

When a case's status is moved by a flow, the resulting audit record SHALL carry the run and node that moved it, so "who moved this case, and why" is answerable without inferring it from timing.

Each status move in a case flow SHALL be its own step rather than a side effect of another step. A status that changes as a by-product of an unrelated node cannot be attributed to an intention, and it is the applicant-facing signal — it is the thing the case's progress is read from.

#### Scenario: A flow-driven status move is attributed
- **WHEN** a flow node moves a case's status
- **THEN** the audit record for that change names the run and the node

#### Scenario: A status move is a step of its own
- **WHEN** a case flow moves a case between stages
- **THEN** the move is performed by a node whose purpose is the move
- **AND** the run's history shows it as a step

#### Scenario: A status move outside a flow is unattributed, not mis-attributed
- **WHEN** a person changes a case's status directly
- **THEN** the audit record names the person
- **AND** it names no flow run

### Requirement: A transition executes from the case page (REQ-STE-11)

You move the case to its next status from the case page by clicking the stage
you are moving it to. A move that declares inputs SHALL ask for exactly those
first and SHALL send nothing when the question is cancelled; a move that
declares none SHALL be taken on the click. The transition SHALL reach
`StatusTransitionService` through OpenRegister, which re-validates it. The page
SHALL then show the new status without a reload, and the guard failures the
service reports SHALL be shown where the click happened instead of moving the
case.

The optional comment is NOT carried here any more. It belonged to the
transition strip's own confirmation dialog, and the strip is gone: a move now
asks only for the inputs it declares.

#### Scenario: A handler advances a case
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a seeded case whose current status allows one transition to "In behandeling"
- **WHEN** the handler clicks that stage on the timeline
- **THEN** the case's status SHALL be the target status
- **AND** the transition history SHALL hold one new row with the handler as actor
- **AND** the timeline SHALL mark the new status and offer the moves out of it

#### Scenario: A failed guard keeps the case where it is
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a transition whose guard requires a document the case lacks
- **WHEN** the handler clicks the stage it leads to
- **THEN** the timeline SHALL show the guard's message
- **AND** the case's status SHALL be unchanged

### Requirement: Closing a case asks for the result (REQ-STE-12)

When you close a case, you pick the result. A transition whose target status is
final SHALL require a result type from the case type's result types before it
executes, and SHALL write the result on the case in the same request.

#### Scenario: A final transition records a result
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case one transition away from a final status
- **AND** the case type has result types "Verleend" and "Geweigerd"
- **WHEN** the handler clicks the final stage and gives "Verleend" as the result
- **THEN** the case SHALL be in the final status
- **AND** the case's `result` SHALL reference a result of type "Verleend"

#### Scenario: No result, no close
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** the same case
- **WHEN** the handler tries to confirm the final move without a result
- **THEN** the confirm button SHALL be disabled
- **AND** the case SHALL stay in its current status

### Requirement: Suspend, resume, extend and reopen from the Actions menu (REQ-STE-13)

You suspend, resume, extend or reopen a case from its Actions menu. The Actions
menu on `CaseDetail` SHALL offer Suspend when the case type allows suspension,
Resume when the case is suspended, Extend term when the case type allows
extension, and Reopen when the case is in a final status. Each SHALL ask for a
reason and SHALL run through the existing deadline and transition services, so
the deadline moves and the audit row is written.

#### Scenario: Suspend then resume
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** an open case of a case type with `suspensionAllowed` true
- **WHEN** the handler chooses Suspend, gives a reason and confirms
- **THEN** the case SHALL read as suspended on its own lifecycle endpoint
- **AND** the Actions menu SHALL offer Resume
- **AND** choosing Resume SHALL clear the suspension and recompute the deadline

#### Scenario: Extend the term
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** an open case of a case type with `extensionAllowed` true and `extensionPeriod` P14D
- **WHEN** the handler chooses Extend term and confirms
- **THEN** the case's deadline SHALL be fourteen days later than before
- **AND** `extensionCount` SHALL be one higher

#### Scenario: Reopen a closed case
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in a final status
- **WHEN** a handler with the reopen scope chooses Reopen, gives a reason and confirms
- **THEN** the case SHALL be in the case type's initial status
- **AND** `isFinalStatus` SHALL be false

#### Scenario: A user without the reopen scope is refused
@e2e exclude authorization is asserted by a PHPUnit test on CaseLifecycleController; Playwright runs as admin and cannot take a lesser role

- **GIVEN** a case in a final status
- **WHEN** a user without the reopen scope posts the reopen request
- **THEN** the request SHALL be refused with 403
- **AND** the case SHALL stay closed
