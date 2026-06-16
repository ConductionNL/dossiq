---
status: done
---

# Task Management Specification

## Purpose

Tasks represent work items within a case. They follow CMMN 1.1 HumanTask concepts and are semantically typed as `schema:Action`. Tasks can be assigned to Nextcloud users, have due dates and priorities, and follow an independent lifecycle within the parent case. Tasks are the primary mechanism for distributing and tracking work across case handlers, advisors, and other participants.

**Standards**: CMMN 1.1 (HumanTask, PlanItem lifecycle), Schema.org (`Action`, `actionStatus`), BPMN 2.0 (task patterns)
**Primary feature tier**: MVP
**Extended features**: V1 (kanban, checklists, dependencies, templates), Enterprise (automation)

**Competitive context**: Flowable provides the most comprehensive task management with a unified task service across BPMN and CMMN engines, supporting a 5-state lifecycle (created/claimed/in-progress/suspended/completed) with delegation and sub-tasks. Dimpact ZAC uses Flowable-backed tasks with WebSocket-based real-time updates and configurable worklists. xxllnc Zaken implements phase-bound tasks that become read-only when the case progresses past their phase. ArkCase uses Activiti-backed tasks with queue-based routing via Drools rules. Procest takes an OpenRegister-first approach where tasks are JSON objects with CMMN-compliant lifecycle states, avoiding the complexity of an embedded workflow engine.

---

## Data Model

### Task Entity

Stored as an OpenRegister object in the `procest` register under the `task` schema.

| Property | Type | CMMN/Schema.org | Required | Default |
|----------|------|----------------|----------|---------|
| `title` | string (max 255) | `schema:name` | Yes | -- |
| `description` | string | `schema:description` | No | -- |
| `status` | enum | CMMN PlanItem states | Yes | `available` |
| `assignee` | string (Nextcloud user UID) | CMMN assignee | No | -- |
| `case` | reference (UUID) | CMMN parent case | Yes | -- |
| `dueDate` | datetime (ISO 8601) | `schema:endTime` | No | -- |
| `priority` | enum: `low`, `normal`, `high`, `urgent` | `schema:priority` | No | `normal` |
| `completedDate` | datetime (ISO 8601) | `schema:endTime` | Auto (set on completion) | -- |

### Task Status Lifecycle (CMMN PlanItem)

```
                +----------+
                | available|
                +----+-----+
                     | start
                     v
                +----------+
           +----+  active  +----+
           |    +----------+    |
     complete                terminate
           |                    |
           v                    v
    +-----------+       +------------+
    | completed |       | terminated |
    +-----------+       +------------+

    +----------+
    | disabled |  (set from available only)
    +----------+
```

| Status | CMMN State | Description | Allowed Transitions From |
|--------|-----------|-------------|--------------------------|
| `available` | Available | Task can be started | (initial state) |
| `active` | Active | Task is being worked on | `available` |
| `completed` | Completed | Task finished successfully | `active` |
| `terminated` | Terminated | Task stopped before completion | `available`, `active` |
| `disabled` | Disabled | Task not applicable | `available` |

### Priority Levels

| Priority | Sort Weight | Visual Indicator |
|----------|------------|------------------|
| `urgent` | 1 (highest) | Red badge |
| `high` | 2 | Orange badge |
| `normal` | 3 | No badge (default) |
| `low` | 4 (lowest) | Grey badge |

---
## Requirements

### REQ-TASK-001: Task CRUD

The system MUST support task create, read, update, and delete operations linked to cases.

@e2e exclude Task CRUD requires existing cases to link tasks to; data-dependent create/read/update/delete flows not testable without pre-seeded cases.

The system MUST support creating, reading, updating, and deleting tasks linked to cases. All task objects are stored in OpenRegister under the `procest` register, `task` schema.

**Tier**: MVP

#### Scenario: Create a task linked to a case

- GIVEN a case #2024-042 "Bouwvergunning Keizersgracht" exists with status "In behandeling"
- AND the current user "jan.devries" has access to the case
- WHEN the user creates a task with title "Controleer bouwtekeningen" and assigns it to case #2024-042
- THEN the system MUST create an OpenRegister object in the `task` schema
- AND the task `case` property MUST contain the UUID of case #2024-042
- AND the task `status` MUST default to `available`
- AND the task `priority` MUST default to `normal`
- AND the `completedDate` MUST be null
- AND the audit trail MUST record the creation event with the creating user

#### Scenario: Create a task with all optional fields

