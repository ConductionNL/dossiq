# Tasks: tenant-zaaksysteem-saas-06-mandate-validation

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantAuthenticationService` (loads active mandate matrix via OR, resolves user role, evaluates matrix with `tenant_admin/*=true → case_handler/{create,edit,status_update}=true → viewer={}` default template; fail-closed on every error path), `MandateValidationMiddleware` (HTTP-verb-to-action mapper, `/transition` URL-hint → `status_update`, 403 on deny via `MandateDeniedException`, every decision logged for audit). Middleware registered after the tenant-claim validator in `Application.php`. 11 new unit tests cover role-specific + wildcard role + wildcard action + fail-closed empty matrix + multi-candidate (wildcard-allow-wins) + verb mapping (POST/PATCH/DELETE/GET) + URL-hint status_update. Marked [~] for cross-app blockers — live OR mandate-matrix fetch + integration audit log assertion are deferred to chain member 12.

Member 6 of 12 (code). Depends on member 05. Traces to giant Task 6 + REQ-002-D, REQ-006-D.

## 1. Mandate validation service

- [x] Implement `TenantAuthenticationService::validateMandateMatrix(tenantId, userId, action)` — returns `{allowed: bool, reason: string}`
- [x] Load the active `TenantMandate` for the tenant via OpenRegister — `loadActiveMatrix()` filters by `tenantRef`, picks the row whose `effectiveFrom <= now <= effectiveTo`
- [x] Resolve the user's role and check it against the matrix for the requested action — `resolveUserRole()` reads the `tenantUser` row + `isAllowed()` consumes the matrix
- [x] Return `{allowed: bool, reason: string}`; fail closed on unresolved matrix / service error — every catch returns `allowed=false`; default matrix template is conservative (viewer has no permissions)

## 2. Mandate middleware + audit

- [x] Implement `MandateValidationMiddleware` for mandate-requiring requests (edit, status transition, delete) — verb-map: POST=create, PATCH/PUT=edit, DELETE=delete; URL hints `/transition` or `/status` → status_update; GET (read) not gated
- [x] On deny → HTTP 403 with the reason message — `MandateDeniedException` → JSON `{success: false, error: reason}` via `afterException()`
- [x] Log every mandate decision (allow + deny) to the audit trail — `logDecision()` logs `tenantId, userId, action, allowed, reason` at INFO so a SIEM can ingest it
- [x] Ensure the validation method has exactly one caller (no orphan auth) — `validateMandateMatrix()` is called only from `MandateValidationMiddleware::beforeController()`

## 3. Tests

- [x] Unit test: validation across roles × actions — `TenantAuthenticationServiceTest` covers role-specific (case_handler create:true / delete:false), wildcard action (tenant_admin *), wildcard role (* view:true), fail-closed empty matrix, multi-candidate (wildcard-allow-wins)
- [x] Unit test: fail-closed when no matrix or service error — `testValidateMandateMatrixFailsClosedWhenOrUnavailable` proves the OR-unavailable path returns `allowed=false`
- [~] Integration test: middleware blocks unauthorised action (403) + logs; allows authorised action — requires live OR + tenantUser/tenantMandate rows; deferred to chain member 12
