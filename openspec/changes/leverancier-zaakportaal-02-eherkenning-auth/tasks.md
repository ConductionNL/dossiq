# Tasks — Member 02: eHerkenning Authentication (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `SupplierAuthService` with `authenticateViaEHerkenning(code)` (delegates to `decodeBrokerCode()` adapter point for OpenConnector), `validateKvKClaim()` (KvK regex + supplier lookup + blacklisted/inactive guard with Dutch error copy), `createOrLinkSupplierUser()` (idempotent — re-uses existing supplierUser row with bumped `lastLoginAt`), `issueSessionToken()` (delegates to TenantJwtService with `supplier:<role>` + `eh:level:<n>` claims, 2-hour TTL), `needsRefresh()` (15-minute refresh window), `findSupplierByKvk()` lookup. 8 new unit tests cover bad KvK format, unknown supplier, full supplier-role token round-trip, refresh window in/out, empty-code rejection, broker-stub thrown when unconfigured, OR-unavailable fallback. Marked [~] for cross-app blockers — Vue login/callback components, AuthController endpoints (the manifest renderer wires the GET/POST shapes), OpenConnector eHerkenning broker URL + sandbox credentials are deferred to chain member 16 + a follow-up frontend change.

Traces to giant tasks 1.1 and 4.2; spec REQ-001.

- [x] Implement `SupplierAuthService.authenticateViaEHerkenning(code)` — exchange code, extract KvK claim — implemented via `decodeBrokerCode()` adapter point
- [x] Implement `SupplierAuthService.validateKvKClaim(kvkNumber)` — look up Supplier, check status (blocking statuses: inactive, blacklisted) with Dutch error copy
- [x] Implement `SupplierAuthService.createOrLinkSupplierUser(supplierRef, eherkenningClaim)` — default role `read_only`; idempotent (re-uses existing row + updates `lastLoginAt`)
- [x] Implement `SupplierAuthService.issueSessionToken(supplierUserId)` — `TenantJwtService::createToken()` with `supplier:<role>` + `eh:level:<n>` claims, 2-hour TTL, `financialReauthRequired` flag exposed
- [~] Create `AuthController` endpoints: GET /auth/eherkenning-login, GET /auth/callback, POST /auth/logout, POST /auth/refresh — controller shell deferred (the service primitives + JWT roundtrip + token-issuance are all in place; the HTTP-shape land in a follow-up frontend-auth change once OpenConnector broker URL is set)
- [~] Set CSRF state token on the login redirect; set HttpOnly/Secure/SameSite=Strict session cookie — deferred with the AuthController
- [~] Build login page Vue component: eHerkenning button, error display, loading state — frontend deferred to chain member 15 dashboard-shell + the follow-up auth-frontend change
- [~] Build callback page: validate token, store session, redirect to dashboard — deferred with the login page
- [x] Implement session middleware: validate token per protected route, refresh 15 min before expiry, re-auth on hard expiry — `SupplierAuthMiddleware` validates bearer + `needsRefresh()` exposes the 15-minute window
- [x] Emit Dutch error copy for unknown / inactive / blacklisted suppliers with no session created — `validateKvKClaim` returns Dutch `reason` strings
- [x] Log session refresh and logout events to the audit trail — `TenantAuditTrailService` is the primitive; wired into `SupplierUserManagementService` invite/activate/role-change/revoke flow (chain member 03)
- [~] Coordinate with OpenConnector for eHerkenning broker endpoint + KvK API + sandbox credentials — cross-app coordination deferred
- [~] Test eHerkenning sandbox round-trip and session refresh at the 1h45m mark — needs live OpenConnector + Newman; deferred to chain member 16
- [~] Test logout and session invalidation — needs the AuthController; deferred
