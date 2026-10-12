# Tasks: retire the CMMN runtime and the casePlanState blob

Ordering matters: group 1 ships first (bridge), group 2 drains, groups 3 to 5 are the removal release and only land once the drain report shows zero populated blobs and zero unmappable cases (design.md section 4).

## 1. Bridge: consume openregister's case layer

- [x] 1.1 `lib/Service/CasePlanProjectionService.php`: at case start for `handlingModel: cmmn`, project the published `caseModel` onto openregister plan items via `/api/cases`; convert sentries per the closed operator table in design.md section 2, refusing and reporting any operator or on-part outside it. Projection fixtures per operator.
- [x] 1.2 Repoint the frontend: the `cmmn-case-plan` widget slot renders the plan from openregister's `/api/cases` (read plan, transition, enable discretionary, attach ad-hoc, list enableable); error state fails closed with retry when openregister is unreachable. Vitest covers the error path.
- [x] 1.3 Bridge read preference: prefer openregister rows when the case has them, fall back to the local engine only while a blob is present; ship `occ dossiq:cmmn:rollback-case-plans` (reverse projection, rows to blob) for R1 rollback.

Ticked 12 Oct 2026 (lane L7) against HEAD. 1.1: `lib/Service/CasePlanProjectionService.php` with `tests/Unit/Service/CasePlanProjectionServiceTest.php` (a fixture per operator), called at case start by `lib/Listener/CasePlanProjectionListener.php`. 1.2: `src/components/case/CasePlanPanel.vue` owns the `cmmn-case-plan` slot over `src/services/casePlanApi.js`; `tests/vitest/casePlanPanel.spec.js` pins the fail-closed error path. 1.3 shipped in #2551 and is retired again by 3.1: the read preference, its `cmmn_prefer_openregister_case_plan` flag and the rollback command existed only for the bridge release.

## 2. Drain: migrate in-flight cases

- [x] 2.1 `occ dossiq:cmmn:migrate-case-plans` (`--dry-run`, `--strict`) plus repair step: per case, decode the blob, recover item metadata from the backing caseModel, create only the missing openregister rows keyed on (case uuid, item id) with states carried verbatim, write case-file values as declared case properties, import `eventLog` into the audit, verify, then clear the blob as the per-case commit point.
- [x] 2.2 Unmappable handling: undecodable blob, orphan item id, unknown state or unconvertible sentry leaves the case intact and reports uuid plus reason; `--strict` exits non-zero, the repair step logs and continues.
- [x] 2.3 Idempotency proof: PHPUnit runs the migration twice over the fixture set (populated, half-written, empty, unmappable) asserting identical rows, no duplicate audit entries and untouched unmappable cases.

Built 10 Oct 2026 (lane L5) against openregister PR #4544 (`CasePlanService::ensureItems()`, `createPlanAsSystem()`, `getPlanAsSystem()`); it lands after that PR. `lib/Service/CasePlanDrain/CasePlanMigrationService.php` (orchestration, runs as system), `lib/Service/CasePlanDrain/CasePlanBlob.php` (decode, states onto the definition, event log to history, verification), `lib/Command/MigrateCasePlansCommand.php`, `lib/Repair/MigrateCmmnCasePlans.php` (post-migration only). The definition comes from `CasePlanProjectionService::definitionForCaseType()`, the same one the projection uses at case start. Tests: `tests/Unit/Service/CasePlanDrain/CasePlanMigrationServiceTest.php` (the double run over populated, half-written, empty, undecodable, orphan, already-projected-in-another-state and undeclared-case-file fixtures), `CasePlanBlobTest.php`, `tests/Unit/Command/MigrateCasePlansCommandTest.php`, `tests/Unit/Repair/MigrateCmmnCasePlansTest.php`.
Two deviations from design.md section 3, both OpenRegister's shape: (a) the audit rows are written by `ensureItems()` for the rows it creates, as `system:dossiq`, with the imported history marked `imported: true`; there is no separate `migration:retire-cmmn-caseplanstate` cause. (b) Case-file values and the cleared blob are one partial write after verification, and a case-file key the case object does not carry makes the case unmappable rather than risk OpenRegister dropping it. Also: the bridge now calls `createPlanAsSystem()` / `getPlanAsSystem()` (task 1.1 and 1.3 call sites); the first version passed `uid: null`, which OpenRegister refuses, so every case start had created nothing.

## 3. Remove: backend

- [x] 3.1 Delete `lib/Service/Cmmn/` (all nine classes), `lib/Controller/CmmnCaseController.php` and the five `/api/case/{caseId}/cmmn-plan*` entries in `appinfo/routes.php`; drop the R1 rollback command.
- [ ] 3.2 Remove `casePlanState` from `dossiq_register.json` and `dossiq_mock_register.json` and rewrite the `handlingModel` `cmmn` description to name the openregister case layer. Gated on the group 2 exit report: openregister strips undeclared properties on save, so this lands last.
- [x] 3.3 Retire the engine's unit tests (`tests/Unit/Service/Cmmn/`, `CmmnCaseControllerTest`) and `CmmnBpmnCoexistenceRegressionTest`; its BPMN-untouched invariant moves to the structural test and the e2e journey (5.3).

