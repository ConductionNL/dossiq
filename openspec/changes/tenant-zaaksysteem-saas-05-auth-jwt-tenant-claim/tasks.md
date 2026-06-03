# Tasks: tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim

Member 5 of 12 (code). Depends on member 04. Traces to giant Task 4 + Task 5 + REQ-002-B/C, REQ-006-B/C.

## 1. JWT tenant-claim injection

- [ ] Enhance `AuthenticationService` to accept tenant context during login
- [ ] Inject `tenant_id`, `tenant_slug`, roles into the JWT payload on token creation
- [ ] Add `createTokenFromSAML()` mapping eHerkenning assertion (role, level) → tenant-scoped JWT
- [ ] Sign the JWT with the Procest private key

## 2. Validation middleware

- [ ] Enhance `AuthenticateMiddleware` to validate the JWT signature and extract tenant_id (forged → 401)
- [ ] Hand the extracted tenant_id to the request-scoped tenant context
- [ ] Implement `TenantClaimValidationMiddleware` comparing JWT tenant_id with request tenant (URL/domain/header)
- [ ] Mismatch → HTTP 403 Forbidden
- [ ] Add security logging for cross-tenant attempts (IP, timestamp, attempted tenant_id, user)
- [ ] Implement rate-limit counter (fail closed) + alert after 5 failures/hour from one IP

## 3. Tests

- [ ] Unit test: JWT creation + SAML mapping with tenant claims
- [ ] Unit test: JWT validation + forged-signature rejection (401)
- [ ] Integration test: cross-tenant token rejection (403) + security log entry
- [ ] Integration test: rate-limit alert after N failed attempts
