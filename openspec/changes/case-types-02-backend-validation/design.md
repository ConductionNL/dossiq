## Architecture

This member is `kind: code` per ADR-032. The centre of mass is PHP: two new
service methods plus their hook points and unit tests. It consumes the declarative
foundation from member 01 (seed data + registered schemas) but adds no declarative
surface of its own.

### Security (ADR-005)

Both guards are **server-authoritative** — they run in the backend service layer,
not the frontend. This closes the existing gap where the publish guard ran only in
the UI and the API could bypass it. Error responses use **static error strings**,
never `$e->getMessage()` in a JSONResponse (ADR-005 input/output hygiene).

### Backend Changes — `lib/Service/ZgwZtcRulesService.php`

Add `validatePublish(string $register, string $caseTypeId): array`:

1. Load all `statusType` objects where `caseType = $caseTypeId` via
   `$this->objectService->findObjects($register, 'statusType', ['caseType' => $caseTypeId])`
   (3 positional args, ADR-015).
2. If count === 0 → append "At least one status type must be defined before publishing".
3. If no `statusType` has `isFinal = true` → append "At least one status type must be marked as final".
4. Load the `caseType` object; if `validFrom` is empty → append "'Valid from' date must be set before publishing".
5. Return array of error strings (empty = valid).

Hook into the publish path: when `isDraft` transitions from `true` to `false` via
`ObjectService::saveObject()`, call `validatePublish()` and return HTTP 422 with
`{ "errors": [...] }` if the array is non-empty. All errors are returned together
so the UI can display them simultaneously.

Add `validateDeletion(string $register, string $caseTypeId): array`:

1. Count `case` objects where `caseType = $caseTypeId` AND status is non-final.
2. If count > 0 → return HTTP 409 with
   `{ "message": "Cannot delete case type '...': {n} active cases are using this type. Close or reassign all cases first." }`.
3. Count all `case` objects where `caseType = $caseTypeId`; if > 0 but only closed →
   return HTTP 200 with `{ "warning": "...", "requiresConfirmation": true }` and
   require `?confirm=true` to proceed.

Add `@spec` PHPDoc tags referencing this member's tasks on both new methods, and
SPDX headers on any new/edited files (ADR-014).

### Reuse Analysis

| Capability | Reused Component | Notes |
|---|---|---|
| Backend publish validation | Extend `ZgwZtcRulesService` | Add methods to existing service; no new class |
| Active case count | `ObjectService::findObjects()` with status filter | 3-arg positional form (ADR-015); no custom count endpoint |
| Status lookup | Existing statusType objects (seeded in member 01) | Forward query by `caseType` |

## Decisions

1. **Backend validation placement** — added to `ZgwZtcRulesService` rather than a
   new service class, to stay consistent with the existing ZTC rules pattern and
   avoid service proliferation.
2. **Deletion guard via query** — count-based check using `findObjects()` with a
   non-final status filter; no custom API endpoint or stored counter field.
3. **Static error strings** — never echo exception messages back to the client.
