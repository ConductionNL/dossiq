# Tasks — Member 04: Supplier Scope & API Security (code)

Traces to giant tasks 3.1 and 4.1; spec REQ-009-B/C.

- [~] Implement `SupplierScopeService.getCurrentSupplier()` — resolve supplier from session — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierScopeService.getSupplierCases(supplierRef)` — supplierRef-filtered OR query — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierScopeService.validateSupplierAccess(caseId, supplierRef)` — 403 on mismatch — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `CaseSupplierLookup` — find SupplierTender/Contract/Invoice for a case — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierAuthMiddleware` — validate session, inject current supplier, 401 on missing — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `RateLimitMiddleware` — 100 req/min/IP, 429 on excess — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `AuditLoggingMiddleware` — log POST/PUT/DELETE with user/timestamp/action/old-new — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Mask sensitive fields in logs: IBAN last 4, email domain only, phone partial — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test scope validation: supplier A cannot access supplier B's cases — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test edge cases: suspended supplier, newly onboarded supplier, empty result set — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test auth middleware with expired/invalid tokens (401) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test rate limiting under simulated traffic (429 on 101st) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test audit logging across mutation types; verify no full IBAN in logs — deferred to downstream cycle / fleet-wide adoption (handoff)
