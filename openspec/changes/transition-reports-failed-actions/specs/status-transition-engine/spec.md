## ADDED Requirements

### Requirement: A transition that moved without all of its actions says so (REQ-STE-14)

When a transition or a free-form transition has moved the case and one or more
of the actions it dispatched failed, `StatusTransitionService` SHALL answer
`status: "partial"` and SHALL list each failed action in `failedActions` as
`{type, error}`, in dispatch order. When no action failed it SHALL answer
`status: "ok"` with an empty `failedActions`. `failedActions` SHALL always be
present. A failed action SHALL NOT roll back the move, and the answer SHALL
NOT be an error: the HTTP status stays 200. Each partial answer SHALL be
logged at warning level with the case id and the failed rows.

A dispatched row counts as failed only when its `ok` key is `false`. A row
without an `ok` key counts as done, and a failed row without an `error`
reports `action_failed`.

The case-page transition dialog and the workflow board SHALL warn the handler
with the number of actions that did not run, and SHALL still refresh the page:
the move itself happened.

#### Scenario: Every action ran
@e2e exclude answer shape only; covered by StatusTransitionServiceFailedActionsTest, which asserts ok and an empty failedActions on both execute paths

- **GIVEN** a transition whose dispatched actions all report `ok: true`
- **WHEN** the transition is executed
- **THEN** the answer SHALL carry `status: "ok"`
- **AND** `failedActions` SHALL be an empty list
- **AND** no warning SHALL be logged

#### Scenario: An action failed after the case moved
@e2e exclude a failing action cannot be provoked from the browser on a healthy instance; covered by StatusTransitionServiceFailedActionsTest on execute and executeFreeForm

- **GIVEN** a transition that dispatches `createTask` and `sendEmail`
- **AND** `createTask` reports `ok: false` with error `no_actor`
- **WHEN** the transition is executed
- **THEN** the case SHALL be in the target status
- **AND** the answer SHALL carry `status: "partial"`
- **AND** `failedActions` SHALL be `[{type: "createTask", error: "no_actor"}]`
- **AND** a warning SHALL be logged naming the case

#### Scenario: The handler is told how many actions did not run
@e2e exclude needs a failing action, which the browser cannot provoke; covered by caseTransitionOutcome.spec.js, which mounts the dialog and drives the board handler against a partial answer

- **GIVEN** a handler confirms a transition in the case-page dialog
- **AND** the answer lists two failed actions
- **WHEN** the answer arrives
- **THEN** a warning SHALL say two automatic actions did not run
- **AND** the dialog SHALL close and the page SHALL refresh
