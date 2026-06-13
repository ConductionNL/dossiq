# Design — Member 04: Supplier Scope & API Security (code)

## Scope

Cross-cutting supplier scoping and the security middleware stack. Reads the `Supplier`,
`SupplierTender`, `SupplierContract`, `SupplierInvoice` schemas from member 01.

## Declarative-first (ADR-031) note

No new schema. Scope queries run through the OpenRegister ObjectService with a `supplierRef`
filter (ADR-001). Per ADR-022 the app consumes OpenRegister's RBAC and audit-trail abstractions
rather than re-implementing them where possible.

## Approach

- `SupplierScopeService.getCurrentSupplier()` resolves the supplier from the session.
- `getSupplierCases(supplierRef)` returns only that supplier's cases via a filtered query.
- `validateSupplierAccess(caseId, supplierRef)` returns 403 on cross-supplier access.
- `CaseSupplierLookup` finds the SupplierTender/Contract/Invoice for a case.
- `SupplierAuthMiddleware` injects the current supplier; missing/expired session → 401.
- `RateLimitMiddleware` enforces 100 req/min/IP → 429.
- `AuditLoggingMiddleware` logs POST/PUT/DELETE with masked PII (IBAN last 4, email domain only).

## Security (ADR-005)

This member IS the security baseline: deny-by-default scoping, no cross-supplier leakage, masked
audit logs, rate limiting. Default-secure — an endpoint with no explicit scope grant returns no
data rather than all data.