- GIVEN a case #2024-048 "Subsidie verduurzaming" exists
- WHEN the user creates a task with:
  - title: "Beoordeel begroting"
  - description: "Controleer of de ingediende begroting voldoet aan de subsidievoorwaarden"
  - assignee: "maria.bakker"
  - dueDate: "2026-03-01T17:00:00Z"
  - priority: "high"
- THEN all properties MUST be stored correctly on the task object
- AND the task `status` MUST still default to `available`

#### Scenario: Update a task's description

- GIVEN an existing task "Controleer bouwtekeningen" with status `available`
- WHEN the user updates the description to "Controleer bouwtekeningen inclusief constructieberekening"
- THEN the system MUST update the task object via `PUT` to the OpenRegister API
- AND the audit trail MUST record the update with the changed fields

#### Scenario: Delete a task

- GIVEN a task "Verouderde controle" with status `available` in case #2024-042
- WHEN the user deletes the task
- THEN the system MUST call `DELETE` on the OpenRegister API
- AND the task MUST no longer appear in the case's task list
- AND the audit trail MUST record the deletion

#### Scenario: Validation errors on task creation

- GIVEN the user is creating a new task for case #2024-042
- WHEN the user submits the form with an empty title
- THEN the system MUST reject the request with a validation error indicating `title` is required
- AND submitting without a case reference MUST be rejected with an error indicating `case` is required
- AND submitting with a non-existent case UUID MUST be rejected with an error indicating the case does not exist

---

### REQ-TASK-002: Task Status Lifecycle

The system MUST enforce the CMMN PlanItem task status lifecycle.

@e2e exclude Task lifecycle transitions require existing tasks with specific statuses; data-dependent state machine tests covered by taskLifecycle.js unit tests.

The system MUST enforce the CMMN PlanItem lifecycle for task status transitions, as implemented in `src/utils/taskLifecycle.js`. Invalid transitions MUST be rejected.

**Tier**: MVP

#### Scenario: Start a task (available to active)

- GIVEN a task "Controleer bouwtekeningen" with status `available`
- AND the task is assigned to "jan.devries"
- WHEN the user changes the status to `active`
- THEN the status MUST change to `active`
- AND the audit trail MUST record the status transition with timestamp and user

#### Scenario: Complete a task (active to completed)

- GIVEN a task "Controleer bouwtekeningen" with status `active` assigned to "jan.devries"
- WHEN "jan.devries" marks the task as completed
- THEN the status MUST change to `completed`
- AND the `completedDate` MUST be set to the current timestamp (ISO 8601)
- AND the audit trail MUST record the completion

#### Scenario: Terminate a task

- GIVEN a task "Locatiebezoek plannen" with status `active`
- WHEN the user terminates the task with reason "Niet meer nodig na telefonisch contact"
- THEN the status MUST change to `terminated`
- AND the task MUST remain visible in the case timeline (not deleted)
- AND termination from status `available` MUST also be allowed

#### Scenario: Reject invalid transition - complete an available task

- GIVEN a task "Controleer bouwtekeningen" with status `available`
- WHEN the user attempts to change the status directly to `completed`
- THEN the system MUST reject the transition
- AND the error message MUST indicate that a task must be `active` before it can be `completed`
- AND the task status MUST remain `available`

#### Scenario: Reject invalid transition - reactivate a completed task

- GIVEN a task "Intake controle" with status `completed` and `completedDate` "2026-01-20T14:30:00Z"
- WHEN the user attempts to change the status back to `active`
- THEN the system MUST reject the transition
- AND the `completedDate` MUST remain unchanged
- AND similarly, disabled tasks MUST NOT be reactivatable (disabled is a terminal state)

---

### REQ-TASK-003: Task Assignment

The system MUST support assigning tasks to Nextcloud users.

@e2e exclude Task assignment requires existing tasks; data-dependent assignment flows not testable without pre-seeded tasks.

The system MUST support assigning tasks to Nextcloud users by their user UID. Unassigned tasks are allowed.

**Tier**: MVP

#### Scenario: Assign a task to a user

- GIVEN an available task "Controleer bouwtekeningen" in case #2024-042
- WHEN the user assigns it to Nextcloud user "jan.devries"
- THEN the task `assignee` MUST be set to "jan.devries"
- AND the task MUST appear in Jan de Vries's "My Work" view
- AND the audit trail MUST record the assignment

#### Scenario: Assign a task with notification

