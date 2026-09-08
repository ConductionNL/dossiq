# Tasks: checklist-per-status

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The list on the status

- [ ] 1.1 `lib/Settings/dossiq_register.json`, schema `statusType`: property
  `checklist` per design D1 (array of `{title, required}`), title Checklist,
  with a description that says the tasks are created on entry.
  - `@spec openspec/specs/status-transition-engine/spec.md`
  - `tests/schemas` round-trips the new property
- [ ] 1.2 `lib/Settings/register.d/` bezwaar case type: the Intake and In
  behandeling items per design Seed Data.

## 2. Tasks on entry

- [ ] 2.1 `lib/Service/Transitions/StatusChecklist.php` (new):
  `actionsFor(string $statusTypeId, array $case): array` per design D2 and
  D3, reading the status through `StatusTypeLookup` and the case's tasks
  through `SearchesObjects` filtered on `workflowStepId`.
  - `@spec openspec/specs/status-transition-engine/spec.md`
  - `tests/Unit/Service/Transitions/StatusChecklistTest.php` (new): two
    items yield two actions; an existing task by title skips its item; an
    absent or empty list yields nothing
- [ ] 2.2 `lib/Service/Transitions/CreateTaskHandler.php`: write
  `workflowStepId` from `actionConfig` when present.
  - extend `tests/Unit/Service/Transitions/CreateTaskHandlerTest.php` (new,
    the handler has none) with the saved object carrying the field
- [ ] 2.3 `lib/Service/StatusTransitionService.php`: `execute` prepends the
  checklist actions to the transition's actions before
  `sideEffectDispatcher->dispatch`; `executeFreeForm` dispatches the checklist
  actions after its status write and returns them as `dispatchedActions`.
  - extend `tests/Unit/Service/StatusTransitionServiceRouteSeamTest.php`
    with the checklist actions reaching the dispatcher on both paths

## 3. Required items hold the case

- [ ] 3.1 `lib/Service/Transitions/StatusChecklistGuard.php` (new) per
  design D4, registered in `GuardRegistry` as `statusChecklist` and
  evaluated on every transition by `StatusTransitionService::evaluateGuards`
  regardless of the template's guard list.
  - `@spec openspec/specs/status-transition-engine/spec.md`
  - `tests/Unit/Service/Transitions/StatusChecklistGuardTest.php` (new): a
    required item with an open task fails and names it; with a completed
    task passes; with no task fails; an optional item never fails
- [ ] 3.2 `l10n/en.json` and `l10n/nl.json`: Checklist, "Checklist item not
  done: %s".

## 4. Verification

- [ ] 4.1 Add `tests/e2e/checklist-per-status.spec.ts` covering every
  scenario of the delta spec that names it: the tasks arriving on a
  transition and on a free-form move, one set after a round trip, the
  disabled button with its reason, completing the task freeing the case,
  and an optional item not holding it. Assert on ids, not English labels.
- [ ] 4.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
