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

- [ ] 1.1 `src/components/tasks/CaseTaskPane.vue` — reads
      `objectStore.fetchCollection('caseTask', openTasksQuery(...))`. Becomes
      `engineTasks.openForCase(caseId)`, which already filters terminal rows.
      Its lifecycle buttons move from the register's transitions to the
      engine's verbs (`invoke(uuid, 'complete')`).
- [ ] 1.2 `src/utils/caseTaskPaneHelpers.js` — `FINAL_TASK_STATUSES` is the
      same three states the engine calls terminal. Delete it and use
      `isTerminal` from the engine store, rather than keeping a second copy
      that can drift.
- [ ] 1.3 `src/components/tabs/CaseTasksTab.vue` — the sidebar list.
- [ ] 1.4 `src/views/widgets/MyTasksWidget.vue` — `scope: 'assigned'` here,
      unlike the case surfaces: this one IS the reader's own list.
- [ ] 1.5 `src/views/widgets/TaskRemindersWidget.vue` — needs `overdue`,
      which the engine's inbox filter already supports, so dossiq stops
      deriving overdue-ness itself.
- [ ] 1.6 `src/components/flow/TaskWaitingCaseSection.vue` and
      `src/components/tasks/TaskCaseCard.vue` — both read a task to find its
      case. The engine's `objectUuid` IS the case, so these get simpler.

## 2. The two page rewrites

Neither is a repoint. `type: index` and `type: detail` bind a register and a
schema and let the platform render; the engine is not an OpenRegister object,
so both become `type: custom`.

- [ ] 2.1 `TaskDetail` (`/tasks/:id`) becomes a custom page. **The route, the
      page id and the deep links do not change**, so notification links and
      bookmarks survive. It keeps the case card, the notes and appointment
      leaves and the lifecycle buttons: what changes is the store behind it.
      Check `lib/Service/DeepLink*` and the notification templates still
      resolve.
- [ ] 2.2 `Tasks` (`/tasks`) becomes a custom index over the engine's inbox.
      The six lenses (REQ-TASK-016/017) map onto the inbox's own `scope`,
      `state`, `priority` and `overdue` filters rather than onto register
      queries.
- [ ] 2.3 `Dashboard` and `CaseDetail` reference `caseTask` in widget config
      only. Repoint those widgets; the pages themselves do not change type.

## 3. The server side

- [ ] 3.1 `lib/Service/Transitions/CreateTaskHandler.php` — stop writing the
      register object. `EngineTaskGateway::mirrorCreate()` becomes the only
      write, and the `task_engine_write` flag goes with the dual-run.
- [ ] 3.2 `lib/Flow/DossiqAskPersonNode.php` — creates a `caseTask` and
      re-reads it on heartbeat. Both move to the engine. **Consider retiring
      the node entirely** in favour of OpenRegister's `UserTaskNode`
      (`flow-user-task-node`, 19/19 done), which does the same thing against
      the engine natively. That is a separate decision and should be made
      before this task is started, not during it.
- [ ] 3.3 `lib/Service/Transitions/ChecklistGuard.php` — reads task rows to
      decide whether a transition may proceed.
- [ ] 3.4 `lib/Service/KpiAggregationService.php` — counts tasks. Note the
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
- [ ] 3.8 Demo data: `DemoCaseloadGateway`, `DemoCaseloadReport`,
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

## Not in this change

- `tenantOnboardingTask` is re-filed here from the tenancy cluster (it is a
  step, a completedBy, a completedAt and a blockedReason, which is a `Task`),
  but it is its own migration and should not ride along with this one.
