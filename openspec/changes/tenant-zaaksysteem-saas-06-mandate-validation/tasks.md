# Tasks: tenant-zaaksysteem-saas-06-mandate-validation

Member 6 of 12 (code). Depends on member 05. Traces to giant Task 6 + REQ-002-D, REQ-006-D.

## 1. Mandate validation service

- [ ] Implement `TenantAuthenticationService.validateMandateMatrix(tenantId, userId, action)`
- [ ] Load the active `TenantMandate` for the tenant via OpenRegister
- [ ] Resolve the user's role and check it against the matrix for the requested action
- [ ] Return `{allowed: bool, reason: string}`; fail closed on unresolved matrix / service error

## 2. Mandate middleware + audit

- [ ] Implement `MandateValidationMiddleware` for mandate-requiring requests (edit, status transition, delete)
- [ ] On deny → HTTP 403 with the reason message
- [ ] Log every mandate decision (allow + deny) to the audit trail (tenant_id, user, action, outcome)
- [ ] Ensure the validation method has exactly one caller (no orphan auth)

## 3. Tests

- [ ] Unit test: validation across roles (Behandelaar, Vergunningverlener) × actions (create, edit, status_update, delete)
- [ ] Unit test: fail-closed when no matrix or service error
- [ ] Integration test: middleware blocks unauthorised action (403) + logs; allows authorised action
