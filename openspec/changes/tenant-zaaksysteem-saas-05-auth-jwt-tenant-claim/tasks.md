# Tasks: tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantJwtService` (HMAC HS256 encode/decode, ttl-aware, tenant claim injection, `createTokenFromSaml()` mapper); `TenantClaimValidationMiddleware` (validates JWT signature, compares `tenant_id` claim against the request-bound tenant, fail-closed 403 + security log + rate-limit counter alert at 5 fails/hour/IP); `TenantClaimMismatchException` (always 403). Service registered through a custom factory so the signing secret comes from `procest.jwt_signing_secret` app config (falls back to NC system secret in dev). Middleware registered after TenantContext in `Application.php`. 9 new unit tests cover encode/decode round-trip, forged-signature rejection, malformed JWT rejection, expired JWT, eHerkenning SAML mapping (level → role), and missing-field rejection. Marked [~] for cross-app blockers — live SAML wiring + ICache integration test + cross-tenant Postman scenario are deferred to chain member 12.

Member 5 of 12 (code). Depends on member 04. Traces to giant Task 4 + Task 5 + REQ-002-B/C, REQ-006-B/C.

## 1. JWT tenant-claim injection

- [x] Enhance `AuthenticationService` to accept tenant context during login — `TenantJwtService::createToken(subject, tenantId, tenantSlug, roles, ttl)` is the SaaS-shape entry point (lives alongside `ZgwJwtValidator` to avoid coupling the older ZGW auth)
- [x] Inject `tenant_id`, `tenant_slug`, roles into the JWT payload on token creation — verified by `testCreateTokenAndValidateRoundTrip` (claims persist verbatim through encode/decode)
- [x] Add `createTokenFromSAML()` mapping eHerkenning assertion (role, level) → tenant-scoped JWT — `createTokenFromSaml()` adds `eh:level:<n>` to the roles array
- [x] Sign the JWT with the Procest private key — HMAC HS256 using a server-side secret resolved from `procest.jwt_signing_secret` app config (factory in `Application.php`)

## 2. Validation middleware

- [x] Enhance auth path to validate the JWT signature and extract tenant_id (forged → 401) — `TenantJwtService::validate()` rejects forged signature with `RuntimeException`; the bearer auth layer surfaces as 401
- [x] Hand the extracted tenant_id to the request-scoped tenant context — `TenantClaimValidationMiddleware::beforeController()` reads the bearer header, validates, then cross-checks against `TenantContext::getTenantId()`
- [x] Implement `TenantClaimValidationMiddleware` comparing JWT tenant_id with request tenant (URL/domain/header)
- [x] Mismatch → HTTP 403 Forbidden — `TenantClaimMismatchException` → `afterException()` returns 403 JSON
- [x] Add security logging for cross-tenant attempts (IP, timestamp, attempted tenant_id, user) — `logSecurityIncident()`
- [x] Implement rate-limit counter (fail closed) + alert after 5 failures/hour from one IP — `bumpFailureCounter()` uses `ICacheFactory::createLocal()` with a 3600s window and emits a `LogLevel::ALERT` when `FAIL_THRESHOLD` is reached

## 3. Tests

- [x] Unit test: JWT creation + SAML mapping with tenant claims (`TenantJwtServiceTest::testCreateTokenAndValidateRoundTrip`, `testCreateTokenFromSamlMapsAssertion`)
- [x] Unit test: JWT validation + forged-signature rejection (`testValidateRejectsForgedSignature`, `testValidateRejectsMalformed`, `testValidateRejectsExpired`)
- [~] Integration test: cross-tenant token rejection (403) + security log entry — requires a Newman / Postman fixture and live cache; deferred to chain member 12
- [~] Integration test: rate-limit alert after N failed attempts — same fixture; deferred to chain member 12