- GIVEN an available task "Beoordeel constructieberekening"
- WHEN the user assigns it to "pieter.smit"
- THEN the assigned user SHOULD receive a Nextcloud notification
- AND the notification MUST include the task title and the parent case reference

#### Scenario: Reassign a task to a different user

- GIVEN a task "Review documenten" assigned to "jan.devries" with status `active`
- WHEN the coordinator reassigns it to "maria.bakker"
- THEN the `assignee` MUST be updated to "maria.bakker"
- AND "maria.bakker" SHOULD receive an assignment notification
- AND the audit trail MUST record the reassignment from "jan.devries" to "maria.bakker"
- AND the task MUST remain in its current status (`active`)

#### Scenario: Unassign a task

- GIVEN a task "Verzamel informatie" assigned to "jan.devries" with status `available`
- WHEN the user removes the assignee
- THEN the `assignee` MUST be set to null
- AND the task MUST no longer appear in Jan's "My Work" view

#### Scenario: Attempt to assign to a non-existent user

- GIVEN a task "Controleer bouwtekeningen"
- WHEN the user attempts to assign it to "nonexistent.user"
- THEN the system MUST reject the assignment
- AND the error message MUST indicate that the user does not exist in Nextcloud

---

### REQ-TASK-004: Task List View

The system MUST provide a list view for tasks with search, sorting, and filtering capabilities. The list view MUST support both a global task list (all tasks) and a case-scoped task list (tasks for a specific case). (Note 2026-06-01: rendered by the CnAppRoot manifest shell; no `src/views/tasks/TaskList.vue` SPA view file exists.)

**Tier**: MVP

#### Scenario: View the global task list

- GIVEN 23 tasks exist across 8 cases
- WHEN the user navigates to the Tasks section (via Mijn werk → Alle taken)
- THEN the system MUST display a paginated list of tasks
- AND each task row MUST show: title, parent case reference (ID + title), status, assignee, due date, and priority

#### Scenario: View tasks for a specific case

@e2e exclude Requires case #2024-042 with 5 tasks; data-dependent task section on case detail not testable without pre-seeded data.

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" has 5 tasks
- WHEN the user views the case detail page
- THEN all 5 tasks MUST be displayed in the Tasks section
- AND tasks MUST be sorted by status (available/active first) then by priority (urgent first) then by due date (earliest first)
- AND the task count MUST show completion progress (e.g., "3/5")

#### Scenario: Filter tasks by status

@e2e exclude Requires 23 tasks with specific statuses; data-dependent status filter not testable without pre-seeded data.

- GIVEN 23 tasks exist with statuses: 4 available, 6 active, 12 completed, 1 terminated
- WHEN the user filters by status "active"
- THEN only the 6 active tasks MUST be shown
- AND the filter MUST be clearly indicated in the UI

#### Scenario: Sort tasks by due date

@e2e exclude Requires multiple tasks with varying due dates; data-dependent sorting not testable without pre-seeded data.

- GIVEN tasks with various due dates
- WHEN the user sorts by due date ascending
- THEN tasks MUST be ordered with the earliest due date first
- AND tasks without a due date MUST appear at the end

#### Scenario: Search tasks by title

@e2e exclude Requires tasks with specific titles to search; data-dependent search not testable without pre-seeded data.

- GIVEN tasks with titles including "bouwtekeningen", "constructie", "situatie"
- WHEN the user searches for "bouwtekeningen"
- THEN only tasks whose title contains "bouwtekeningen" MUST be shown

---

### REQ-TASK-005: Task Due Dates and Priorities

The system MUST support task due dates and priority levels.

@e2e exclude Due date/priority display and overdue highlighting require existing tasks with specific dates; data-dependent visual tests covered by taskHelpers.js unit tests.

The system MUST support due dates and priority levels on tasks. Overdue tasks MUST be visually highlighted, as implemented in `src/utils/taskHelpers.js`.

**Tier**: MVP

#### Scenario: Set a due date on a task

- GIVEN a task "Controleer bouwtekeningen" without a due date
- WHEN the user sets the due date to "2026-02-26T17:00:00Z"
- THEN the `dueDate` MUST be stored on the task object
- AND the due date MUST be displayed on the task card as "Feb 26"

#### Scenario: Overdue task highlighting

- GIVEN a task "Review documenten" with dueDate "2026-02-20T17:00:00Z" and status `active`
- AND today is February 25, 2026
- THEN the task MUST be visually marked as overdue (red indicator)
- AND the overdue duration MUST be displayed (e.g., "5 days overdue")

#### Scenario: Completed task is not marked overdue

