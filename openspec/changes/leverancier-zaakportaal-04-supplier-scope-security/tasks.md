# Tasks — Member 04: Supplier Scope & API Security (code)

Traces to giant tasks 3.1 and 4.1; spec REQ-009-B/C.

- [ ] Implement `SupplierScopeService.getCurrentSupplier()` — resolve supplier from session
- [ ] Implement `SupplierScopeService.getSupplierCases(supplierRef)` — supplierRef-filtered OR query
- [ ] Implement `SupplierScopeService.validateSupplierAccess(caseId, supplierRef)` — 403 on mismatch
- [ ] Implement `CaseSupplierLookup` — find SupplierTender/Contract/Invoice for a case
- [ ] Implement `SupplierAuthMiddleware` — validate session, inject current supplier, 401 on missing
- [ ] Implement `RateLimitMiddleware` — 100 req/min/IP, 429 on excess
- [ ] Implement `AuditLoggingMiddleware` — log POST/PUT/DELETE with user/timestamp/action/old-new
- [ ] Mask sensitive fields in logs: IBAN last 4, email domain only, phone partial
- [ ] Test scope validation: supplier A cannot access supplier B's cases
- [ ] Test edge cases: suspended supplier, newly onboarded supplier, empty result set
- [ ] Test auth middleware with expired/invalid tokens (401)
- [ ] Test rate limiting under simulated traffic (429 on 101st)
- [ ] Test audit logging across mutation types; verify no full IBAN in logs
