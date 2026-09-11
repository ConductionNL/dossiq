# Tasks: remove-casetask

**70 files reference the slug**, measured 2026-09-10 by
`git grep -il caseTask -- lib src tests`. They are listed here by name, not by
count, because the Remove step is only done when the grep returns nothing and
a number cannot be checked off.

Order matters. The schema is deleted LAST, in its own commit, after every
surface is green. Until then the backfill stays re-runnable and the register
object is the fallback.

## 1. The six read surfaces

Each moves from `useObjectStore` over `caseTask` to `useEngineTaskStore`.
Each gets its unit test updated and its e2e assertion checked BEFORE the next
one starts.

- [x] 1.1 `src/components/tasks/CaseTaskPane.vue` — reads
      `objectStore.fetchCollection('caseTask', openTasksQuery(...))`. Becomes
      `engineTasks.openForCase(caseId)`, which already filters terminal rows.
      Its lifecycle buttons move from the register's transitions to the
      engine's verbs (`invoke(uuid, 'complete')`).
- [x] 1.2 `src/utils/caseTaskPaneHelpers.js` — `FINAL_TASK_STATUSES` is the
      same three states the engine calls terminal. Delete it and use
      `isTerminal` from the engine store, rather than keeping a second copy
      that can drift.
- [x] 1.3 `src/components/tabs/CaseTasksTab.vue` — the sidebar list.
- [x] 1.4 `src/views/widgets/MyTasksWidget.vue` — `scope: 'assigned'` here,
      unlike the case surfaces: this one IS the reader's own list.
- [x] 1.5 `src/views/widgets/TaskRemindersWidget.vue` — needs `overdue`,
      which the engine's inbox filter already supports, so dossiq stops
      deriving overdue-ness itself.
- [x] 1.6 `src/components/flow/TaskWaitingCaseSection.vue` and
      `src/components/tasks/TaskCaseCard.vue` — both read a task to find its
      case. The engine's `objectUuid` IS the case, so these get simpler.

### Section 1 was already green

Measured 2026-09-10: all six surfaces import `useEngineTaskStore` and no
`fetchCollection('caseTask', ...)` survives in `src/`. The boxes were never
ticked, not the work left undone. The two surfaces that still import
`useObjectStore` (`TaskWaitingCaseSection`, `TaskCaseCard`) use it for the
CASE object, which is correct: the case is still an OpenRegister object.

One `caseTask` WRITE did survive the sweep, in a place nothing read:
`workflow.js`'s `dispatchCreateTaskAction`. A task written there succeeded,
the transition reported success, and the task was invisible to every one of
the six surfaces above -- an object write producing a task nobody sees and
no error anywhere. It now writes the engine and THROWS on refusal, because
`dispatchActions` records a per-action result the user is shown.

## 2. The two pages

**CORRECTED 2026-09-10. The index does NOT need a custom page**, and the
first version of this plan was wrong to say so.

`@conduction/nextcloud-vue` already ships a `tasks` **entity source**
(`src/composables/indexSources.js`) — a third index mode for exactly this,
"a list that is neither an OpenRegister object nor rows a parent holds". It
supplies columns, badge rendering, scope tabs and row navigation. So the
page stays `type: "index"` and gains `entitySource: "tasks"`.

Three gaps stood between that and dossiq using it. All three are now open
upstream, and none needs a custom page:

| Gap | Why dossiq hit it | Where |
|---|---|---|
| The source's `openRow` always wins | It navigates to OPENREGISTER's task page, and dossiq keeps its own. `row-click` cannot recover it: `openRow` calls `window.location.assign()`, so a host's push never lands | nextcloud-vue#1063, `rowRoute` |
| `isTerminal` not on the store allowlist | Two of the six lenses ARE that filter. The server always accepted it | nextcloud-vue#1063 |
| No due-window filter | "Due this week" had no server-side answer at all | openregister#3581, then nextcloud-vue#1063 |

- [x] 2.1 `TaskDetail` (`/tasks/:id`). Still a real change: `type: "detail"`
      binds a register and a schema, and there is no detail-page equivalent
      of `entitySource`. **The route, the page id and the deep links must not
      change**, so notification links and bookmarks survive. It keeps the
      case card, the notes and appointment leaves and the lifecycle buttons.
      Check `lib/Service/DeepLink*` and the notification templates resolve.

      DONE. The page is `type: "custom"` over `TaskDetailView`, with the
      route and the page id untouched. `lib/Service/DeepLink*` does not
      exist in this repo and no PHP builds a task URL: the published link is
      the manifest `deepLinks` entry `/apps/dossiq/tasks/{uuid}`, which the
      SPA resolves by route. Two tests hold that shape,
      `manifestCaseTaskPane.spec.js` and `searchableSchemas.spec.js`, and
      both were mutation-checked by moving the route and by moving the
      template.

      The three leaves moved with it. Notes and appointments read
      openregister's task-anchored endpoints from openregister#3594
      (`/api/flow-tasks/{uuid}/notes` and `/events`); the audit sidebar tab
      became a page section over `/api/flow-tasks/{uuid}/audit`. The
      version-history tab is gone on purpose: an engine task is not an
      object, so nothing writes a version of it. The lifecycle buttons are
      the engine's verbs through `invoke(uuid, verb)`, never
      `CnLifecycleActions`, which asks `/api/objects/{uuid}/available-actions`
      and 404s for a task.