- GIVEN a task "Intake controle" with dueDate "2026-01-15" and status `completed` and completedDate "2026-01-14"
- THEN the task MUST NOT be marked as overdue, even though the due date is in the past
- AND the card MUST show the completion date with a green checkmark

#### Scenario: Task due today indicator

- GIVEN a task "Beoordeel constructie" with dueDate set to today
- AND the task status is `active`
- THEN the task MUST be highlighted with an amber/yellow "Due today" indicator

#### Scenario: Priority affects sort order

- GIVEN the following active tasks:
  - "Draft besluit" with priority `urgent`, dueDate Mar 5
  - "Review documenten" with priority `high`, dueDate Feb 26
  - "Verzamel info" with priority `normal`, dueDate Feb 28
- WHEN the user views the task list sorted by priority
- THEN the order MUST be: "Draft besluit" (urgent), "Review documenten" (high), "Verzamel info" (normal)

---

### REQ-TASK-006: Task Card Display

Task cards MUST display key task information consistently.

@e2e exclude Task card anatomy requires existing tasks to render; data-dependent card display not testable without pre-seeded tasks.

Task cards MUST display key information following a consistent card anatomy across all views.

**Tier**: MVP (list), V1 (kanban cards)

#### Scenario: Task card anatomy in list view

- GIVEN a task with:
  - title: "Review documenten"
  - case: #2024-042 "Bouwvergunning Keizersgracht"
  - dueDate: "2026-02-26"
  - assignee: "jan.devries" (display name "Jan de Vries")
  - priority: `high`
  - status: `active`
- WHEN the task is rendered in the list view
- THEN the card MUST display:
  - The task title "Review documenten" (clickable, navigates to case detail)
  - The parent case reference "Case #2024-042" (clickable, navigates to case)
  - The due date formatted as "Feb 26"
  - The assignee name "Jan" or "Jan de Vries" with avatar
  - A priority badge "high" (orange)

#### Scenario: Unassigned task card

- GIVEN a task "Controleer regelgeving" with no assignee
- WHEN the card is rendered
- THEN the assignee field MUST show a dash "--" or "Unassigned" placeholder
- AND the card MUST still display all other fields normally

#### Scenario: Terminal-state task card styling

- GIVEN a task "Intake controle" with status `completed`
- WHEN the card is rendered
- THEN the card MUST show a completion checkmark or strikethrough styling
- AND terminated tasks MUST show a distinct visual indicator (e.g., grey styling with "Terminated" label)
- AND disabled tasks MUST show a disabled visual state

---

### REQ-TASK-007: Kanban Board View

The system MUST provide a kanban board view for tasks.

@e2e exclude Kanban board is V1; drag-and-drop canvas interactions are not testable in the current Playwright-testable build.

The system MUST provide a kanban board view for tasks, with columns corresponding to CMMN task statuses. The board MUST support drag-and-drop to change task status.

**Tier**: V1

#### Scenario: View tasks as kanban board

- GIVEN tasks exist with statuses: 4 available, 6 active, 12 completed, 1 terminated
- WHEN the user switches to the board view via the "Board | List" toggle
- THEN the system MUST display four columns: "Available" (4 tasks), "Active" (6 tasks), "Completed" (12 tasks), "Terminated" (1 task)
- AND each column header MUST show the task count
- AND tasks within each column MUST be sorted by priority (urgent first) then due date (earliest first)

#### Scenario: Drag task from Available to Active

- GIVEN a task card "Controleer bouwtekeningen" in the "Available" column
- WHEN the user drags the card to the "Active" column
- THEN the system MUST update the task status to `active` via the OpenRegister API
- AND the card MUST move to the "Active" column
- AND the column counts MUST update (Available -1, Active +1)

#### Scenario: Prevent invalid drag (Completed to Active)

- GIVEN a task card "Intake controle" in the "Completed" column
- WHEN the user attempts to drag it to the "Active" column
- THEN the system MUST reject the drop (invalid transition per CMMN lifecycle)
- AND the card MUST snap back to the "Completed" column
- AND a brief error message SHOULD inform the user that completed tasks cannot be reactivated

#### Scenario: Filter kanban by case or assignee

- GIVEN the user selects case filter "Case #2024-042" on the kanban board
- THEN only tasks belonging to case #2024-042 MUST be shown in each column
- AND selecting assignee filter "Jan de Vries" MUST show only tasks assigned to "jan.devries"
- AND the column counts MUST reflect the filtered totals

#### Scenario: Keyboard-accessible status change alternative

