# Tasks — Member 02: eHerkenning Authentication (code)

Traces to giant tasks 1.1 and 4.2; spec REQ-001.

- [ ] Implement `SupplierAuthService.authenticateViaEHerkenning(code)` — exchange code, extract KvK claim
- [ ] Implement `SupplierAuthService.validateKvKClaim(kvkNumber)` — look up Supplier, check status
- [ ] Implement `SupplierAuthService.createOrLinkSupplierUser(supplierRef, eherkenningClaim)` — default role read_only
- [ ] Implement `SupplierAuthService.issueSessionToken(supplierUserId)` — 2-hour TTL + financial re-auth flag
- [ ] Create `AuthController` endpoints: GET /auth/eherkenning-login, GET /auth/callback, POST /auth/logout, POST /auth/refresh
- [ ] Set CSRF state token on the login redirect; set HttpOnly/Secure/SameSite=Strict session cookie
- [ ] Build login page Vue component: eHerkenning button, error display, loading state
- [ ] Build callback page: validate token, store session, redirect to dashboard
- [ ] Implement session middleware: validate token per protected route, refresh 15 min before expiry, re-auth on hard expiry
- [ ] Emit Dutch error copy for unknown / inactive / blacklisted suppliers with no session created
- [ ] Log session refresh and logout events to the audit trail
- [ ] Coordinate with OpenConnector for eHerkenning broker endpoint + KvK API + sandbox credentials
- [ ] Test eHerkenning sandbox round-trip and session refresh at the 1h45m mark
- [ ] Test logout and session invalidation
