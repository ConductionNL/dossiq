# Tasks: task-as-a-first-class-record

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 53, candidates
C-tasks-and-phases-10, C-tasks-and-phases-11, C-tasks-and-phases-13,
C-tasks-and-phases-14, C-configuration-56, C-tasks-and-phases-16 and
C-tasks-and-phases-2. C-tasks-and-phases-33 is buildiq's. Decision D6
admits all five dossiq `must` candidates although the cluster has three
driven passers. The task record is OpenRegister's; the claim act and the
task number wait on openregister, and the list row action on
nextcloud-vue.

- [x] 1.1 `caseType.workflowDefinition`: one declaration block per task
  with on or off, the form, the candidate group, the lead time and the
  effects (D-2).
  - `tests/unit/Service/PerTaskConfigurationTest.php`
  - `@spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md`
- [x] 1.2 `case-type-publish-validation`: refuse a task naming a form, a
  group or an effect handler that cannot be resolved (D-2, D-5).
- [x] 2.1 The always-available acts list on the case type, rendered beside
  the phase's acts and marked, out of the phase strip, the progress figure
  and the term calculation (D-3).
  - `tests/vitest/alwaysAvailableActs.spec.js`
- [x] 3.1 Declare candidate groups and users per task; render the claim
  affordance only when the engine answers one, and say on the case type
  screen when it does not (D-4).
  - `tests/unit/Service/TaskCandidatesTest.php`
- [x] 3.2 Hand openregister the claim act with candidate id
  C-tasks-and-phases-11, and the task number and lock with
  C-tasks-and-phases-16.
- [x] 4.1 Task effects as named handlers from `ActionHandlerRegistry`, run
  on completion, with resume among them (D-5).
  - `tests/unit/Service/TaskEffectsTest.php`
- [x] 4.2 Refuse a completion whose declared handler cannot be resolved,
  per ADR-102 (D-5).
- [x] 5.1 Widen `src/components/tasks/CaseTaskPane.vue` from the first open
  task to every open task on the case, completing in place (D-6).
  - `tests/vitest/caseTaskPaneAllOpen.spec.js`
- [x] 5.2 Show the task's form in place and refuse a completion with a
  required field empty, naming it per ADR-050 (D-6).
- [x] 5.3 Name nextcloud-vue `working-list-row-actions` as the half the
  list surface waits on, with candidate id C-tasks-and-phases-14.
- [x] 6.1 Bind an upload to the open task, removable while open, published
  to the case on completion with the task recorded (D-7).
  - `tests/unit/Service/TaskAttachmentTest.php`
- [x] 7.1 Show the task's own number, due date and lock wherever it is
  shown, and state rather than invent a missing number or lock (D-1).
  - `tests/vitest/taskIdentity.spec.js`
- [x] 7.2 Dutch and English strings.
- [x] 7.3 `tests/e2e/task-as-a-first-class-record.spec.ts`: a task with its
  own lead time, a task switched off for one case type, five acts in two
  halves, an unclaimed task listed for a group, a completion that sends
  and one that resumes a term, the second open task completed on the case,
  a form filled in place, and a file published to the case on completion;
  `openspec validate task-as-a-first-class-record --strict`.

## What was built, and what was left to openregister

**Built here.** 1.1 reads the per-task block off the workflow step
(`TaskDeclaration`, `TaskDeclarationReader`); 1.2 refuses an unresolvable
form, group or effect at publish and names the task
(`TaskDeclarationValidator`, wired into `WorkflowDefinitionService::publish`);
2.1 declares the always-available acts on the case type and answers them
beside the phase's own, in ONE menu: `lifecycle-acts-on-the-case` (#2831)
landed the single lifecycle menu while this change was in flight and already
read `GET /api/case/{id}/acts`, so `AlwaysAvailableActs` answers there and
`buildActsMenu()` folds the half in, marked `kind: 'always'`. The separate
pane this change first built was deleted rather than merged: two surfaces
answering "what may I do" is what both designs argue against; 3.1 writes the declared candidates to the engine's
own candidate columns and renders the claim affordance only where the engine
answers a claim act; 4.1 and 4.2 run the declared effects on the engine's
terminal event and refuse a completion whose handler cannot be resolved
(`TaskEffects`, `TaskCompletionEffectsListener`, `CaseTaskCompletion`),
with `resumeTerm` among the effects; 5.1 and 5.2 widen the pane to every open
task and show the task's form in place, refusing a blank required field by
name; 6.1 holds an upload against the open task and publishes it to the case
on completion, recording the task (`TaskAttachmentService`); 7.1 shows the
task's reference, due date and lock, and states a missing number rather than
inventing one; 7.2 ships the Dutch and English strings.

**Read against openregister on 2026-09-15, and the proposal is out of date in
dossiq's favour.** The claim act EXISTS: `TaskService::claim()`, routed at
`POST /api/flow-tasks/{uuid}/claim`, authorized against the candidate pool,
and `candidateUsers` / `candidateGroups` are columns on the task. So 3.2's
claim half needs no openregister change, and dossiq asks the engine at run
time rather than assuming either answer. `flow-task-forms` is shipped too:
`metadata.form` is the declaration a run-less task carries and
`TaskFormCompletion` validates a payload against it, which is why dossiq
declares the form in the engine's own shape and owns no second one.

**Still openregister's, and not shadowed here (3.2):**

- A task NUMBER and a task LOCK. Neither is a column on the engine's task,
  so the pane shows the engine identifier and says there is no number yet.
- A seam that adds a file to an OPEN task. `evidence` is writable only
  through `create`, `import` and `complete`, so the hold lives on the case in
  `case.taskAttachments` until the task completes.

**Still nextcloud-vue's (5.3):** `working-list-row-actions`, candidate
C-tasks-and-phases-14. `CnObjectListWidget` still has no row action, so the
in-place completion is offered on the case page and not yet inside a list
row. `CaseTaskPane.vue` remains the interim its own header describes.