- GIVEN a user who cannot use drag-and-drop (keyboard-only navigation)
- WHEN the user focuses on a task card and activates the status change action
- THEN a dropdown or button set MUST appear showing valid status transitions
- AND selecting a transition MUST update the task status identically to drag-and-drop

---

### REQ-TASK-008: Task Completion

The system MUST set the completedDate and enforce lifecycle rules when a task is completed.

@e2e exclude Task completion requires an active task to complete; data-dependent lifecycle flow not testable without pre-seeded tasks.

When a task is completed, the system MUST automatically set the `completedDate` and enforce lifecycle rules.

**Tier**: MVP

#### Scenario: Complete a task and record completion date

- GIVEN a task "Locatiebezoek" with status `active` and no `completedDate`
- WHEN the user marks it as completed at 2026-02-25T14:30:00Z
- THEN the `status` MUST change to `completed`
- AND the `completedDate` MUST be set to "2026-02-25T14:30:00Z"
- AND the task MUST remain visible in the case timeline with a green checkmark

#### Scenario: Attempt to complete an already-completed task

- GIVEN a task "Intake controle" already has status `completed` and completedDate "2026-01-20T10:00:00Z"
- WHEN the user attempts to complete it again
- THEN the system MUST reject the operation (no-op or error)
- AND the `completedDate` MUST remain "2026-01-20T10:00:00Z"

#### Scenario: Task completion updates case progress

- GIVEN case #2024-042 has 5 tasks, 2 of which are completed
- WHEN the user completes a third task
- THEN the case detail Tasks section MUST show updated progress "3/5"

---

### REQ-TASK-009: Task Checklist (Sub-Items)

The system SHALL support checklists within tasks.

@e2e exclude Task checklists are V1; sub-item UI is not yet built in the current Playwright-testable build.

The system SHALL support checklists within tasks for detailed work breakdown. Checklist items are lightweight items stored as part of the task object (not separate OpenRegister objects).

**Tier**: V1

#### Scenario: Add checklist items to a task

- GIVEN a task "Beoordeel aanvraag" with status `active`
- WHEN the user adds checklist items:
  - "Controleer volledigheid formulier"
  - "Verifieer bijlagen"
  - "Check regelgeving"
- THEN the task object MUST store these items as an ordered list
- AND each item MUST have a `checked` boolean (default: false) and a `label` string

#### Scenario: Check off a checklist item

- GIVEN a task with 3 checklist items, all unchecked
- WHEN the user checks "Controleer volledigheid formulier"
- THEN that item's `checked` MUST be set to true
- AND the task card SHOULD show checklist progress (e.g., "1/3")

#### Scenario: Checklist completion does not auto-complete the task

- GIVEN a task with 3 checklist items, all checked
- THEN the task status MUST NOT automatically change to `completed`
- AND the user MUST still explicitly complete the task

---

### REQ-TASK-010: Task Dependencies

The system SHALL support declaring dependencies between tasks.

@e2e exclude Task dependencies are V1; dependency UI is not yet built in the current Playwright-testable build.

The system SHALL support declaring dependencies between tasks ("blocked by" relationships). Dependencies are advisory: they provide visual indicators but do not strictly prevent work.

**Tier**: V1

#### Scenario: Declare a task dependency

- GIVEN task A "Draft besluit" and task B "Review documenten" in case #2024-042
- WHEN the user sets task A as "blocked by" task B
- THEN task A MUST store a reference to task B's UUID as a dependency
- AND task A's card MUST show a "blocked" indicator while task B is not completed

#### Scenario: Dependency resolved when blocking task completes

- GIVEN task A "Draft besluit" is blocked by task B "Review documenten"
- WHEN task B is completed
- THEN task A's "blocked" indicator MUST be removed
- AND task A MUST remain in its current status (the indicator is visual only)

#### Scenario: Prevent circular dependencies

- GIVEN task A is blocked by task B
- WHEN the user attempts to set task B as "blocked by" task A
- THEN the system MUST reject the circular dependency
- AND the error message MUST indicate that a circular dependency was detected

---

### REQ-TASK-011: Task Templates per Case Type

The system SHALL support defining task templates on case types.

@e2e exclude Task templates are V1; template definition UI on case types is not yet built in the current Playwright-testable build.

The system SHALL support defining task templates on case types. When a case of that type is created, the user can choose to instantiate the template tasks.

**Tier**: V1

#### Scenario: Define task template on case type

