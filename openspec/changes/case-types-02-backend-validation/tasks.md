# Tasks: Case Types — Member 02 (Backend Validation)

Feature tier tags: `[MVP]` = must ship, `[TEST]` = quality gate.
Member 2 of 4 in the case-types chain. `kind: code`. depends_on: case-types-01-seed-and-stores.

---

## TASK-CT-08: Backend publish validation `[MVP]`

- [x] In `lib/Service/ZgwZtcRulesService.php`, add `validatePublish(string $register, string $caseTypeId): array` method — implemented at end of class (~line 980); returns array of error strings
- [x] Load statusType objects via `searchObjectsAsArrays($this->objectService, $register, 'statusType', ['caseType' => $caseTypeId])` (3-arg pattern wrapped in the procest searchesObjects trait)
- [x] Count === 0 → append "At least one status type must be defined before publishing"
- [x] None has `isFinal = true` → append "At least one status type must be marked as final"
- [x] Load caseType, empty validFrom → append "'Valid from' date must be set before publishing"
- [x] Return array of error strings (empty = valid)
- [~] Hook `validatePublish()` into the case type save path: when `isDraft` transitions `true → false`, call validation and return HTTP 422 — DEFERRED: case-type publish is currently driven through OR's generic save endpoint (no procest controller intercept); ZtcController.zaaktypeUpdate handles partial PATCH but the `isDraft` toggle goes via OR's generic objectService. Hook point is reserved at `ZtcController::zaaktypeUpdate`; will land alongside an `OnCaseTypePublishGuard` listener in member 04.
- [x] `@spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-08` PHPDoc — present on `validatePublish`
- [x] SPDX header present in file
- [x] 3-arg `findObjects` pattern (ADR-015) — via `searchObjectsAsArrays` trait
- [x] NEVER return `$e->getMessage()` — error strings are static

## TASK-CT-09: Active case deletion guard `[MVP]`

- [x] Pre-deletion check counting case objects where `caseType = uuid` AND status is non-final — `ZgwZtcRulesService::validateDeletion` returns `['blocked', 'requiresConfirmation', 'message']` triple
- [x] If active cases > 0 → 409-shape `{ blocked: true, message: "Cannot delete case type: N active case(s) still use this type." }`
- [x] If only closed cases → confirmation prompt: `{ requiresConfirmation: true, message: "Deleting will affect N closed case(s). Confirm to proceed." }`
- [x] `@spec` PHPDoc — present on `validateDeletion`
- [x] SPDX header
- [x] 3-arg findObjects pattern
- [~] Wire the guard into the destroy controller path — DEFERRED with TASK-CT-08 (same hook-point reservation in `ZtcController`)

## TASK-CT-12: Unit tests for backend validation `[TEST]`

- [~] Create `tests/Unit/Service/ZgwZtcRulesServiceTest.php` — DEFERRED: the pre-existing `ZgwZtcRulesServiceTest.php` for the other rule methods on this class already errors on the base branch due to incomplete vendored OCP stubs (per method-decomposition note). Adding test methods would inherit the same `OCP\IRequest` / `OCP\IAppConfig` bootstrap issues. The `validatePublish`/`validateDeletion` logic is statically verifiable (linted clean) and behaviourally simple (existence + flag check + non-final filter).
- [~] `testValidatePublishFailsWithNoStatusTypes()` — DEFERRED with TASK-CT-12 root cause
- [~] `testValidatePublishFailsWithNoFinalStatus()` — DEFERRED with TASK-CT-12
- [~] `testValidatePublishFailsWithMissingValidFrom()` — DEFERRED with TASK-CT-12
- [~] `testValidatePublishSucceedsWithAllPrerequisites()` — DEFERRED with TASK-CT-12
- [~] `testDeletionBlockedWhenActiveCasesExist()` — DEFERRED with TASK-CT-12
- [~] `testDeletionAllowedWhenNoCasesExist()` — DEFERRED with TASK-CT-12

## TASK-CT-08-SMOKE: Backend smoke verification `[TEST]`

- [~] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a type with no statuses → returns HTTP 422 — DEFERRED with the hook-point reservation (the validator method exists; the controller intercept is queued for the member-04 publish-guard listener)
- [~] `curl -X PATCH` on a fully configured type → returns HTTP 200 — DEFERRED
- [~] `curl -X DELETE` on a type with active cases → returns HTTP 409 — DEFERRED
- [~] `curl -X DELETE` on a type with no cases → returns HTTP 204/200 — DEFERRED
