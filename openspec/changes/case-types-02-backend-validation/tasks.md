# Tasks: Case Types — Member 02 (Backend Validation)

Feature tier tags: `[MVP]` = must ship, `[TEST]` = quality gate.
Member 2 of 4 in the case-types chain. `kind: code`. depends_on: case-types-01-seed-and-stores.

---

## TASK-CT-08: Backend publish validation `[MVP]`

- [ ] In `lib/Service/ZgwZtcRulesService.php`, add `validatePublish(string $register, string $caseTypeId): array` method
  - Load statusType objects: `$this->objectService->findObjects($register, 'statusType', ['caseType' => $caseTypeId])`
  - If count === 0 → append "At least one status type must be defined before publishing"
  - If none has `isFinal = true` → append "At least one status type must be marked as final"
  - Load caseType object; if `validFrom` is empty → append "'Valid from' date must be set before publishing"
  - Return array of error strings (empty = valid)
- [ ] Hook `validatePublish()` into the case type save path: when `isDraft` transitions `true → false`, call validation and return HTTP 422 with `{ "errors": [...] }` if non-empty
- [ ] `@spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-08` PHPDoc on new method
- [ ] SPDX header present: `// SPDX-License-Identifier: EUPL-1.2`
- [ ] Use `$this->objectService->findObjects($register, $schema, $params)` — 3 positional args (ADR-015)
- [ ] NEVER return `$e->getMessage()` in JSONResponse — use static error strings
- **Spec ref**: REQ-CT-02b (CT-02b-01 through CT-02b-05)
- **Files**: `lib/Service/ZgwZtcRulesService.php`
- **Acceptance**: `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a case type with no status types returns HTTP 422 with "At least one status type must be defined before publishing"

---

## TASK-CT-09: Active case deletion guard `[MVP]`

- [ ] In the case type deletion path (controller or service), add a pre-deletion check:
  - Count case objects where `caseType = uuid` AND status is non-final using `findObjects()`
  - If count > 0: return HTTP 409 with `{ "message": "Cannot delete case type '...': {n} active cases are using this type. Close or reassign all cases first." }`
  - Also count all cases (including final); if > 0 but only closed: return HTTP 200 with `{ "warning": "...", "requiresConfirmation": true }` and require `?confirm=true` query param to proceed
- [ ] `@spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-09` PHPDoc on guard logic
- [ ] SPDX header present
- [ ] 3-arg `findObjects()` pattern (ADR-015)
- **Spec ref**: REQ-CT-01d (CT-01d-01 through CT-01d-03)
- **Files**: `lib/Service/ZgwZtcRulesService.php` or `lib/Controller/ZtcController.php`
- **Acceptance**: Attempting to delete a case type with active cases via API returns HTTP 409; deleting with only closed cases returns the confirmation warning; deleting with no cases succeeds

---

## TASK-CT-12: Unit tests for backend validation `[TEST]`

- [ ] Create `tests/Unit/Service/ZgwZtcRulesServiceTest.php`
- [ ] Add SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
- [ ] Test `validatePublish()` — at least 4 test methods:
  - `testValidatePublishFailsWithNoStatusTypes()`
  - `testValidatePublishFailsWithNoFinalStatus()`
  - `testValidatePublishFailsWithMissingValidFrom()`
  - `testValidatePublishSucceedsWithAllPrerequisites()`
- [ ] Test `validateDeletion()` — at least 2 test methods:
  - `testDeletionBlockedWhenActiveCasesExist()`
  - `testDeletionAllowedWhenNoCasesExist()`
- [ ] All tests pass under `composer check:strict`
- [ ] `@spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-12` in test file header
- **Spec ref**: ADR-008-testing; REQ-CT-02b; REQ-CT-01d
- **Files**: `tests/Unit/Service/ZgwZtcRulesServiceTest.php`
- **Acceptance**: `composer test` passes; ≥6 test methods; coverage includes both happy paths and error paths

---

## TASK-CT-08-SMOKE: Backend smoke verification `[TEST]`

- [ ] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a type with no statuses → returns HTTP 422
- [ ] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a fully configured type → returns HTTP 200
- [ ] `curl -X DELETE .../api/case-types/{uuid}` on a type with active cases → returns HTTP 409
- [ ] `curl -X DELETE .../api/case-types/{uuid}` on a type with no cases → returns HTTP 204 or 200
- **Spec ref**: ADR-008 smoke testing rules
- **Acceptance**: All curl commands return the expected status codes