- GIVEN the admin is editing case type "Omgevingsvergunning"
- WHEN the admin defines a task template with:
  - "Intake controle" (priority: high, relative due: +3 days)
  - "Locatiebezoek plannen" (priority: normal, relative due: +14 days)
  - "Beoordeel aanvraag" (priority: high, relative due: +28 days)
  - "Draft besluit" (priority: urgent, relative due: +42 days)
  - "Verstuur resultaat" (priority: normal, relative due: +56 days)
- THEN the template MUST be saved on the case type configuration

#### Scenario: Apply task template on case creation

- GIVEN case type "Omgevingsvergunning" has 5 template tasks
- WHEN the user creates a new case of this type with start date "2026-03-01"
- THEN the system SHOULD offer to create the template tasks
- AND if accepted, 5 task objects MUST be created, each as an independent OpenRegister object
- AND relative due dates MUST be calculated from the case start date (e.g., "Intake controle" due date = 2026-03-04)
- AND all template tasks MUST be created with status `available`

#### Scenario: Skip task template on case creation

- GIVEN case type "Omgevingsvergunning" has 5 template tasks
- WHEN the user creates a new case and declines the template
- THEN no tasks MUST be created automatically
- AND the user can add tasks manually later

---

### REQ-TASK-012: Automated Task Creation on Case Status Change

The system SHALL support automatically creating tasks on case status change.

@e2e exclude Automated task creation is Enterprise tier; n8n webhook automation is a backend integration not testable via Playwright.

The system SHALL support automatically creating tasks when a case transitions to a specific status. This can be implemented via n8n workflows that listen for case status change events.

**Tier**: Enterprise

#### Scenario: Auto-create tasks on status change

- GIVEN case type "Omgevingsvergunning" has a rule: "When status changes to Besluitvorming, create task 'Draft besluit'"
- WHEN case #2024-042 transitions from "In behandeling" to "Besluitvorming"
- THEN the system MUST automatically create a task "Draft besluit"
- AND the task MUST be linked to case #2024-042
- AND the task MUST inherit default values from the automation rule (assignee, priority, relative due date)

#### Scenario: Auto-created task notification

- GIVEN an automated task creation rule exists
- WHEN the rule fires and creates a task assigned to "jan.devries"
- THEN "jan.devries" SHOULD receive a notification that a task was auto-created
- AND the notification MUST indicate it was system-generated

#### Scenario: Automation rule management

- GIVEN the admin is configuring case type "Omgevingsvergunning"
- WHEN the admin defines an automation rule linking status "Besluitvorming" to task template "Draft besluit"
- THEN the rule MUST be stored as configuration on the case type
- AND the rule MUST specify: trigger status, task title, priority, relative due date, and optional assignee role (e.g., "assign to handler")

---

### REQ-TASK-013: Overdue Task Management

The system MUST provide clear visual indicators for overdue tasks.

@e2e exclude Overdue task indicators require tasks with past due dates; data-dependent visual tests covered by taskHelpers.js unit tests.

The system MUST provide clear visual indicators for overdue tasks and support filtering/sorting by overdue status, as implemented in the My Work view.

**Tier**: MVP

#### Scenario: Overdue task in My Work view

- GIVEN "jan.devries" has an active task "Review documenten" with dueDate "2026-02-20"
- AND today is 2026-02-25
- THEN the task MUST appear in the "Overdue" section of Jan's My Work view
- AND the overdue indicator MUST show "5 days overdue" in red

#### Scenario: Multiple overdue tasks sorted by urgency

- GIVEN "jan.devries" has overdue tasks:
  - "Review documenten" (5 days overdue, priority: high)
  - "Verzamel informatie" (2 days overdue, priority: normal)
  - "Controleer bijlagen" (1 day overdue, priority: urgent)
- WHEN Jan views his My Work
- THEN overdue tasks MUST be sorted by priority first (urgent, high, normal), then by overdue duration (most overdue first within the same priority)
- AND the resulting order MUST be: "Controleer bijlagen", "Review documenten", "Verzamel informatie"

#### Scenario: Terminated or disabled tasks are not shown as overdue

- GIVEN a task "Verouderde controle" with dueDate in the past and status `terminated`
- THEN the task MUST NOT be marked as overdue
- AND the task MUST NOT appear in the overdue section of My Work

---

### Requirement: Task list MUST be reached via Mijn werk, not a sibling top-level menu

The global task list view (`/tasks`) MUST be discoverable through the "Mijn
werk" top-level menu rather than as a sibling top-level menu entry. This
matches the proposed IA placement `Mijn werk › Taken` and removes the
duplicate framing where Tasks and My Work compete as separate "what's on my
plate" entries.

