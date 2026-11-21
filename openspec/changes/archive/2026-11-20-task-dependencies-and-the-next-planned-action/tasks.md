# Tasks: task-dependencies-and-the-next-planned-action

Tier: V1. Kind: code. Size M. Rows 3.27, 3.28 and 3.29.

- [x] 1.1 `lib/Service/Milestone/MilestoneService.php`: read
  `milestoneDefinition.dependsOn` and compute the date as an offset from the
  named item; keep the cumulative sum for an item that names none (D-1, D-2).
  - `@spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md`
  - `tests/unit/Service/Milestone/DependsOnOffsetTest.php`
- [x] 1.2 Moving a predecessor moves every item downstream of it, on the
  working calendar (D-1).
  - `tests/unit/Service/Milestone/CascadeOnMoveTest.php`
- [x] 1.3 Refuse a cycle when the case type is saved, naming the items in it
  (D-3).
  - `tests/unit/Service/Milestone/CycleRefusedTest.php`
- [x] 1.4 An assignee on `milestoneDefinition`, resolved from a role on the
  case where it names one (D-4).
  - `tests/unit/Service/Milestone/MilestoneAssigneeTest.php`
- [x] 2.1 `lib/Settings/dossiq_register.json`: the planned action with a
  type, an owner, a date and a state, and the successor declared on the type
  (D-5).
  - `@spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md`
  - `tests/unit/Service/PlannedActionServiceTest.php`
- [x] 2.2 Completing an action schedules the one its type declares; a type
  with no successor ends the chain (D-6).
  - `tests/unit/Service/PlannedActionChainTest.php`
- [x] 2.3 Show the next planned action on the case and on the work list.
  - `tests/vitest/caseNextAction.spec.js`
**3.1 to 3.3 are the ENGINE's half, built in openregister rather than here.** Row 3.29
is about a TASK, and since `remove-casetask` dossiq has no task table: the
Tasks page is the engine's inbox (`entitySource: "tasks"`), the due list is
built by the engine, and the close event is the engine's. dossiq can neither
derive a state on a record it does not own nor keep a row out of a list it
does not build, so shipping a dossiq-side blocked flag would be a field the
engine never reads, which is the exact failure `dependsOn` was. It belongs in
openregister beside `rbac-inherits-to-children`.

- [x] 3.1 A task may declare the task that blocks it; the blocked state is
  derived, never stored by hand (D-7).
  - Built in openregister (main, change `a-task-may-wait-on-another-task`): a task's `blockedBy`,
    and `TaskBlockerGuard::refuseHandWrittenState()` refuses a hand-written state in `TaskService::create()`.
  - openregister `tests/Unit/Db/TaskBlockedByTest.php`, `tests/Unit/Service/Task/TaskBlockedByCreateTest.php`
- [x] 3.2 A blocked task leaves the assignee's due list and returns when the
  blocker closes (D-7).
  - openregister main: `TaskMapper::applyBlockedExclusion()` keeps a task out of a list while its
    `blockedBy` names an open task; once the blocker is terminal it is listed again.
- [x] 3.3 Release dependents on the blocker's close event, placed as
  post-event work under ADR-078 (D-8).
  - openregister main: `lib/Listener/TaskBlockerReleaseListener.php` over `TaskBlockerMapper`; openregister `tests/Unit/Listener/TaskBlockerReleaseListenerTest.php`.
- [x] 4.1 Dutch and English strings for the predecessor field, the planned
  action types and states, and the blocked label.
- [x] 4.2 `tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts`:
  a milestone that moves with its predecessor, a chain of two planned actions,
  a blocked task that releases itself;
  `openspec validate task-dependencies-and-the-next-planned-action --type change --strict`.
