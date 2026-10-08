---
kind: code
depends_on: []
---

# Proposal: task-dependencies-and-the-next-planned-action

## The rows this closes

**3.27**, area Tasks and phases, rated `partial`: "Task timeline where each
item is offset from a named earlier item."

Source field, verbatim: `dossiq#2314, published as 3.24`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.27** | 3.24 | Task timeline where each item is offset from a named earlier item | partial | unread |  |
```

The ledger note, verbatim:

> milestoneDefinition declares dependsOn and nothing reads it: offsets are a cumulative sum from the case start ordered by a number. Neither milestoneDefinition nor deadlineInstance carries an assignee, so nothing resolves one from a role on the case.

**3.28**, area Tasks and phases, rated `no`: "Planned next action of a type,
with an owner, chaining the one after it."

Source field, verbatim: `dossiq#2314, published as 3.25`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.28** | 3.25 | Planned next action of a type, with an owner, chaining the one after it | no | unread |  |
```

The ledger note, verbatim:

> Row 8.4 reminders fire at a date. A planned next action is a typed record with an owner that, on completion, schedules the one that follows it.

**3.29**, area Tasks and phases, rated `no`: "Task blocked by another task,
released by the system when that one closes."

Source field, verbatim: `dossiq#2314, published as 3.26`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.29** | 3.26 | Task blocked by another task, released by the system when that one closes | no | unread |  |
```

The ledger note, verbatim:

> Tasks carry due dates and assignees, not dependencies. Nothing derives a blocked state and nothing releases it without a person noticing.

## What the competitor evidence is

None for any of the three. All three are among the 98 rows promoted under
decision D1, whose batch file states: "Every competitor column is `unread`,
and none of them is `no`. ... `no` is a reading of a product somebody
opened, and filling these cells with it would fabricate thirty readings per
row."

## Why

Work on a case is a sequence, and dossiq models it as a pile.

**The timeline has no predecessors.** `milestoneDefinition` declares
`dependsOn` and nothing reads it. The offsets are a cumulative sum from the
case start, ordered by a number. So a milestone that should be two weeks
after the hearing is two weeks after an estimate of the hearing, and when
the hearing moves, nothing else does. Neither `milestoneDefinition` nor
`deadlineInstance` carries an assignee either, so a timeline is a set of
dates nobody owns.

**There is no next action.** A reminder fires at a date, which
`case-reminder-as-task` is adding for a named colleague. A planned next
action is a different thing: a typed piece of work, with an owner, whose
completion schedules the one that follows. It is how a case with a long
sequence of contacts is run, and it is the record that answers "what happens
next on this case" without reading the whole file.

**Nothing blocks.** Tasks carry due dates and assignees and no dependencies.
So a task that cannot start yet sits in somebody's list looking like work
they are ignoring, and the task that unblocks it releases nothing when it
closes. The state is derived nowhere and cleared by a person noticing.

## What changes

- `dependsOn` is read. A milestone's date is an offset from the item it
  names, and moving the predecessor moves everything downstream of it. The
  cumulative sum from the case start stays the behaviour for an item that
  names no predecessor.
- A cycle in the dependencies is refused when the case type is saved, not
  discovered at runtime.
- A milestone carries an assignee, resolved from a role on the case where it
  names one, so a date on a timeline has an owner.
- A planned next action: a typed record with an owner, a date and a state,
  which on completion schedules the action its type declares as the one that
  follows. A chain ends where the type declares no successor.
- The case shows its next planned action, so the question is answered
  without reading the history.
- A task may declare that it is blocked by another task. The blocked state
  is derived, the task stays out of the assignee's due list while it holds,
  and closing the blocker releases it without anybody looking.

## Ownership

dossiq builds the milestone reading, the planned action and the task
dependency. What follows what on a zaak is case administration.

Consumed:
- openregister engine tasks (shipped) for the task itself, its assignee and
  its due window, so a blocked task is a declared state on the engine's task
  rather than a dossiq task table. dossiq's `remove-casetask` is what makes
  that true and this change assumes it;
- openregister `flow-business-timers` (shipped) for the date a planned action
  is scheduled on;
- openregister `working-calendar-admin`, to be specified in openregister
  (register row 8.12), for the calendar the offsets count on, which dossiq
  consumes through `terms-on-the-engine-calendar`;
- openregister RBAC roles (shipped) for resolving an assignee from a role on
  the case.

## ADRs

- Company ADR-022: the task, the timer and the roles are the platform's.
- Company ADR-031: the offset, the successor and the blocking relation are
  declared on the definition, not computed in a service per case type.
- Company ADR-038 for the requirement ids.
- Company ADR-078: releasing a blocked task happens on a post-event and is
  placed accordingly, so closing a task never waits for its dependents.

## Size

M. One declaration read that already exists, one new record and one derived
state.

## The existing spec this extends

`milestone-tracking` for the timeline, and `task-management`, which carries
REQ-TASK-001 to REQ-TASK-021 including `case-reminder-as-task`'s
REQ-TASK-020.

## Out of scope

- The reminder for a colleague, which is `case-reminder-as-task`
  REQ-TASK-020. A reminder fires at a date and chains nothing.
- A recurring planned case, which is `planned-case-series`. That repeats a
  case; this chains actions inside one.
- A term that moves because another case's term moved, which is
  `dependent-term-follows-predecessor` REQ-RCL-11. That is between cases.
- The statutory term itself, which is `termijnbewaking-op-engine-timers`.