#### Scenario: Tasks does not appear as a top-level menu item

- GIVEN a behandelaar opens the Procest app
- WHEN the left navigation renders
- THEN the top-level menu MUST NOT include an entry labelled "Tasks" /
  "Taken" with a top-level icon
- AND the manifest `menu[]` array MUST NOT contain an entry with
  `id: "Tasks"` outside of the `section: "settings"` group

#### Scenario: Task list is reachable from Mijn werk

- GIVEN a behandelaar is on `/my-work`
- WHEN they look for the global task list
- THEN the `MyWork` view MUST surface an explicit affordance (tab, link, or
  button) labelled "Taken" / "Tasks" that navigates to `/tasks`
- AND the affordance MUST be discoverable above the fold

#### Scenario: Existing /tasks deep links continue to resolve

- GIVEN a stored bookmark or external link points to `/tasks`
- WHEN a user opens that URL
- THEN the route MUST still resolve to the existing `Tasks` index page (the
  manifest `pages[]` entry with `id: "Tasks"` is preserved)
- AND no 404 or redirect MUST occur

<!--
  Note (2026-06-13, archive sweep): the original delta carried a
  `## REMOVED Requirements` block for "Tasks MUST appear as a top-level
  navigation entry". The canonical `openspec/specs/task-management/spec.md`
  never expressed that as a formal `### Requirement:` — it was only an
  implicit assumption in the manifest `menu[]` order. There is no formal
  requirement to remove, so the REMOVED block was dropped to let the change
  archive cleanly; the new ADDED requirement above fully supersedes the old
  navigation placement. The manifest `menu[]` Tasks entry was already removed
  in the implementation (tasks 1.x) and the `/tasks` page route is preserved
  for deep links.
-->

## Accessibility

All task management interfaces MUST comply with WCAG AA:

- Task cards MUST have sufficient color contrast for all text and indicators
- Overdue/priority indicators MUST NOT rely solely on color (use icons and text labels)
- Kanban drag-and-drop MUST have a keyboard-accessible alternative (e.g., dropdown to change status)
- Task list MUST be navigable by keyboard (Tab to move between rows, Enter to open)
- Screen readers MUST be able to identify task status, priority, and overdue state

---

## Performance

- The task list MUST load within 2 seconds for up to 100 tasks
- The kanban board MUST render within 2 seconds for up to 50 cards per column
- Drag-and-drop status changes MUST provide optimistic UI updates (move the card immediately, then confirm with the API)
- The My Work view MUST aggregate tasks and cases in a single page load (parallel API calls)

---

### Current Implementation Status

**MVP substantially implemented. V1/Enterprise features not implemented.**

**Implemented (with file paths):**
- **Task schema**: Defined in `lib/Settings/procest_register.json` with properties: `title`, `description`, `status` (enum: available/active/completed/terminated/disabled), `assignee`, `case` (UUID ref), `dueDate`, `priority` (enum: low/normal/high/urgent), `completedDate`. Matches the spec data model exactly.
- **Task lifecycle**: `src/utils/taskLifecycle.js` implements the full CMMN PlanItem lifecycle with:
  - `TASK_STATUSES` constant object
  - `TRANSITION_MAP`: available -> [active, terminated, disabled], active -> [completed, terminated], completed/terminated/disabled -> [] (terminal)
  - `getAllowedTransitions(currentStatus)`, `validateTransition(from, to)`, `getStatusLabel(status)`, `getTransitionLabel(targetStatus)`, `isTerminalStatus(status)` functions
  - Localized status labels (English, Dutch pending)
