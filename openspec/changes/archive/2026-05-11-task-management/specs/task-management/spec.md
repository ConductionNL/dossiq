---
status: implemented
---
# task-management Specification

## Purpose
Define the MVP task surface in Procest: validated task creation against a parent case, lifecycle state transitions with explicit error feedback, a filterable and searchable task list, overdue highlighting, anatomy of task cards, and reliable completion-date handling. Tasks are first-class work items linked to cases and surfaced both in `TaskList.vue`/`TaskDetail.vue` and in the `my-work` view.

## Context
Procest tasks are OpenRegister objects with a parent case reference, an assignee, a status (`new`/`in-progress`/`blocked`/`done`/`cancelled`), an optional due date, and a priority. Earlier changes shipped the schema and the basic CRUD; this change closes MVP gaps in validation, lifecycle feedback, list ergonomics, and card anatomy.

## ADDED Requirements
### Requirement: REQ-TASK-VAL-01 — Case Reference Validation on Task Creation
Task creation MUST validate that the parent case reference exists and is usable before persisting the task.

#### Scenario: TASK-VAL-01-1: Missing case reference
- **GIVEN** a user opens `TaskCreateDialog.vue` and submits without selecting a case
- **WHEN** `taskValidation.js` runs
- **THEN** the form MUST highlight the case field with the error "Selecteer een zaak"
- **AND** save MUST be blocked

#### Scenario: TASK-VAL-01-2: Unknown case reference
- **GIVEN** a user submits a task referencing a case id that does not resolve via the object store
- **THEN** validation MUST fail with "Zaak niet gevonden"
- **AND** no task object MUST be persisted

#### Scenario: TASK-VAL-01-3: Required core fields
- **GIVEN** a task with no title
- **THEN** validation MUST fail with "Titel is verplicht" before any backend call is made

### Requirement: REQ-TASK-LC-01 — Lifecycle Transition Error Feedback
`TaskDetail.vue` MUST surface explicit error messages when a lifecycle status transition is rejected.

#### Scenario: TASK-LC-01-1: Invalid transition
- **GIVEN** a task currently in status `done`
- **WHEN** the user attempts to set the status back to `new`
- **THEN** the UI MUST display a Dutch-language error explaining that the transition is not allowed
- **AND** the task status MUST remain unchanged

#### Scenario: TASK-LC-01-2: Backend rejection surfaced
- **GIVEN** the backend returns a 4xx error on a status update
- **THEN** the error message from the response MUST be surfaced near the status control
- **AND** the status select MUST revert to its previous value

#### Scenario: TASK-LC-01-3: Transition success clears prior errors
- **WHEN** a transition succeeds after a prior failure
- **THEN** any previously displayed transition error MUST be cleared

### Requirement: REQ-TASK-LIST-01 — Task List Filters and Search
`TaskList.vue` MUST expose filters for status, assignee, and priority, plus keyword search.

#### Scenario: TASK-LIST-01-1: Filter by status
- **GIVEN** the task list contains tasks in multiple statuses
- **WHEN** the user selects a status from the status filter
- **THEN** the list MUST refresh with `_filters[status]` applied
- **AND** only matching tasks MUST be visible

#### Scenario: TASK-LIST-01-2: Filter by assignee
- **WHEN** the user selects an assignee from the assignee filter
- **THEN** the list MUST refresh with `_filters[assignee]` applied

#### Scenario: TASK-LIST-01-3: Filter by priority
- **WHEN** the user selects a priority value
- **THEN** the list MUST refresh with `_filters[priority]` applied

#### Scenario: TASK-LIST-01-4: Keyword search
- **GIVEN** a task with title "Documenten verzamelen voor advies"
- **WHEN** the user types "advies" into the search field
- **THEN** the list MUST refresh with `_search=advies` and the matching task MUST appear

### Requirement: REQ-TASK-LIST-02 — Overdue Highlighting
`TaskList.vue` MUST visually highlight overdue tasks.

#### Scenario: TASK-LIST-02-1: Overdue row styling
- **GIVEN** a task with a `dueDate` in the past and a non-final status
- **THEN** the row MUST render with an explicit overdue visual treatment (red accent or icon)
- **AND** the due date MUST be labeled "Te laat" or equivalent Dutch term

#### Scenario: TASK-LIST-02-2: Completed tasks not flagged
- **GIVEN** a task that is in status `done` or `cancelled` even with a past `dueDate`
- **THEN** no overdue styling MUST be applied

### Requirement: REQ-TASK-CARD-01 — Task Card Anatomy
Task cards in both the list and the my-work view MUST display a consistent, recognizable anatomy.

#### Scenario: TASK-CARD-01-1: Required card content
- **GIVEN** any task card
- **THEN** the card MUST display: title, parent case reference (identifier + truncated case title), assignee, priority indicator, status pill, and due date

#### Scenario: TASK-CARD-01-2: Missing case reference
- **GIVEN** a task whose parent case can not be resolved at render time
- **THEN** the case reference area MUST show a neutral placeholder rather than an empty space, without breaking the layout

#### Scenario: TASK-CARD-01-3: Clickable card navigates to task detail
- **WHEN** the user clicks anywhere on the card (outside interactive controls)
- **THEN** the app MUST navigate to the task detail view

### Requirement: REQ-TASK-COMP-01 — Completion Date on Completion
Tasks transitioning to `done` MUST have their `completedDate` set automatically.

#### Scenario: TASK-COMP-01-1: Auto-set completedDate
- **GIVEN** a task transitioning from any status to `done`
- **WHEN** the update is persisted
- **THEN** the task object MUST contain a `completedDate` set to the transition timestamp
- **AND** the value MUST be set even if the client did not explicitly send it

#### Scenario: TASK-COMP-01-2: Re-opening clears completion
- **GIVEN** a task previously in `done`
- **WHEN** the user reopens it via an allowed transition (e.g., to `in-progress`)
- **THEN** `completedDate` MUST be cleared on the persisted object

#### Scenario: TASK-COMP-01-3: Cancelled tasks
- **GIVEN** a task transitioning to `cancelled`
- **THEN** `completedDate` MUST NOT be set (only `done` transitions trigger it)

### Requirement: REQ-TASK-INT-01 — Integration with Case Detail and My Work
Task changes MUST stay coherent with the case detail and my-work surfaces.

#### Scenario: TASK-INT-01-1: Reflected on case detail
- **GIVEN** a task linked to a case
- **WHEN** the task is created, updated, or completed
- **THEN** the case detail tasks panel MUST reflect the change on next render

#### Scenario: TASK-INT-01-2: Reflected in my-work
- **GIVEN** a task assigned to the current user
- **WHEN** the task transitions, becomes overdue, or completes
- **THEN** the my-work view MUST reflect the change on next render and re-apply its grouping

## Dependencies
- OpenRegister `task` schema and shared `createObjectStore`
- `src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`, `src/views/tasks/TaskCreateDialog.vue`
- `src/utils/taskValidation.js`
- `case-management` capability (parent case references)
- `my-work` capability (task surface for assigned tasks)
