# Tasks: task-dependencies-and-the-next-planned-action

Tier: V1. Kind: code. Size M. Rows 3.27, 3.28 and 3.29.

- [ ] 1.1 `lib/Service/Milestone/MilestoneService.php`: read
  `milestoneDefinition.dependsOn` and compute the date as an offset from the
  named item; keep the cumulative sum for an item that names none (D-1, D-2).
  - `@spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md`
  - `tests/unit/Service/Milestone/DependsOnOffsetTest.php`
- [ ] 1.2 Moving a predecessor moves every item downstream of it, on the
  working calendar (D-1).
  - `tests/unit/Service/Milestone/CascadeOnMoveTest.php`
- [ ] 1.3 Refuse a cycle when the case type is saved, naming the items in it
  (D-3).
  - `tests/unit/Service/Milestone/CycleRefusedTest.php`
- [ ] 1.4 An assignee on `milestoneDefinition`, resolved from a role on the
  case where it names one (D-4).
  - `tests/unit/Service/Milestone/MilestoneAssigneeTest.php`
- [ ] 2.1 `lib/Settings/dossiq_register.json`: the planned action with a
  type, an owner, a date and a state, and the successor declared on the type
  (D-5).
  - `@spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md`
  - `tests/unit/Service/PlannedActionServiceTest.php`
- [ ] 2.2 Completing an action schedules the one its type declares; a type
  with no successor ends the chain (D-6).
  - `tests/unit/Service/PlannedActionChainTest.php`
- [ ] 2.3 Show the next planned action on the case and on the work list.
  - `tests/vitest/caseNextAction.spec.js`
- [ ] 3.1 A task may declare the task that blocks it; the blocked state is
  derived, never stored by hand (D-7).
  - `tests/unit/Service/TaskBlockedStateTest.php`
- [ ] 3.2 A blocked task leaves the assignee's due list and returns when the
  blocker closes (D-7).
- [ ] 3.3 Release dependents on the blocker's close event, placed as
  post-event work under ADR-078 (D-8).
  - `tests/unit/Listener/TaskReleaseListenerTest.php`
- [ ] 4.1 Dutch and English strings for the predecessor field, the planned
  action types and states, and the blocked label.
- [ ] 4.2 `tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts`:
  a milestone that moves with its predecessor, a chain of two planned actions,
  a blocked task that releases itself;
  `openspec validate task-dependencies-and-the-next-planned-action --type change --strict`.