- **Task helpers**: `src/utils/taskHelpers.js` provides utility functions for task display and overdue calculations.
- **Task list / detail views**: rendered by the CnAppRoot manifest shell (REQ-TASK-004). No `src/views/tasks/TaskList.vue` / `TaskDetail.vue` SPA view files exist (corrected 2026-06-01); list/detail are manifest-driven pages, with `TaskCreateDialog.vue` and `taskValidation.js` as the real supporting code.
- **Task create dialog**: `src/views/tasks/TaskCreateDialog.vue` -- form for creating new tasks linked to a case.
- **Task API service**: `src/services/taskApi.js` -- `fetchTasksForCases()` for CalDAV task integration.
- **Task CRUD**: Via the shared object store (`src/store/modules/object.js`) for OpenRegister-based tasks.
- **My Work integration**: `src/views/MyWork.vue` includes tasks in the unified view with overdue highlighting, priority indicators, grouped sections (REQ-TASK-013).
- **Dashboard widgets**: `lib/Dashboard/MyTasksWidget.php` and `src/views/widgets/MyTasksWidget.vue` -- Nextcloud dashboard widget showing assigned tasks. `src/views/dashboard/MyWorkPreview.vue` shows task summary on app dashboard.
- **Navigation**: The global task list is reached via Mijn werk, not as a sibling top-level menu entry. The `Tasks` entry has been removed from `src/manifest.json` `menu[]`; the `pages[]` entry for `id: "Tasks"` is preserved so that `/tasks` continues to resolve for deep links and `CaseTasksTab` navigation. `src/views/MyWork.vue` surfaces an explicit "All tasks" (`Alle taken`) button that navigates to `/tasks`.
- **Router**: `src/router/index.js` includes routes for `/tasks`, `/tasks/new`, and `/tasks/:id`.
- **Overdue highlighting**: Implemented in `MyWork.vue` with red indicators and "X days overdue" text (REQ-TASK-005, REQ-TASK-013).
- **Priority badges**: Priority indicators shown in My Work view for high and urgent priorities.

**Architecture note:** Tasks have a dual implementation:
1. OpenRegister `task` schema objects (used by the object store for CRUD)
2. CalDAV tasks via `fetchTasksForCases()` in `src/services/taskApi.js` (used by My Work view)

This duality means some views use OpenRegister tasks while others use CalDAV tasks. The spec assumes a single OpenRegister-based task system.

**Not yet implemented:**
- **REQ-TASK-007: Kanban board view (V1)**: No kanban/board view with columns per status. No drag-and-drop status transitions. No board/list toggle.
- **REQ-TASK-009: Task checklists (V1)**: No checklist/sub-item support on tasks.
- **REQ-TASK-010: Task dependencies (V1)**: No "blocked by" relationship support.
- **REQ-TASK-011: Task templates per case type (V1)**: No task template configuration on case types. No auto-creation of template tasks on case creation.
- **REQ-TASK-012: Automated task creation on status change (Enterprise)**: No automation rules for task creation.
- **Task assignment notifications**: No Nextcloud notifications sent when tasks are assigned or reassigned.
- **Task search by title**: Basic search may exist via the object store's `_search` parameter, but dedicated task search UI is not prominent.
- **Keyboard navigation**: No explicit keyboard navigation support in task list or cards.
- **Screen reader support**: No ARIA attributes for task status, priority, or overdue state.

### Standards & References

- **CMMN 1.1**: Task lifecycle states (Available, Active, Completed, Terminated, Disabled) follow the CMMN PlanItem lifecycle exactly. Transition rules match CMMN specification. Implemented in `src/utils/taskLifecycle.js`.
- **Schema.org**: Tasks typed as `schema:Action` with `actionStatus` in `procest_register.json`.
- **BPMN 2.0**: Task patterns for assignment and lifecycle management.
- **ZGW APIs**: No direct ZGW equivalent for tasks (ZGW does not define a task resource), but tasks complement the ZGW Zaak lifecycle. Dimpact ZAC uses Flowable tasks via its own REST API.
- **WCAG 2.1 AA**: Spec requires color-independent indicators and keyboard accessibility. Partially implemented (text labels for overdue, but no keyboard nav).
- **Competitive reference**: Flowable (unified task service with 5-state lifecycle, delegation, sub-tasks), Dimpact ZAC (Flowable-backed tasks with WebSocket updates), xxllnc Zaken (phase-bound tasks), ArkCase (Activiti tasks with Drools routing).

### Specificity Assessment

- **MVP requirements are well-specified and mostly implemented.** The task CRUD, lifecycle, assignment, list view, and overdue management are clear and actionable.
- **V1 features (kanban, checklists, dependencies, templates) are well-specified** with concrete scenarios but not yet implemented.
- **Task architecture ambiguity**: The dual CalDAV/OpenRegister task system needs resolution. The spec assumes OpenRegister-only tasks.
- **Open questions:**
  - Should tasks migrate fully to OpenRegister, or should CalDAV integration be maintained for Nextcloud ecosystem compatibility?
  - How should kanban drag-and-drop handle the keyboard-accessible alternative (dropdown vs. move buttons)?
  - Should task templates be stored as JSON arrays on the case type object or as separate OpenRegister objects?
  - How should task dependencies be stored (array of UUIDs on the task object, or separate relation objects)?
