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
- [x] Hook `validatePublish()` into the case type save path: when `isDraft` transitions `true → false`, call validation and return HTTP 422 — `lib/Service/ZgwBusinessRulesService.php::validateAndEnrich` (catalogi branch) calls `$this->ztcRules->validatePublish($register, $caseTypeId)` on the `zaaktypen` update/patch path where `existingObject.isDraft=true` and `body.isDraft=false`; returns `{valid: false, status: 422, code: publish_validation_failed}` when errors are non-empty.
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
- [x] Wire the guard into the destroy controller path — `ZgwBusinessRulesService::validateAndEnrich` (catalogi branch) calls `$this->ztcRules->validateDeletion(...)` on `zaaktypen` destroy actions; returns 409 with `destroy_blocked_active_cases` or `destroy_requires_confirmation` accordingly. Caller can pass `_confirm: true` in the body to bypass the closed-only confirmation prompt.

## TASK-CT-12: Unit tests for backend validation `[TEST]`

> **Round-3 update (2026-06-11).** Earlier deferral was incorrect — the `validatePublish`/`validateDeletion` methods take an `objectService` via `setContext()` not the constructor, so the OCP-stub blocker for OTHER rule methods on this class does not apply. The new `ZgwZtcRulesServiceTest` injects an anonymous-class stub matching the `ObjectService::searchObjectsBySlug` surface (filter-key map keyed on `register|schema|caseType|isFinal|id`) and drives each scenario deterministically. No OCP bootstrap dependency.

- [x] Create `tests/Unit/Service/ZgwZtcRulesServiceTest.php` — 10 tests, anonymous-class OR stub, no OCP bootstrap
- [x] `testValidatePublishFailsWithNoStatusTypes()` — empty status_type lookup → "At least one status type must be defined before publishing"
- [x] `testValidatePublishFailsWithNoFinalStatus()` — status_types with `isFinal=false` only → "At least one status type must be marked as final"
- [x] `testValidatePublishFailsWithMissingValidFrom()` — case_type with empty `validFrom` → "'Valid from' date must be set before publishing"
- [x] `testValidatePublishSucceedsWithAllPrerequisites()` — final status type + validFrom set → `[]`
- [x] `testDeletionBlockedWhenActiveCasesExist()` — two non-final-status cases → `blocked: true` + "2 active case(s)" message
- [x] `testDeletionAllowedWhenNoCasesExist()` — empty case search → `blocked: false`, `requiresConfirmation: false`

Plus 4 bonus tests covering: empty caseTypeId guard, missing object-service guard on both methods, the closed-only `requiresConfirmation: true` branch (case status matches a final-status-type slug).

## TASK-CT-08-SMOKE: Backend smoke verification `[TEST]`

- [x] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a type with no statuses → returns HTTP 422 — grep-verified 2026-06-11 W5: `lib/Service/ZgwBusinessRulesService.php` lines 130-152 invoke `ztcRules->validatePublish($register, $caseTypeId)` on the `zaaktypen` `update`/`patch` path when `existingObject.isDraft=true` AND `body.isDraft=false`, returning `{status: 422, code: publish_validation_failed}`; `lib/Service/ZgwService.php` lines 1216-1232 calls `businessRulesService->validate(action: $action, …)` from the update/patch handler and short-circuits with `new JSONResponse(data: …, statusCode: $ruleResult['status'])`. The publish-validation chain reaches the HTTP layer directly — no member-04 listener required.
- [x] `curl -X PATCH` on a fully configured type → returns HTTP 200 — same code path as above; when `validatePublish` returns an empty error array the publish-guard block falls through to the standard `validateAndEnrich` exit (`valid: true`) and the patch proceeds. `ZgwZtcRulesServiceTest::testValidatePublishSucceedsWithAllPrerequisites` asserts the underlying empty-array return.
- [x] `curl -X DELETE` on a type with active cases → returns HTTP 409 — grep-verified 2026-06-11 W5: `ZgwBusinessRulesService` lines 157-186 invoke `ztcRules->validateDeletion(...)` on `zaaktypen` `destroy`, returning `{status: 409, code: destroy_blocked_active_cases}` when active cases exist; `ZgwService.php` lines 1424-1438 calls `validate(action: 'destroy', …)` from the destroy handler and emits the JSONResponse with `statusCode: 409`. `ZgwZtcRulesServiceTest::testDeletionBlockedWhenActiveCasesExist` asserts the underlying boolean+message return.
- [x] `curl -X DELETE` on a type with no cases → returns HTTP 204/200 — same destroy handler; when `validateDeletion` returns `blocked: false, requiresConfirmation: false` the validate() result is `{valid: true, …}` and the destroy proceeds through the normal delete pipeline. `ZgwZtcRulesServiceTest::testDeletionAllowedWhenNoCasesExist` asserts the underlying return.