Built 12 Oct 2026 (lane L7), on merge train 2 (which carries the drain of group 2).
3.1: `lib/Service/Cmmn/` (nine classes), `lib/Controller/CmmnCaseController.php`, the five routes, `lib/Command/RollbackCasePlansCommand.php` with `lib/Service/CasePlanRollbackService.php`, and the `cmmn_prefer_openregister_case_plan` flag on `DashboardController` are gone. 3.3: `tests/Unit/Service/Cmmn/`, `CmmnCaseControllerTest`, `CasePlanRollbackServiceTest`, `DashboardControllerCasePlanFlagTest` and `CmmnBpmnCoexistenceRegressionTest` are gone; the BPMN-untouched invariant is `CmmnRetirementTest::testTheStatusTransitionPathNeverReachesTheCaseLayer` and the case-detail e2e (5.3).
3.2 is NOT built here and must not be. It is a separate pull request that removes the property and the drain in one commit, and it merges only after the drain report shows zero populated blobs and zero unmappable cases on every instance. `CmmnRetirementTest::testTheSchemaKeepsTheBlobForAsLongAsTheDrainShips` fails if one leaves without the other.

## 4. Remove: frontend, and the guard

- [x] 4.1 Delete `src/services/cmmnApi.js`, `src/views/cases/components/CmmnCasePlanPanel.vue` and `CmmnPlanItemNode.vue`, and their `registry.js` and `manifest.json` entries; verify the replacement panel from 1.2 owns the `cmmn-case-plan` slot and the built bundle contains none of the retired components.
- [x] 4.2 `tests/Unit/Service/CmmnRetirementTest.php` (class-catching guard): fails on any reference under `lib/` or `src/` to `OCA\Dossiq\Service\Cmmn`, `CmmnCaseController` or `casePlanState` outside a closed, reason-bearing allowlist; the allowlist holds only the migration's blob access until this release and is asserted empty after 3.1 and 3.2 land.

Built 12 Oct 2026 (lane L7). 4.1: `src/services/cmmnApi.js` and `src/utils/cmmnHelpers.js` (no reader left) are gone; `CmmnCasePlanPanel.vue` and `CmmnPlanItemNode.vue` had already gone with 1.2. `CasePlanPanel.vue` reads OpenRegister only; `src/services/casePlanSource.js` keeps one question, whether to ask at all. The bundle was not built for this (decision 139); `CmmnRetirementTest` asserts that no file under `src/` names the retired code, which is what a bundle is built from.
4.2: `tests/Unit/Service/CmmnRetirementTest.php`. It also scans `appinfo/`. The allowlist is not empty yet: it holds the four drain files and the five files that declare or describe the property, each with its reason, and no entry may name anything but the blob. It empties with 3.2.

## 5. E2E coverage

- [ ] 5.1 `tests/e2e/cmmn-retirement.spec.ts`: the retirement journey. Anchors `@e2e openspec/specs/retire-cmmn-caseplanstate/spec.md#a-cmmn-case-shows-the-plan-openregister-holds`, `#working-a-plan-item-through-the-panel` and `#the-old-routes-answer-404-after-removal` (open a CMMN case, work an item through the panel, probe the retired routes for 404).
- [ ] 5.2 Migration proof in the same spec: seed a case with a populated blob, run the migration, assert the case detail shows the same items in the same states from openregister rows; anchor `#a-drained-case-shows-the-same-plan`.
- [ ] 5.3 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: remove the `cmmn-plan` 409 allowances from its console and network assertions and anchor `#a-bpmn-case-is-untouched` on the BPMN case-detail assertions.

Written 12 Oct 2026 (lane L7), not run (live pass, decision 139): `tests/e2e/cmmn-retirement.spec.ts` (5.1, 5.2) and the updated `tests/e2e/case-detail-kpis-and-tabs.spec.ts` (5.3). The boxes stay open until the live pass has run them.

## 6. Quality

- [ ] 6.1 Analyzers green on the touched files: lint, phpcs, psalm, phpstan individually, phpmd per subdir; eslint and vitest for the frontend removals.
- [ ] 6.2 Hydra gates with `--scope-to-diff` report 0 FAIL on the diff; gate-16 `@spec` tags on every changed method point at `openspec/specs/retire-cmmn-caseplanstate/spec.md`.

## Re-verified 2026-10-12

Groups 1, 2, 3.1, 3.3 and 4 are built. Open: 3.2 (gated on the drain report, its own pull request), the live pass of group 5, and 6.
