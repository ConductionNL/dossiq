# Design — Member 02: eHerkenning Authentication (code)

## Scope

Implement the eHerkenning login flow and session lifecycle, reading the `Supplier` and
`SupplierUser` schemas declared in member 01.

## Declarative-first (ADR-031) note

No new schema is declared here — member 01 owns the data model. This member is pure code:
services, a controller, and Vue pages. Records are read and written via the OpenRegister
ObjectService (ADR-001: `find`, `findAll`, `saveObject`), not bespoke mappers.

## Approach

- `SupplierAuthService.authenticateViaEHerkenning(code)` exchanges the authorization code for an
  ID token through the OpenConnector broker, extracts the `kvkNumber` claim.
- `validateKvKClaim(kvkNumber)` looks up the `Supplier` by `kvkNumber`, checks `status`.
- `createOrLinkSupplierUser(supplierRef, claim)` finds or creates a `SupplierUser`
  (default role `read_only`, `eherkenningLevel` from the token).
- `issueSessionToken(supplierUserId)` mints a session with 2-hour TTL and
  `requiresReAuthForFinancial = true`.
- `AuthController` exposes login/callback/logout/refresh; the Vue middleware refreshes the token
  15 minutes before expiry and redirects to login on hard expiry.

## Security (ADR-005)

- State token on the login redirect to prevent CSRF on the OAuth round-trip.
- Session cookie HttpOnly, Secure, SameSite=Strict.
- Re-auth flag gates financial views (enforced by members 04/07/12).
- No session created on invalid/blacklisted KvK; explicit Dutch error copy.
- Refresh events written to the audit trail.
