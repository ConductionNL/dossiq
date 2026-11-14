# Design: checklist-per-status

## Context

`statusType` (`lib/Settings/dossiq_register.json`) carries `name`,
`description`, `caseType`, `order`, `isFinal` and `role`. A transition on a
workflow template carries `automaticActions`, and
`StatusTransitionService::execute` runs them through `SideEffectDispatcher`
after the status write; `executeFreeForm` (admin only) writes the status and
runs nothing. `CreateTaskHandler` takes
`{type: 'createTask', title?, assignee?, dueIn?}` and saves a `caseTask` with
status `available`. `caseTask.workflowStepId` is free text meant to name the
thing that generated the task. `CaseDetail` (`src/manifest.json`) shows the
case's tasks in the `case-tasks` object-list widget. `GuardRegistry` holds the
four guard evaluators the transition buttons report on.

ADR-032 kind: **config**. The surface is a schema property and seed data; the
engine gains one reader and one guard, each under fifty lines, so that the
existing handler does the work.

## Goals / Non-Goals

**Goals:**

- The case type says what each status asks for, in one list per status.
- A case that reaches a status gets that list as tasks, once.
- A required item keeps the case in its status until it is done, and the
  handler can read which one.

**Non-Goals:**

- A form to edit the list on the case type page. That is the sub-object
  table widget `case-type-one-authoring-surface` waits on (triage #7).
- Per-item assignees, roles or deadlines. `title` and `required` first.
- Sub-items inside a task (`caseTask.checklist` stays what it is).

## Decisions

### D1: the list lives on the status, not on the transition

`statusType.checklist` is
`{"type": "array", "items": {"type": "object", "properties": {"title": {"type": "string"}, "required": {"type": "boolean", "default": false}}, "required": ["title"]}}`.
A transition's `automaticActions` was the alternative and was rejected: a
status can be reached over more than one transition and by an admin's
free-form move, and the work belongs to the phase, not to the road into it.
English identifiers per decisions D13.

### D2: the engine expands the list into `createTask` actions

`StatusChecklist::actionsFor(statusTypeId, case)` reads the target status
through `StatusTypeLookup` and returns one
`{type: 'createTask', title: <item.title>}` per item, prepended to the
transition's own actions so `SideEffectDispatcher` runs them in order.
`executeFreeForm` calls the same method, so an admin move brings the tasks
too. `CreateTaskHandler` learns one field: `workflowStepId`, set to the
status type's uuid, so the task says which status made it.

### D3: entering a status twice creates nothing twice

Before yielding an action `StatusChecklist` reads the case's tasks with
`workflowStepId` equal to the status uuid. An item whose title already has a
task on the case, open or completed, is skipped. A case sent back to Intake
and forward again keeps its Intake tasks and gets no second set.

### D4: a required item is a guard on leaving

`StatusChecklistGuard` is registered in `GuardRegistry` under the type
`statusChecklist` and is evaluated on every transition, not only where a
template names it. It loads the current status's items with `required: true`
and their tasks on the case; one task not at `completed` fails the guard with
the message "Checklist item not done: <title>". `getAvailableTransitions`
reports it as a failed guard, so the case page's transition buttons show the
reason the same way they show a missing document. A required item without a
task on the case counts as not done: that is the free-form path before D2
ran, and it must not open a hole.

### D5: the case page does not change

The `case-tasks` object-list on `CaseDetail` filters on the case and shows
the new tasks with the rest. `task-on-the-case` (A06) restyles that pane; this
change adds no widget.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| The list per status | declarative, a schema property | Data on the case type. |
| Tasks on entry | imperative, `StatusChecklist` feeding the existing handler | The engine already dispatches actions after the status write; this adds a source. |
| Required item holds the status | imperative, a guard evaluator | The guard registry is where the buttons read their reasons. |

## Seed Data

- `lib/Settings/register.d/`: the bezwaar case type's Intake status gets
  `checklist: [{title: "Check the objection is on time", required: true}, {title: "Confirm receipt to the objector", required: false}]`
  and In behandeling gets one required item, so the demo shows the tasks
  arriving and a button held back.

## Risks / Trade-offs

- The guard runs a task search on every button render. The search is one
  filtered call per case, the same cost `ChecklistGuard` already pays.
- D3 matches on title. Renaming an item on the case type after cases reached
  the status creates the renamed one on the next entry. Accepted; the uuid
  of a status is stable, the wording of an item is not, and a duplicate task
  is cheaper than a missed one.
- `executeFreeForm` never dispatched actions. It now dispatches the checklist
  ones only, not the transition's, since it has no transition.
