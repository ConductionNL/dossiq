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

- [ ] 2.1 `TaskDetail` (`/tasks/:id`). Still a real change: `type: "detail"`
      binds a register and a schema, and there is no detail-page equivalent
      of `entitySource`. **The route, the page id and the deep links must not
      change**, so notification links and bookmarks survive. It keeps the
      case card, the notes and appointment leaves and the lifecycle buttons.
      Check `lib/Service/DeepLink*` and the notification templates resolve.
- [ ] 2.2 `Tasks` (`/tasks`): add `entitySource: "tasks"` and `rowRoute:
      "TaskDetail"`, drop `register`/`schema`. Map the six lenses onto the
      engine's own filters (All -> `scope: all`, Mine -> `scope: assigned` +
      `isTerminal: false`, Unclaimed -> `scope: pooled` + `isTerminal:
      false`, Closed -> `isTerminal: true`, Overdue -> `overdue: true`, Due
      this week -> `dueAfter`/`dueBefore`). **Blocked on both library PRs
      landing and a dossiq nc-vue bump.**
- [ ] 2.3 `Dashboard` and `CaseDetail` reference `caseTask` in widget config
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
- [x] 3.2 `lib/Flow/DossiqAskPersonNode.php` — DONE, and done before it was
      ticked: the node builds an `AskPersonTaskStore` wired to
      `EngineTaskGateway` and carries no `caseTask`, `task_schema` or
      `saveObject` at all. Verified 2026-09-10. It originally created a `caseTask` and
      re-reads it on heartbeat. Both move to the engine. **Consider retiring
      the node entirely** in favour of OpenRegister's `UserTaskNode`
      (`flow-user-task-node`, 19/19 done), which does the same thing against
      the engine natively. That is a separate decision and should be made
      before this task is started, not during it.
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
- [ ] 3.5 `lib/Service/Settings/SchemaSlugMap.php` and `SchemaSlugResolver.php`
      — drop the slug.
- [ ] 3.6 `lib/Service/Support/JsonEncodedStringProperties.php` — the
      `checklist` JSON-string handling. The engine stores real JSON, so this
      entry goes.
- [ ] 3.7 `lib/Repair/RenameCollidingSchemaSlugs.php` — remove the slug from
      the collision list.
- [x] 3.8 Demo data: `DemoCaseloadGateway`, `DemoCaseloadReport`,
      `DemoCaseloadSeedDataService` and the 64 `tasks` rows in the seed files.
      Demo tasks must become engine tasks, or the demo caseload arrives with
      no work on it.

## 4. Delete the schema

Only after 1 to 3 are green.

- [ ] 4.1 Reconcile or accept the `completedDate` loss. `TaskBuilder` reads no
      `completedAt` (openregister#3575), so 13 of 33 backfilled tasks keep
      their state and lose their date. Read it off the register row while the
      schema still exists, or record the decision to drop it.
- [ ] 4.2 Remove `caseTask` from `lib/Settings/dossiq_register.json` and
      `lib/Settings/dossiq_mock_register.json`, as a TEXT deletion. Do not
      re-serialise: regenerating the mock descriptor destroys 36 hand-written
      demo objects (measured: 2933 insertions, 18011 deletions to change three
      rows).
- [ ] 4.3 Retire `EngineTaskGateway`'s dual-run scaffolding: the
      `task_engine_write` flag, `mirrorCreate`'s trusted path, and
      `TaskBackfillService` with `occ dossiq:tasks:mirror`. They exist for the
      migration and should not outlive it.
- [ ] 4.4 `git grep -i casetask` over the WHOLE repo returns nothing. Not
      `lib src tests`: seed data, `tests/e2e/ci-seed.sh`, demo data and
      fixtures all carry it, and a miss in `ci-seed.sh` exits before
      Playwright starts, which reports every spec as NOT RUN rather than as
      one broken seed.

## 5. e2e

- [ ] 5.1 The nine specs that name the slug:
      `case-flow-live-journeys`, `case-list-lenses`, `case-parties`,
      `case-task-pane`, `checklist-per-status`, `dashboard-tiles`,
      `demo-caseload`, `pages`, plus `helpers/fixtures.ts` and `ci-seed.sh`.
- [ ] 5.2 One new spec for the cutover itself: a task created by a transition,
      completed through the engine's verb, resuming a suspended flow run. That
      is the path `TaskCompletionResumeListener` now serves and no existing
      spec covers it end to end.
- [ ] 5.3 `seedTask()` in `helpers/fixtures.ts` writes a register object.
      It becomes an engine create, and every spec that seeds a task inherits
      the change.

## 6. What the first pass missed

An exhaustive inventory on 2026-09-10 found references the checklist above
does not name. Two of them were LIVE REGRESSIONS rather than deletion work,
and both were caused by moving the task WRITES to the engine while leaving
the matching READS on `caseTask`.

- [x] 6.1 `lib/Service/Transitions/StatusChecklist.php` — the worst of them.
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
- [ ] 6.5 `src/manifest.json` metrics `tasks_total` and `tasks_overdue_total`
      (`kind: objectCount`, `schema: caseTask`) and the `deepLinks[]` entry
      with `schemaSlug: caseTask`. Neither is in section 4; both dangle when
      the schema goes. The deep link is paired with
      `tests/vitest/searchableSchemas.spec.js`, whose own comment warns the
      pairing "fails silently" when broken.
- [ ] 6.6 `src/views/settings/Settings.vue` — a visible admin form field bound
      to `form.task_schema`, offering a picker for a schema that will not
      exist.
- [ ] 6.7 `lib/Service/Settings/ConfigKeys.php` carries `task_schema`. Decide
      explicitly whether to drop it or leave it as an orphan appconfig row.
      Dropping it also touches four LIVE openspec specs whose MUST-clauses
      enumerate the key, plus `SettingsServiceTest` and `VthSettingsServiceTest`.
- [ ] 6.8 `lib/Settings/dossiq_mock_register.json` holds THREE seeded objects
      with `@self.schema: "caseTask"`, not just the schema block section 4.2
      names. Orphan demo rows pointing at a dead schema.
- [ ] 6.9 `tests/vitest/casePartiesWidget.spec.js` reads
      `schema('caseTask').properties.assigneeGroup.facetable` off the shipped
      register and will fail outright. Two further e2e specs navigate to a
      task surface without naming the slug and are not in 5.1:
      `spec-coverage/task-management.spec.ts` and `docs-screenshots.spec.ts`.
- [ ] 6.10 🔴 48 `@spec` citations ALREADY DANGLE, independent of this change:
      they name `openspec/changes/task-on-the-case/…`, which was archived on
      2026-09-08. They live in exactly the files this change touches
      (`CaseTaskPane.vue` 18, `TaskCaseCard.vue` 15, `registry.js` 4,
      `caseTaskPaneHelpers.js` 6, and four vitest specs), so repoint them in
      the same commit rather than leaving the debt behind.

## Not in this change

- `tenantOnboardingTask` is re-filed here from the tenancy cluster (it is a
  step, a completedBy, a completedAt and a blockedReason, which is a `Task`),
  but it is its own migration and should not ride along with this one.

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
