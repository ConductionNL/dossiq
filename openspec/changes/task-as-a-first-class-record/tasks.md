# Tasks: task-as-a-first-class-record

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 53, candidates
C-tasks-and-phases-10, C-tasks-and-phases-11, C-tasks-and-phases-13,
C-tasks-and-phases-14, C-configuration-56, C-tasks-and-phases-16 and
C-tasks-and-phases-2. C-tasks-and-phases-33 is buildiq's. Decision D6
admits all five dossiq `must` candidates although the cluster has three
driven passers. The task record is OpenRegister's; the claim act and the
task number wait on openregister, and the list row action on
nextcloud-vue.

- [ ] 1.1 `caseType.workflowDefinition`: one declaration block per task
  with on or off, the form, the candidate group, the lead time and the
  effects (D-2).
  - `tests/unit/Service/PerTaskConfigurationTest.php`
  - `@spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md`
- [ ] 1.2 `case-type-publish-validation`: refuse a task naming a form, a
  group or an effect handler that cannot be resolved (D-2, D-5).
- [ ] 2.1 The always-available acts list on the case type, rendered beside
  the phase's acts and marked, out of the phase strip, the progress figure
  and the term calculation (D-3).
  - `tests/vitest/alwaysAvailableActs.spec.js`
- [ ] 3.1 Declare candidate groups and users per task; render the claim
  affordance only when the engine answers one, and say on the case type
  screen when it does not (D-4).
  - `tests/unit/Service/TaskCandidatesTest.php`
- [ ] 3.2 Hand openregister the claim act with candidate id
  C-tasks-and-phases-11, and the task number and lock with
  C-tasks-and-phases-16.
- [ ] 4.1 Task effects as named handlers from `ActionHandlerRegistry`, run
  on completion, with resume among them (D-5).
  - `tests/unit/Service/TaskEffectsTest.php`
- [ ] 4.2 Refuse a completion whose declared handler cannot be resolved,
  per ADR-102 (D-5).
- [ ] 5.1 Widen `src/components/tasks/CaseTaskPane.vue` from the first open
  task to every open task on the case, completing in place (D-6).
  - `tests/vitest/caseTaskPaneAllOpen.spec.js`
- [ ] 5.2 Show the task's form in place and refuse a completion with a
  required field empty, naming it per ADR-050 (D-6).
- [ ] 5.3 Name nextcloud-vue `working-list-row-actions` as the half the
  list surface waits on, with candidate id C-tasks-and-phases-14.
- [ ] 6.1 Bind an upload to the open task, removable while open, published
  to the case on completion with the task recorded (D-7).
  - `tests/unit/Service/TaskAttachmentTest.php`
- [ ] 7.1 Show the task's own number, due date and lock wherever it is
  shown, and state rather than invent a missing number or lock (D-1).
  - `tests/vitest/taskIdentity.spec.js`
- [ ] 7.2 Dutch and English strings.
- [ ] 7.3 `tests/e2e/task-as-a-first-class-record.spec.ts`: a task with its
  own lead time, a task switched off for one case type, five acts in two
  halves, an unclaimed task listed for a group, a completion that sends
  and one that resumes a term, the second open task completed on the case,
  a form filled in place, and a file published to the case on completion;
  `openspec validate task-as-a-first-class-record --strict`.
