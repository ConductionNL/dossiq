## 1. Case-model definition schema

- [x] 1.1 Add `lib/Settings/register.d/70-cmmn-case-model.json` — `caseModel` schema per
      `design.md` §2 (`caseFileItems`, `planItems` tree, `lifecycleStatus`)
- [x] 1.2 Additive `handlingModel` enum (`bpmn` default | `cmmn`) on `caseType` in
      `lib/Settings/procest_register.json`
- [x] 1.3 Additive `casePlanState` (JSON-encoded string) on `case` in
      `lib/Settings/procest_register.json`
- [x] 1.4 `SettingsService::SLUG_TO_CONFIG_KEY['caseModel'] = 'case_model_schema'` (auto-reconciled
      by `reconcileSchemaConfig()`/`autoConfigureAfterImport()`, matching the `milestoneDefinition`
      precedent — no `CONFIG_KEYS` admin-list entry needed)

## 2. Runtime engine (pure, deterministic)

- [x] 2.1 `lib/Service/Cmmn/CaseModelLoader.php` — active-`caseModel`-by-`caseType` lookup,
      per-request memoised, mirroring `WorkflowTemplateLoader`
- [x] 2.2 `lib/Service/Cmmn/PlanItemTransitions.php` — the exhaustive legal-transition table +
      `IllegalPlanItemTransitionException`, pure/static, no I/O
- [x] 2.3 `lib/Service/Cmmn/SentryEvaluator.php` — pure sentry evaluation (`design.md` §4): AND
      within a sentry, OR across a criteria array, reusing the `{field,operator,value}` condition
      shape from `Service/Transitions/RequiredFieldGuard`
- [x] 2.4 `lib/Service/Cmmn/CaseModelEngine.php` — orchestrates load/save (single
      `ObjectService::saveObject()` write path per mutation), plan-item state per `design.md` §3,
      sentry-driven cascades bounded by `MAX_CASCADE_DEPTH`, milestone achievement recording,
      discretionary-enablement gating, `case_not_cmmn_managed` guard (REQ-CMMN-008)
- [x] 2.5 Unit tests: every legal transition (2.2), every illegal transition throws (2.2), sentry
      AND/OR/multi-criteria (2.3), discretionary gating (2.4), milestone achievement (2.4), single-
      write-path assertion (2.4), BPMN/CMMN mutual-refusal guard (2.4)

## 3. REST surface

- [x] 3.1 `lib/Controller/CmmnCaseController.php` — `getPlan`, `enableDiscretionary`, `completeTask`,
      `terminateTask`, `signalEvent`, following `StatusTransitionController`'s auth/error-mapping
      conventions (static error messages, `readJsonBody()` via `getParams()`)
- [x] 3.2 Routes in `appinfo/routes.php`: `GET /api/case/{caseId}/cmmn-plan`,
      `POST /api/case/{caseId}/cmmn-plan/enable`, `POST /api/case/{caseId}/cmmn-plan/complete`,
      `POST /api/case/{caseId}/cmmn-plan/terminate`, `POST /api/case/{caseId}/cmmn-plan/signal`
- [x] 3.3 Group-authorization gate per plan item's optional `authorization: string[]`, reusing
      `IGroupManager` the same way `StatusTransitionService::isTransitionGroupAuthorized()` does
- [x] 3.4 Controller tests: 401 unauthenticated, 403 unauthorized-group, happy path per endpoint,
      illegal-transition mapped to 409

## 4. Coexistence + case-driven proof

- [x] 4.1 `CaseModelEngine` end-to-end test per REQ-CMMN-009: define a small model, activate a
      stage, trip a sentry to enable a discretionary task, complete it, achieve a milestone — all
      through the public engine API against a real (in-memory-mocked OR) case object
- [x] 4.2 Regression test: an existing `bpmn`-handled caseType's `StatusTransitionService` behaviour
      is unchanged (no `caseModel` lookup, no `casePlanState` read/write)

## 5. UI

- [x] 5.1 `src/services/cmmnApi.js` — thin fetch wrappers for the 4 endpoints
- [x] 5.2 `src/utils/cmmnHelpers.js` — pure helpers: group items by stage, state→badge label/class,
      compute enable-able set client-side-safe subset (Vitest-tested, no DOM/network)
- [x] 5.3 `src/views/cases/components/CmmnCasePlanPanel.vue` — case-detail widget, NC CSS vars only,
      renders nothing when case's caseType is not `cmmn`-handled, following `CaseAssistantPanel`'s
      route-param + services/*Api.js pattern
- [x] 5.4 Register in `src/registry.js` (`kind: 'widget'`) and add a `type: "custom"` widget +
      slot entry on the `CaseDetail` page in `src/manifest.json`, following the
      `case-assistant`/`widget-case-assistant` entries exactly
- [x] 5.5 Vitest for `cmmnHelpers.js`: grouping, badge mapping, enable-able filtering

## 6. i18n

- [x] 6.1 English source strings via `t('procest', '...')` in the new Vue panel
- [x] 6.2 `l10n/en.json` + `l10n/nl.json` — matching key pairs for every new string (`npm run
      test:l10n` extraction-drift check)

## 7. Verification

- [x] 7.1 PHPUnit full suite via `procest-php83-zip` container, `phpunit-unit.xml` — diff test
      NAMES vs a pristine `origin/development` baseline run with the same filter; only NEW
      failures block
- [x] 7.2 `npm run test:unit` (Vitest) green for the new helper tests
- [x] 7.3 `npm run build` succeeds
- [x] 7.4 `npm run test:l10n` passes (en/nl parity)
- [x] 7.5 `grep -rn '<<<<<<<'` clean after merging `origin/development`
