# Tasks — Member 02: eHerkenning Authentication (code)

Traces to giant tasks 1.1 and 4.2; spec REQ-001.

- [~] Implement `SupplierAuthService.authenticateViaEHerkenning(code)` — exchange code, extract KvK claim — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierAuthService.validateKvKClaim(kvkNumber)` — look up Supplier, check status — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierAuthService.createOrLinkSupplierUser(supplierRef, eherkenningClaim)` — default role read_only — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierAuthService.issueSessionToken(supplierUserId)` — 2-hour TTL + financial re-auth flag — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `AuthController` endpoints: GET /auth/eherkenning-login, GET /auth/callback, POST /auth/logout, POST /auth/refresh — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Set CSRF state token on the login redirect; set HttpOnly/Secure/SameSite=Strict session cookie — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build login page Vue component: eHerkenning button, error display, loading state — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build callback page: validate token, store session, redirect to dashboard — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement session middleware: validate token per protected route, refresh 15 min before expiry, re-auth on hard expiry — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit Dutch error copy for unknown / inactive / blacklisted suppliers with no session created — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Log session refresh and logout events to the audit trail — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Coordinate with OpenConnector for eHerkenning broker endpoint + KvK API + sandbox credentials — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test eHerkenning sandbox round-trip and session refresh at the 1h45m mark — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test logout and session invalidation — deferred to downstream cycle / fleet-wide adoption (handoff)
