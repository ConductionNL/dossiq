# Tasks: case-lifecycle-on-the-page

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. Transition strip on the case page

- [x] 1.1 Add `src/components/case/CaseTransitionsWidget.vue`: reads
  `GET /api/case/{caseId}/available-transitions`, renders one button per
  transition, opens a confirm dialog (comment field; result type select when
  the target status `isFinal`), posts `POST /api/case/{caseId}/transition`,
  bumps `cn:page:refresh` so the page re-reads. Registered in `src/registry.js`
  (kind `widget`, ADR-036 -- `customComponents.js` is the legacy map) and bound
  as page slot `widget-case-transitions`.
  - guard failures from the service render inside the dialog
  - `@spec openspec/specs/status-transition-engine/spec.md`
- [x] 1.2 `src/manifest.json` page `CaseDetail`: add widget `case-transitions`
  (`type: custom`) in the header row, remove the `lifecycleActions` block, and
  keep the layout cell budget (ADR-062: no reserved voids).
- [x] 1.3 `lib/Service/StatusTransitionService.php`: accept `resultTypeId` in the
  execute payload and write the `result` object before the status moves when
  the target status is final; reject a final transition without one.
  - unit test in `tests/Unit/Service/StatusTransitionServiceResultTest.php`

## 2. Actions menu: suspend, resume, extend, reopen

- [x] 2.1 Add `lib/Controller/CaseLifecycleController.php` with `suspend`,
  `resume`, `extend`, `reopen` over `DeadlinePauseService`,
  `DeadlineExtensionService` and `StatusTransitionService`; each guards the
  case with `CaseAccessGuard` and reopen checks the reopen scope the way
  `ZrcController::checkReopenScope` does. Register the four routes in
  `appinfo/routes.php`.
  - SPDX header, `#[NoAdminRequired]` plus per-case guard, 403 on refusal
  - unit test in `tests/Unit/Controller/CaseLifecycleControllerTest.php`
    covering the refused path first
- [x] 2.2 `src/manifest.json` page `CaseDetail`: four `headerActions` of type
  `handler` (Suspend, Resume, Extend term, Reopen) with `visibleWhen` on
  `caseType.suspensionAllowed`, the suspended marker, `caseType.extensionAllowed`
  and `isFinalStatus`. Shipped as `open-modal` onto ONE registered dialog
  (`CaseLifecycleActionDialog`, kind `modal`) rather than four handlers: the
  gesture differs only by `props.action`. Only Reopen gates as written --
  `isFinalStatus` is on the case record, while `suspensionAllowed` and
  `extensionAllowed` are on the case TYPE, which an action's `visibleWhen`
  cannot reach (local mode reads the record; `endpoint` mode is not
  token-interpolated; an OpenRegister `source` filtered by `id` returns the
  whole table). Suspension is derived from the case's `activity` journal, so
  Resume is not gateable either. The dialog reads `/lifecycle` before it posts
  and refuses there; the strip shows Resume when the case is really suspended.

## 3. The stepper

- [x] 3.1 Add `src/components/case/CaseStepsWidget.vue` rendering
  `CnTimelineStages` over the `statusType` rows of the case's type sorted by
  `order`, active on `case.status`; bind it as page slot `widget-case-steps`.
- [x] 3.2 `src/manifest.json` page `CaseDetail`: replace widget
  `case-kpi-progress` with `case-steps` in the same layout cell.
- [ ] 3.3 [blocked: nextcloud-vue a `stepper` widget type over a reference
  field's ordered rows] Replace the custom widget with the declarative type when
  it ships; until then 3.1 is the interim.

## 4. Verification

- [x] 4.1 Add `tests/e2e/case-lifecycle-on-the-page.spec.ts` covering every
  scenario of the two delta specs that names it (transitions render, execute,
  guard failure, final transition with result, suspend and resume, extend,
  reopen, stepper), seeded through `seedStateMachine` from
  `tests/e2e/helpers/fixtures.ts`.
- [x] 4.2 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the KPI row no
  longer holds Completed; assert no `/milestones/progress` request.
- [x] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
- [ ] 4.4 [blocked: openregister a lifecycle over a reference field, or
  nextcloud-vue `CnLifecycleActions` accepting a transition endpoint per page]
  Delete `CaseTransitionsWidget.vue` and declare `lifecycleActions` again when
  `case-status-onto-engine-lifecycle` lands; 1.1 is the interim.