- [x] 2.2 `Tasks` (`/tasks`): add `entitySource: "tasks"` and `rowRoute:
      "TaskDetail"`, drop `register`/`schema`. Map the six lenses onto the
      engine's own filters (All -> `scope: all`, Mine -> `scope: assigned` +
      `isTerminal: false`, Unclaimed -> `scope: pooled` + `isTerminal:
      false`, Closed -> `isTerminal: true`, Overdue -> `overdue: true`, Due
      this week -> `dueAfter`/`dueBefore`). **Blocked on both library PRs
      landing and a dossiq nc-vue bump.**
- [x] 2.3 `Dashboard` and `CaseDetail` reference `caseTask` in widget config
      only. Repoint those widgets; the pages themselves do not change type.

## 3. The server side

**The file list below was incomplete.** A grep for `task_schema` as well as
the slug finds six more users the first pass missed: `AskPersonTaskStore`,
`WorkQueueService`, `CaseReassignmentService`, `KpiAggregationService`,
`DemoCaseloadReport` and `DemoCaseloadSeedDataService`. `AskPersonTaskStore`
is the important one: it is the flow node's task storage, and it pairs with
the resume listener that already moved.

- [x] 3.1 `lib/Service/Transitions/CreateTaskHandler.php` (dossiq#2363) — stop writing the
      register object. `EngineTaskGateway::mirrorCreate()` becomes the only
      write, and the `task_engine_write` flag goes with the dual-run.
- [x] 3.2 `lib/Flow/DossiqAskPersonNode.php` — created a `caseTask` and
      re-read it on heartbeat. Both moved to the engine.

      **DONE, and this entry used to be TWO entries both numbered 3.2**, which
      made the change's own count wrong: the file listed 38 tasks where 37 were
      distinct. The duplicate was the original wording plus the note written
      when the migration landed; they are one task and are now one entry.

      The migration half is done and verified. The node writes and re-reads
      through `AskPersonTaskStore`, which is the engine since 3.3b, so no
      `caseTask` object is created or read on any path.

      **The retirement question is RE-FILED to section 7, not answered here**,
      and deliberately left unanswered rather than decided in passing. Whether
      `dossiq.askPerson` should exist beside OpenRegister's `UserTaskNode` is a
      decision about duplication, and it is not free: the node carries three
      behaviours `UserTaskNode` has no reason to have — `AssigneeResolver`
      rendering with a declared fallback, the case-id extraction that makes the
      task hang off the case, and placing the answer under a configured
      `signalKey`. The shipped `Case behandeling` flow names `dossiq.askPerson`
      in two nodes, and every suspended run's resume slot names that node id, so
      a swap is a data migration and not an edit. Section 4 never waited on it
      and neither does the archive of this change.
- [x] 3.3 `lib/Service/Transitions/ChecklistGuard.php` — reads task rows to
      decide whether a transition may proceed.
- [x] 3.3b `lib/Flow/AskPersonTaskStore.php` — the flow node's own task
      storage, reading `task_schema` directly. Pairs with the resume
      listener, which already moved to `TaskTerminalEvent`.
- [x] 3.3c `lib/Service/WorkQueueService.php` (dossiq#2369) and
      `lib/Service/CaseReassignmentService.php` — both read `task_schema`;
      reassignment writes to it, so it is a writer as well as a reader.
- [x] 3.4 `lib/Service/KpiAggregationService.php` — counts tasks. Note the
      documented trap in that file: `findAll()` overwrites the register and
      schema context as a side effect, so a count issued after another read
      silently counts the WRONG schema and answers 0.
- [x] 3.5 `lib/Service/Settings/SchemaSlugMap.php` and `SchemaSlugResolver.php`
      — drop the slug.
- [x] 3.6 `lib/Service/Support/JsonEncodedStringProperties.php` — the
      `checklist` JSON-string handling. The engine stores real JSON, so this
      entry goes.
- [x] 3.7 `lib/Repair/RenameCollidingSchemaSlugs.php` — remove the slug from
      the collision list.
- [x] 3.8 Demo data: `DemoCaseloadGateway`, `DemoCaseloadReport`,
      `DemoCaseloadSeedDataService` and the 64 `tasks` rows in the seed files.
      Demo tasks must become engine tasks, or the demo caseload arrives with
      no work on it.

## 4. Delete the schema

Only after 1 to 3 are green.

- [x] 4.1 Reconcile or accept the `completedDate` loss. `TaskBuilder` reads no
      `completedAt` (openregister#3575), so 13 of 33 backfilled tasks keep
      their state and lose their date. Read it off the register row while the
      schema still exists, or record the decision to drop it.
- [x] 4.2 Remove `caseTask` from `lib/Settings/dossiq_register.json` and
      `lib/Settings/dossiq_mock_register.json`, as a TEXT deletion. Do not
      re-serialise: regenerating the mock descriptor destroys 36 hand-written
      demo objects (measured: 2933 insertions, 18011 deletions to change three
      rows).
- [x] 4.3 Retire `EngineTaskGateway`'s dual-run scaffolding: the
      `task_engine_write` flag, `mirrorCreate`'s trusted path, and
      `TaskBackfillService` with `occ dossiq:tasks:mirror`. They exist for the
      migration and should not outlive it.
- [x] 4.4 The slug is gone from every place that RESOLVES it. Not
      `lib src tests` alone: seed data, `tests/e2e/ci-seed.sh`, demo data and
      fixtures all carried it, and a miss in `ci-seed.sh` exits before
      Playwright starts, which reports every spec as NOT RUN rather than as
      one broken seed.

      ⚠️ **THE TEST AS FIRST WRITTEN — "`git grep -i casetask` returns
      nothing" — IS FALSE, AND WAS FALSE WHEN IT WAS TICKED.** Measured on
      `development` 2026-09-11: **124 files still match**, and every one of
      them is a hit this change kept ON PURPOSE. Leaving the claim standing is
      worse than having no claim, because the next person runs the stated
      command, gets 124 files, and has no way to tell a kept concept-name from
      a live binding.

      What the 124 are: component and file names that name the CONCEPT a task
      on a case (`CaseTaskPane.vue`, `CaseTasksTab.vue`,
      `caseTaskPaneHelpers.js`, the `caseTasks` parameters in `workflow.js`),
      prose and `_note` blocks recording why the schema went, archived
      openspec changes, and ONE live literal:
      `EngineTaskGateway::sourceKey()`'s `'dossiq:caseTask:' . $id`, kept
      deliberately as the only written-down form of the backfill key (see 4.1).

      The test that actually holds, and the one to re-run before archiving —
      the slug as a STRING a store is asked for, rather than the word:

          git grep -n "dossiq/caseTask\|'caseTask'\|\"caseTask\"" -- lib src appinfo

      Verified 2026-09-11: exit 1, no matches. `tests/` is deliberately NOT in
      that list and must not be added: 20-odd unit tests use the literal
      `caseTask` as an arbitrary schema NAME to drive a resolver or a slug map,
      which is fixture data rather than a binding, and including them would
      make the check permanently red for no defect.

## 5. e2e

- [x] 5.1 The nine specs that name the slug:
      `case-flow-live-journeys`, `case-list-lenses`, `case-parties`,
      `case-task-pane`, `checklist-per-status`, `dashboard-tiles`,
      `demo-caseload`, `pages`, plus `helpers/fixtures.ts` and `ci-seed.sh`.
      Landed as dossiq#2417 (six specs + the `seedFlowTask` / `invokeFlowTask`
      / `listFlowTasks` / `cleanupFlowTasks` helpers) and the three below.
- [x] 5.2 One new spec for the cutover itself: a task created by a transition,
      completed through the engine's verb, resuming a suspended flow run. That
      is the path `TaskCompletionResumeListener` now serves and no existing
      spec covers it end to end.

      DONE as `tests/e2e/task-completion-resumes-the-run.spec.ts`, and what it
      asserts is narrower than this line asked for, on purpose.

      **The transition half was already covered and re-asserting it would have
      proved nothing.** `checklist-per-status` drives a real transition, reads
      the tasks it created out of `/api/flow-tasks`, and completes one through
      the engine's verb. What no spec covered was the WAKE, and the reason it
      went uncovered is the reason it needed its own file:
      `case-flow-live-journeys` completes a task and then drives the flow
      worker, so a run woken by `TaskCompletionResumeListener` and a run woken
      by its own heartbeat are indistinguishable from there. The heartbeat is
      the safety net by design (`DossiqAskPersonNode` re-reads the task on
      re-entry), which means the listener can be entirely dead while every
      journey assertion stays green and the only symptom is a case that moves
      up to half an hour late.

      So the new spec asserts the wake through the one field that separates
      them. `FlowRunService::signal()` sets `resumeAt` to now;
      `DossiqAskPersonNode::heartbeatAt()` sets it minutes out. The run is read
      before and after the completion and must go from parked to due.

      It also covers the half that fails SILENTLY and had no test at any level
      above the unit: the engine announces terminality for `completed`,
      `terminated` and `disabled` alike, and only a completion is an answer. A
      second case has its task CANCELLED, and its run's `resumeAt` must not
      move.

      🔴 **THE MUTATION CHECK RECORDED HERE EARLIER WAS NOT ONE, AND THE
      CORRECTION IS THE MOST USEFUL THING IN THIS ENTRY.**

      Two runs were made. The first went red on the wrong test (a fourth
      assertion wrongly expected a run ending on `openregister.end` to finish
      `completed`, where it finishes `stopped`), and because the file is
      `serial`, the cancel test never executed and reported as SKIPPED. The
      second, after that fix, went red on exactly the cancel test with its own
      message, and was recorded here as clean.

      **It was not clean. The cancel test fails identically WITHOUT the
      mutation** — `-801ms` on the unmutated PR head against `-1073ms` under
      the mutation. It had never passed on the branch. A mutation check has
      two halves, red-with and green-without, and only the first was done:
      what was actually measured was a test that always fails, which is the
      mirror image of a test that cannot fail.

      **Why it always failed, established from the code rather than by
      re-running.** `resumeAt` cannot measure dossiq's listener, because
      dossiq's listener is not the only one on the event. OpenRegister
      registers `UserTaskTerminalListener` on the same `TaskTerminalEvent`; it
      filters on nothing but "committed" and "carries a run uuid" — no state
      check — and calls `FlowTaskBridge::continueRun()`, which calls
      `signal(run, payload: [])`. So the run is made due on EVERY terminal
      state, cancellation included, whatever dossiq does.

      That is CORRECT behaviour, not a defect: the run has to re-enter so the
      node can fail the step, which is this repo's own scenario "A withdrawn
      ask fails the step ... WHEN the run next re-enters the step". Waking it
      is how that happens in seconds instead of thirty minutes.

      So the assertion was measuring another app's listener and calling it
      dossiq's guard. The withdrawn-ask test now asserts what is real and was
      genuinely uncovered: **a withdrawn ask does not advance its step, and its
      run fails rather than reaching the end.** Dossiq's own refusal — that it
      delivers no ANSWER for a terminated task — stays unit-pinned in
      `TaskCompletionResumeListenerTest`, which is the right level, because two
      listeners share the event and the refusal has no signature of its own in
      the run.

      The same correction applies to the completion half: "makes the run due at
      once" would pass with dossiq's listener deleted. It is kept because the
      behaviour matters to whoever is waiting on the case, and the comment now
      says plainly what it does and does not prove.

## 5. e2e

- [x] 5.1 The nine specs that name the slug:
      `case-flow-live-journeys`, `case-list-lenses`, `case-parties`,
      `case-task-pane`, `checklist-per-status`, `dashboard-tiles`,
      `demo-caseload`, `pages`, plus `helpers/fixtures.ts` and `ci-seed.sh`.
      Landed as dossiq#2417 (six specs + the `seedFlowTask` / `invokeFlowTask`
      / `listFlowTasks` / `cleanupFlowTasks` helpers) and the three below.
- [x] 5.2 One new spec for the cutover itself: a task created by a transition,
      completed through the engine's verb, resuming a suspended flow run. That
      is the path `TaskCompletionResumeListener` now serves and no existing
      spec covers it end to end.

      DONE as `tests/e2e/task-completion-resumes-the-run.spec.ts`, and what it
      asserts is narrower than this line asked for, on purpose.

      **The transition half was already covered and re-asserting it would have
      proved nothing.** `checklist-per-status` drives a real transition, reads
      the tasks it created out of `/api/flow-tasks`, and completes one through
      the engine's verb. What no spec covered was the WAKE, and the reason it
      went uncovered is the reason it needed its own file:
      `case-flow-live-journeys` completes a task and then drives the flow
      worker, so a run woken by `TaskCompletionResumeListener` and a run woken
      by its own heartbeat are indistinguishable from there. The heartbeat is
      the safety net by design (`DossiqAskPersonNode` re-reads the task on
      re-entry), which means the listener can be entirely dead while every
      journey assertion stays green and the only symptom is a case that moves
      up to half an hour late.

      So the new spec asserts the wake through the one field that separates
      them. `FlowRunService::signal()` sets `resumeAt` to now;
      `DossiqAskPersonNode::heartbeatAt()` sets it minutes out. The run is read
      before and after the completion and must go from parked to due.

      It also covers the half that fails SILENTLY and had no test at any level
      above the unit: the engine announces terminality for `completed`,
      `terminated` and `disabled` alike, and only a completion is an answer. A
      second case has its task CANCELLED, and its run's `resumeAt` must not
      move.

      **The first mutation run proved nothing, and it is worth recording why.**
      `proof/task-resume-withdrawn-guard` widened the listener's guard to
      accept all three terminal states, and the job went red — on the WRONG
      test. A fourth assertion in the same file expected the finished run's
      status to be `completed`, where a run ending on `openregister.end`
      finishes as `stopped`. That failure had nothing to do with the mutation,
      and because the file runs `serial`, the cancel test never ran at all: it
      reported as skipped, which is not a result.

      Two lessons, both already in the fleet memory and both re-earned here:
      a red job is not a mutation check until you have read WHICH test failed
      and with what message, and a `serial` file can only be mutation-checked
      one assertion at a time, because the first failure hides every test
      after it.

      The status vocabulary is fixed and the mutation re-run. **Second run
      (dossiq 34581112375), and this one is a real check:**

      | Test | Result |
      |---|---|
      | the ask suspends its run and parks it on a heartbeat minutes away | ✓ |
      | the task the ask created is reachable on dossiq's own task page | ✓ |
      | completing it through the engine verb makes the run due at once | ✓ |
      | and one worker pass then carries the run past the ask to a terminal state | ✓ |
      | **cancelling the task leaves its run parked on the heartbeat** | **✘, and only this one** |

      It failed with its own message — "A cancelled task must NOT make the run
      due. A run resumed here would walk past the ask as though somebody had
      answered it, and nobody did." — and the numbers say exactly what the
      guard buys:

          Expected: > 60000      (parked, ~30 minutes out)
          Received:   -1073      (due, 1.07 seconds in the PAST)

      So with the guard widened, cancelling a task signals the run within a
      second. That is the behaviour the guard exists to refuse, and nothing
      else in the file moved. The listener was restored and verified
      byte-identical afterwards; the proof branch is deleted.
- [x] 5.3 `seedTask()` in `helpers/fixtures.ts` writes a register object.
      It becomes an engine create, and every spec that seeds a task inherits
      the change.

      DONE, and it landed as a rename rather than an edit. There is no
      `seedTask()` in `helpers/fixtures.ts`: `seedFlowTask()` replaced it and
      posts `/api/flow-tasks`. The two specs that still spell a helper
      `seedTask` (`checklist-per-status`, `case-task-pane`) each define their
      own local one, and both already post to the engine.

### What 5.1 left standing on purpose

`checklist-per-status` needed no conversion: dossiq#2402 and #2405 had already
moved both of its halves onto the engine, and its four remaining mentions of
the slug are past-tense history. One of them was a lie, though — a
`🔴 KNOWN TO FAIL` note on `back to intake and forward again keeps one set of
tasks`, describing the `existingTitles()` defect that #2405 fixed. The
assertion was always the right one and was never weakened; only the note
moved.

`case-flow-live-journeys` had rotted unnoticed. It is excluded from the
default Playwright project (it needs the shipped flow ENABLED), so nothing has
run it since the writes moved. Its `completeTask` read `/objects/dossiq/task`,
a slug this app does not ship, and then PUT `caseTask` — an object
`AskPersonTaskStore` stopped creating at #2363. Both halves now go through the
engine.

`ci-seed.sh` no longer REQUIRES `caseTask`. The schema still exists and
`demo-caseload` still seeds objects of it, deliberately — but a name in that
list is a hard `exit 1` before Playwright starts, which reports every spec as
NOT RUN. Out of the list, the day the schema goes costs `demo-caseload` its
two calculation scenarios and nothing else.

`FIXTURE_SCHEMAS` in `helpers/fixtures.ts` keeps `caseTask` for the same
reason: it is the cleanup order for the objects `demo-caseload` writes. It
goes with 4.2, not with 5.1.

## 6. What the first pass missed

An exhaustive inventory on 2026-09-10 found references the checklist above
does not name. Two of them were LIVE REGRESSIONS rather than deletion work,
and both were caused by moving the task WRITES to the engine while leaving
the matching READS on `caseTask`.

- [x] 6.1 `lib/Service/Transitions/StatusChecklist.php` (dossiq#2405, landed
      by a parallel session while this branch was out) — the worst of them.
      `actionsFor()` emits `createTask`, `CreateTaskHandler` writes it to the
      engine, and `tasksFor()` read `caseTask` objects. Both callers broke at
      once and neither said so: `existingTitles()` saw nothing, so every
      re-entry into a status raised the whole checklist again as DUPLICATE
      tasks, and `StatusChecklistGuard` saw nothing completed, so a status
      with a required item could never be left. Note the guard fails CLOSED,
      not open: an empty read blocks the transition rather than waving it
      through.
- [x] 6.2 `lib/Service/Substitution/SubstitutedWorkResolver.php` — a
      substitute saw the absentee's cases with no tasks under them. The guard
      `if ($taskSchema !== '')` stayed TRUE the whole time, so there was no
      branch to notice. This path had NO test at all, which is why it went
      unseen; it has two now.
- [x] 6.3 The read path never spoke the register's vocabulary.
      `engineTask.js`'s `create()` mapped `dueDate` to the engine's `dueAt`
      from the first day; rows came back RAW, so every component asking for
      `row.dueDate` got `undefined` and a task due today rendered "No due
      date". Mapped once in the store now, the way `EngineTaskInbox::asArray()`
      does it server-side.
- [x] 6.4 A version mismatch became a plausible zero. Every filter is a NAMED
      argument on another app's class, so an OpenRegister predating one throws
      "Unknown named parameter", the catch turns it into no rows, and a count
      answers 0 for ever. Measured on the dev instance, whose OpenRegister
      checkout predated the due-window filter: the dashboard's due-today tile
      read 0 and looked like a quiet morning. Logged at ERROR now, naming the
      parameter.
- [x] 6.5 `src/manifest.json` metrics `tasks_total` and `tasks_overdue_total`
      (`kind: objectCount`, `schema: caseTask`) and the `deepLinks[]` entry
      with `schemaSlug: caseTask`. Neither is in section 4; both dangle when
      the schema goes. The deep link is paired with
      `tests/vitest/searchableSchemas.spec.js`, whose own comment warns the
      pairing "fails silently" when broken.
- [x] 6.6 `src/views/settings/Settings.vue` — a visible admin form field bound
      to `form.task_schema`, offering a picker for a schema that will not
      exist.
- [x] 6.7 `lib/Service/Settings/ConfigKeys.php` carries `task_schema`. Decide
      explicitly whether to drop it or leave it as an orphan appconfig row.
      Dropping it also touches four LIVE openspec specs whose MUST-clauses
      enumerate the key, plus `SettingsServiceTest` and `VthSettingsServiceTest`.
- [x] 6.8 `lib/Settings/dossiq_mock_register.json` holds THREE seeded objects
      with `@self.schema: "caseTask"`, not just the schema block section 4.2
      names. Orphan demo rows pointing at a dead schema.
- [x] 6.9 `tests/vitest/casePartiesWidget.spec.js` reads
      `schema('caseTask').properties.assigneeGroup.facetable` off the shipped
      register and will fail outright. Two further e2e specs navigate to a
      task surface without naming the slug and are not in 5.1:
      `spec-coverage/task-management.spec.ts` and `docs-screenshots.spec.ts`.
- [x] 6.10 🔴 48 `@spec` citations ALREADY DANGLED, independent of this change:
      they name `openspec/changes/task-on-the-case/…`, which was archived on
      2026-09-08. They live in exactly the files this change touches
      (`CaseTaskPane.vue` 18, `TaskCaseCard.vue` 15, `registry.js` 4,
      `caseTaskPaneHelpers.js` 6, and four vitest specs), so repoint them in
      the same commit rather than leaving the debt behind.


## Section 4, as built

Six calls were made while deleting the schema. Each is recorded here with what
it cost, because the cheap half of a deletion is the code and the expensive
half is the decisions nobody wrote down.

**4.1, the `completedDate` loss is ACCEPTED, and it could not have been
avoided from here.** Reading the date off the register row was the plan, and
there is nowhere to put it: `completedAt` is writable only by the `complete`
verb, which stamps `new DateTime()`, and by OpenRegister's own repair step,
which reaches the entity directly. `TaskBuilder::fromData()` still sets no
`completedAt` (openregister#3575 is open), so a re-import would drop it again.
A leaf app cannot write another app's column.

The loss is also smaller than it reads. Deleting a schema from the descriptor
deletes nothing on a provisioned instance: `ConfigurationService::importFromApp`
creates and updates, and prunes nothing. The `caseTask` rows and their
`completedDate` are still there, orphaned under an orphan schema, and the
engine rows they became still carry `taskKey = dossiq:caseTask:<uuid>`. So the
thirteen dates are recoverable the day openregister#3575 lands, by whoever
wants them. `EngineTaskGateway::sourceKey()` is kept for exactly that: it is
the only place the key format is written down.

**6.7, `task_schema` STAYS in `ConfigKeys::ALL`, as an inert row.** Nothing in
`lib/` resolves it any more, so `SchemaKeyCoverageTest` (which sweeps `lib/`
for resolved keys) stays green without a recorded exception. The slug left
`SchemaSlugMap`, so `SchemaKeyReconciler` never writes the key again and it
reads `''` on a fresh instance.

Dropping it would have cost five MUST-clause edits across three LIVE specs
(`dossiq-app-scaffold`, `dossiq-case-management`, `dossiq-object-store`) plus
`SettingsServiceTest` and `VthSettingsServiceTest`. Those clauses describe the
SETTINGS contract, gate 19 then wants an `@e2e` citation for each scenario the
edit touches, and none of that is about a schema. It also buys nothing on a
live instance: removing a key from the list does not remove the appconfig row.
The admin form field is gone (6.6), so nothing offers a picker for a schema
that does not exist.

**6.5, both metrics are REMOVED and the gap is named.** `dossiq_tasks_total`
and `dossiq_tasks_overdue_total` were `kind: objectCount` over the deleted
schema, and a counter over a schema nothing declares reports 0 for ever. On a
task gauge that reads as a quiet week. An `objectCount` source cannot reach the
engine's table, so there is nothing dossiq can declare instead. OpenRegister is
exporting them itself in openregister#3597.

**The `deepLinks` entry is REMOVED, and engine tasks now have no unified-search
provider.** The entry was keyed on `schemaSlug: caseTask` and fed one consumer,
OpenRegister's schema-scoped unified search, which resolves a hit on an OBJECT
to a URL. With no objects it matched nothing. The gap is OpenRegister's: engine
tasks live in their own table, outside the object index the search reads, and
no `deepLinks` shape addresses them. The route `/tasks/:id` is unchanged, so
the page is ready the day such a shape exists, and
`manifestCaseTaskPane.spec.js` asserts both halves.

**`FIXTURE_SCHEMAS` DROPS `caseTask`.** It was cleanup order for the objects
`demo-caseload` wrote, and `demo-caseload` writes engine tasks now, so nothing
the suite creates lands there. One consequence is stated rather than
discovered: on an instance upgraded from a version that had the schema, rows
earlier runs left behind are no longer swept. They are orphan rows under an
orphan schema and removing them is an administrative act.

**`demo-caseload`'s two calculation scenarios CHANGED STORES rather than
going.** They asserted `isTerminalStatus` and `daysUntilDue`, materialised
calculations on the deleted schema. The engine answers both itself: `isTerminal`
is a real column the lifecycle verbs maintain, and the deadline is a per-row
projection `TaskInboxService::row()` attaches at read time. Both scenarios ask
the same two questions of `/api/flow-tasks` and assert exact numbers. The
fixture clock gained an hour of slack in each direction, because the projection
is `intdiv(abs(deadline - now), 86400)` on an instant and a deadline set
exactly two days back reports 1 after one second of runtime.

### What section 4 did NOT do

Five live specs still name the schema, measured by `git grep -i casetask --
openspec/specs` after this change:

| Spec | What it still says |
|---|---|
| `task-management` | the Tasks page is `type: index` over `caseTask`; lifecycle, priority facet and `caseTask.case` |
| `case-management` | "The case task schema SHALL be `caseTask` and SHALL NOT be `task`" (line 1230) |
| `case-search-via-or-unified-search` | flags `caseTask` as searchable |
| `role-routing-via-or-rbac` | `caseTask` gains an `assigneeGroup` property |
| `case-types`, `case-management` (twice) | `CMMN CaseTask`: the CMMN standard's element, unrelated to the schema, and correct as it stands |

The first four are a spec delta over a capability that still exists, not
deletion work: the task surfaces are all still there and all read the engine. Rewriting those
clauses touches live specs and pulls gate 19's `@e2e` requirement into a
change that ships none, so it is left for a follow-up whose subject is the
spec rather than the schema.

The same holds for the two metrics. `openspec/specs/apphost-adoption/spec.md`
and `openspec/specs/prometheus-metrics/spec.md` still carry MUST-clauses naming
`dossiq_tasks_total` and `dossiq_tasks_overdue_total`, and those series are gone.
No test asserts them, so nothing reddens; the specs simply describe output the
app no longer produces. Their follow-up belongs with openregister#3597, which is
where task counts are now exported.

The admin tutorial's screenshot `03-admin-settings-03.png` still shows a Task
schema field. The prose beside it is corrected; the image is regenerated by the
journeydoc capture, which does not run on a PR.

`blocksCase` is gone with the schema and had NO consumer anywhere in `lib`,
`src`, the manifest or the e2e suite. The engine has no column for it. A task
blocking a case is therefore a capability dossiq declared, materialised and
never used; `CaseFlowDeclarationTest` now asserts no schema re-declares it.

Component and file names keep the word: `CaseTaskPane.vue`, `CaseTasksTab.vue`,
`caseTaskPaneHelpers.js`, and the `caseTasks` parameters in `workflow.js`.
Those name the CONCEPT, a task on a case, which the app still has. Renaming
them is a refactor with no functional change and is out of scope here.

## Not in this change

- `tenantOnboardingTask` is re-filed here from the tenancy cluster (it is a
  step, a completedBy, a completedAt and a blockedReason, which is a `Task`),
  but it is its own migration and should not ride along with this one.

## 7. Follow-ups

### 7.0 🔴 ARCHIVING THIS CHANGE BREAKS 14 `@spec` CITATIONS

Found 2026-09-11, while checking what archiving would cost. This is a blocker
on the archive, not on the work.

- [ ] 7.0 Repoint the 14 `@spec` citations that name
      `openspec/changes/remove-casetask/tasks.md`. Measured:

          git grep -c 'openspec/changes/remove-casetask' -- lib src tests   # 14

      across `AskPersonTaskStore`, `EngineTaskGateway` (2), `EngineTaskInbox`
      (2), `EngineTaskInboxTest`, `TaskWaitingCaseSection.vue`,
      `CaseTaskPane.vue` (2), `TaskCaseCard.vue`, `engineTask.js` (3) and
      `flowTaskHelpers.js`.

      **Every one names a tasks.md, which is a PLAN and not a requirement.**
      Gate 16 is satisfied by the path existing, so all 14 are green today and
      stay green right up to the moment the archive moves the file, at which
      point they resolve to nothing — the same failure dossiq#2057 left behind
      as 119 dangling citations.

      🔴 **AND THE OBVIOUS TARGET IS NOT YET A VALID ONE.**
      `openspec/specs/task-management/spec.md` is where these requirements
      belong, but it still describes dossiq's tasks as "JSON objects with
      CMMN-compliant lifecycle states" (line 23) and four of its requirements
      still name the deleted schema. Repointing 14 citations at a spec that
      describes the pre-cutover model would LOOK resolved and be worse than
      the dangle, because nothing would then prompt anyone to fix it.

      So the order is fixed: bring `task-management/spec.md` up to the engine
      first (the follow-up "Section 4 did NOT do" already names, whose subject
      is the spec rather than the schema), THEN repoint, THEN archive.

### 7.2 🔴 TWO LISTENERS SIGNAL THE SAME RUN AND THE EMPTY PAYLOAD WINS

Found 2026-09-11 by the spec 5.2 added. The first filing of this blamed
`RegistryStepDispatcher::scopeSignal()`; that was wrong, and the real cause is
both simpler and worse.

- [ ] 7.2 Stop OpenRegister's empty signal payload from overwriting dossiq's.

      **Two listeners are registered on `TaskTerminalEvent` and both signal
      the run:**

      | Listener | Payload |
      |---|---|
      | dossiq `TaskCompletionResumeListener` | `decision`, `node`, `taskId`, `completedBy` |
      | openregister `UserTaskTerminalListener` -> `FlowTaskBridge::continueRun()` | **`[]`** |

      `FlowRunService::signal()` assigns `$context['signal'] = $payload`
      outright, so this is last-writer-wins, and the empty one is a legitimate
      winner. That is exactly what the run log shows: the answer carries
      `recovered: true`, which is `DossiqAskPersonNode::answerFor()`'s name for
      `$signal === []`.

      **What it costs.** `completedBy` is the one thing the payload carries
      that the task row does not, so nothing after the ask can route on who
      answered. And the node logs "a heartbeat delivered the answer to task X;
      its completion signal never reached the run" at INFO on the normal path,
      every time — an alarm that always fires is an alarm nobody reads the day
      it is true.

      **It also makes dossiq's listener very nearly redundant.** Waking the
      run is done by OpenRegister's listener already; the payload is dossiq's
      only distinctive contribution, and it is discarded. Worth asking whether
      the right fix is for dossiq to stop signalling and instead have
      OpenRegister carry the outcome bag — `FlowTaskBridge::outcomeBagFor()`
      already assembles exactly those fields, `completedBy` included, and
      passes `[]` anyway.

      Likely an openregister issue rather than a dossiq one. Establish
      ownership before writing a fix.

      NOT asserted in the spec: pinning `recovered: false` ships a red test,
      pinning `recovered: true` freezes the defect.

### 7.1 Follow-up: `tenantOnboardingTask`

Re-filed from `tenancy-onto-openregister-organisation` (decision 2c,
2026-09-11). It is a follow-up to this change, not part of it.

- [ ] 7.1 Move `tenantOnboardingTask` onto the engine `Task`. The fields
      have homes: `step` becomes the `taskKey`, `completedBy` and
      `completedAt` map by name, `blockedReason` maps by name, and
      `tenantRef` becomes the task's `organisation`. The status does not
      map by name, so decide it before starting: `pending` to `available`,
      `in_progress` to `active` and `completed` to `completed` read
      naturally, but `skipped` could be `disabled` or `terminated` with an
      `outcome`, and those mean different things to an inbox.
      **Waits on the tenancy move.** `TenantOnboardingService::activate()`
      ends by setting the tenant `active`, and what a tenant's status becomes
      on `Organisation` is still undecided (tenancy decisions 2e and 2f).
      Measured on the dev instance 2026-09-11: 7 rows, all for tenant id
      `00000000-0000-0000-0000-00000000000d`, which does not exist. Test
      fixture residue, not data to migrate.

## What the server side learned on the way

Three things came out of 3.1 to 3.4 that the plan did not anticipate, and
each one is now a property of the code rather than a note here.

**A read that fails and a case with no tasks both answer `[]`.** For the
checklist guard that difference decides a transition: "no tasks" means
"nothing unticked", which PASSES. So `EngineTaskInbox::lastError()` exists,
the guard asks for it by name, and the guard's own test cannot prove it
because that test mocks the method -- the proof lives in
`EngineTaskInboxTest` instead.

**A count is not `count($rows)`.** The inbox pages. A dashboard tile built
on the row count reads the page size once there are more matches than the
limit, and would have said the same number for ever. The envelope carries
`total`, so the count asks for a page of one and throws the rows away.

**`task_schema` was a required id for the whole KPI payload.** Every case
tile on the dashboard would have blanked the moment the task schema was
retired. It is gone from `ids()`, which is a prerequisite for step 4 rather
than a tidy-up.
