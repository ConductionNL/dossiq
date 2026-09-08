# Tasks: case-actions-menu

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. Copy a case

- [ ] 1.1 Add `lib/Service/CaseCopyService.php` with `copy(string $caseId,
  array $options): array` per design D1: fixed field list, initial status,
  `relatedCases` to the source, documents linked by reference when asked.
  - SPDX header; `@spec openspec/specs/case-management/spec.md`
  - unit test `tests/Unit/Service/CaseCopyServiceTest.php` asserting the
    fields that are never copied stay empty
- [ ] 1.2 Add `lib/Controller/CaseActionsController.php` with `copy`,
  `startableFlows` and `plan`; register `POST /api/case/{caseId}/copy`,
  `GET /api/case/{caseId}/startable-flows` and `POST /api/case/{caseId}/plan`
  in `appinfo/routes.php`.
  - `#[NoAdminRequired]` plus a per-case guard on every method, 403 on refusal
  - unit test `tests/Unit/Controller/CaseActionsControllerTest.php` covering
    the refused path first
- [ ] 1.3 `src/manifest.json` page `CaseDetail`: header action `copy-case`
  (type `handler`); bind the handler in `src/customComponents.js` to open
  `CnCopyDialog` and navigate to the new case.
- [ ] 1.4 [blocked: nextcloud-vue a `copy` header action type over
  `CnCopyDialog` taking a field list and an endpoint] Replace the handler with
  the declaration; 1.3 is the interim.

## 2. Start a flow

- [ ] 2.1 `lib/Settings/dossiq_register.json`: property `startableFlows` on
  `caseType` (array of `$ref` to `flow`); seed the shipped sub-case and letter
  flows on the demo types.
- [ ] 2.2 Add `src/components/case/CaseStartFlowDialog.vue`: lists the flows
  from `startable-flows`, posts the chosen one to OpenRegister's run endpoint
  with the case as subject, closes on success. Header action `start-flow`
  (type `handler`) on `CaseDetail`.
- [ ] 2.3 [blocked: openregister a `userStartable` flag on the `flow` schema]
  Read the flag instead of `caseType.startableFlows`; 2.1 is the interim.

## 3. Plan a follow-up

- [ ] 3.1 Add `src/components/case/CasePlanFollowUpDialog.vue` (case type,
  date, title) and the `plan` controller method writing one flow with a
  `TriggerScheduleNode` (`runAs` set, once) and a `DossiqTxCreateSubCaseNode`.
  - unit test asserts `runAs` is set and the trigger is single-shot
- [ ] 3.2 Add `src/components/case/CasePlannedWidget.vue` as `custom` widget
  `case-planned` under the Related cases tab, listing unfired planned flows
  for this case; header action `plan-follow-up` on `CaseDetail`.
- [ ] 3.3 [blocked: openregister the `related` widget listing scheduled flows
  by subject] Delete `CasePlannedWidget.vue`; 3.2 is the interim.

## 4. Verification

- [ ] 4.1 Add `tests/e2e/case-actions-menu.spec.ts` covering every scenario
  of the two delta specs that names it (copy with and without documents, the
  Start list, a run appearing in `case-flow-runs`, a planned row on Related
  cases).
- [ ] 4.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
