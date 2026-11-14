## ADDED Requirements

### Requirement: REQ-TASK-014 A task MUST be finished on the case page

You finish a task on the case page and see a confirmation without leaving
the case. Widget `case-tasks` on `CaseDetail` SHALL show the first open
task of the case (`case = @objectId`, `isTerminalStatus = false`, earliest
`dueDate` first) with its title, assignee, due date and the lifecycle
buttons that OpenRegister's `available-actions` route answers for that task
(`caseTask.status` lifecycle: activate, complete, terminate, disable),
rendered by `CnLifecycleActions` with the same labels `TaskDetail` shows.
A transition SHALL write through the lifecycle route and SHALL NOT navigate
away. On a transition to a final status the widget SHALL show a success
toast naming the task and SHALL replace the pane with the next open task.
When no open task remains the pane SHALL show the text "No open tasks on
this case". The other open tasks SHALL stay listed under the pane, each
routing to `TaskDetail`, and View all SHALL keep leading to the Tasks list
filtered on the case. Until nextcloud-vue's `CnObjectListWidget` accepts a
lifecycle column the widget SHALL be type `custom`, resolved through the
page slot `widget-case-tasks` to the dossiq component `CaseTaskPane`; the
widget id and the page SHALL NOT change, so no page is added.

#### Scenario: The open task shows its buttons on the case
@e2e tests/e2e/case-task-pane.spec.ts

- **GIVEN** a case with an open task in status active and a second open task with a later due date
- **WHEN** you open the case page
- **THEN** the widget `case-tasks` SHALL show the first task's title with the buttons Mark task as completed, Terminate the task and Disable the task
- **AND** the second task SHALL be listed under the pane

#### Scenario: Completing the task confirms and shows the next one
@e2e tests/e2e/case-task-pane.spec.ts

- **GIVEN** the case page with an open task in status active and a second open task
- **WHEN** you press Mark task as completed
- **THEN** a success toast SHALL name the completed task
- **AND** the route SHALL still be the case page
- **AND** the pane SHALL show the second task with its own buttons

#### Scenario: The last task leaves an empty pane
@e2e tests/e2e/case-task-pane.spec.ts

- **GIVEN** a case with exactly one open task in status active
- **WHEN** you press Mark task as completed
- **THEN** the pane SHALL show "No open tasks on this case"
- **AND** the Tasks list reached through View all SHALL show the task as completed

#### Scenario: A transition the server refuses is reported, not swallowed
@e2e exclude The refusal needs a task whose assignee is another user; the e2e user is admin, so the assignee rule is exercised by the PHPUnit test on TaskCompletionResumeListener in case-flow-human-steps, and the pane's error path by the vitest unit test on CaseTaskPane.

- **GIVEN** a task assigned to another user
- **WHEN** you press Mark task as completed
- **THEN** the pane SHALL show an error toast with the server's message
- **AND** the task SHALL stay in the pane in its current status

#### Scenario: The pane adds no page
@e2e exclude Manifest shape is checked by the unit test on src/manifest.json in tests/unit/manifest-case-task-pane.spec.js; a page count is not a browser observation.

- **GIVEN** the manifest before and after this change
- **WHEN** the pages are counted
- **THEN** the count SHALL be equal
- **AND** the widget `case-tasks` SHALL still sit on the page `CaseDetail`

### Requirement: REQ-TASK-015 A task MUST link back to its case

You find your way back from a task to the case it belongs to. The
`TaskDetail` page SHALL show the case the task belongs to (`caseTask.case`)
by its case title, as a link that routes to `CaseDetail` for that case. The
link SHALL render for every task with a case, independent of whether the
task holds a flow run, and SHALL sit above the Data widget. A task without a
case SHALL show no link and no empty box.

#### Scenario: The task names its case and leads back to it
@e2e tests/e2e/case-task-pane.spec.ts

- **GIVEN** a task that belongs to a case
- **WHEN** you open the task page
- **THEN** the page SHALL show the case title as a link
- **AND** following it SHALL open the case page for that case

#### Scenario: A task without a case shows no link
@e2e exclude Every task the seed creates belongs to a case; the null render is covered by the vitest unit test on TaskCaseLink.

- **GIVEN** a task whose `case` is empty
- **WHEN** you open the task page
- **THEN** no case link and no empty box SHALL render
