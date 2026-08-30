# security-hardening Specification

## Purpose
TBD - created by archiving change dossiq-security-hardening. Update Purpose after archive.
## Requirements
### Requirement: REQ-PSH-001 — Supplier portal endpoints derive scope from the authenticated session, not a client parameter

The supplier portal SHALL resolve the calling supplier's `supplierRef` from the
server-validated portal session (the bearer JWT), and SHALL NOT trust a client-supplied
`supplierRef` request parameter for authorization. Every supplier-scoped endpoint SHALL
fail CLOSED (HTTP 401) when no valid supplier session is present.

#### Scenario: A read endpoint scopes to the session supplier

- **GIVEN** an authenticated supplier session whose validated JWT carries `supplierRef = A`
- **WHEN** the caller requests the tender list and passes `supplierRef = B` in the query
- **THEN** the endpoint SHALL scope the read to supplier `A` (the session value)
- **AND** the client-supplied `supplierRef = B` SHALL be ignored

@e2e exclude Backend authorization invariant (session-derived supplierRef in SupplierPortalController via SupplierSessionService::requireSupplierRef) verified by PHPUnit; not a UI flow exercisable by a dossiq-only Playwright e2e.

#### Scenario: A request without a valid supplier session is denied

- **GIVEN** a request to a supplier portal endpoint with no valid bearer token
- **WHEN** the endpoint is invoked
- **THEN** it SHALL return HTTP 401
- **AND** it SHALL NOT read or write any supplier-scoped object

@e2e exclude Fail-closed 401 on missing bearer (SupplierAuthMiddleware) is a server middleware contract verified by PHPUnit; no UI surface to exercise in a dossiq-only e2e.

#### Scenario: The supplier auth middleware covers the portal controllers

- **GIVEN** the registered `SupplierAuthMiddleware`
- **WHEN** a supplier portal controller method is dispatched
- **THEN** the middleware SHALL validate the bearer token before the controller body runs
- **AND** it SHALL inject the server-trusted `supplierRef` and role into the request

@e2e exclude Middleware registration/coverage over the three portal controllers is a static wiring assertion (Application.php) verified by PHPUnit + the route-auth gate; not UI-exercisable.

### Requirement: REQ-PSH-002 — The citizen portal uses a guard-prefixed session resolver

The `ZaakportaalController` SHALL resolve the citizen subject through a guard-prefixed
resolver (`requireAuthenticatedSubject()`) that fails closed when no authenticated user is
present, so its server-derived scoping is explicit and recognisable as the IDOR guard.

#### Scenario: An unauthenticated citizen request is denied

- **GIVEN** a citizen portal request with no authenticated Nextcloud user
- **WHEN** a `ZaakportaalController` endpoint resolves the subject
- **THEN** the resolver SHALL throw and the endpoint SHALL return an error response
- **AND** no case, message, request or preference SHALL be read or written

@e2e exclude Citizen-portal fail-closed subject resolver (ZaakportaalController::requireAuthenticatedSubject) is a backend IDOR guard verified by PHPUnit; not a dossiq-only UI flow.

### Requirement: REQ-PSH-003 — KvK and logo validators are wired into their write paths

`SupplierMasterDataMutationService::validateKvk()` SHALL be invoked before a KvK
master-data verification is submitted, and `TenantConfigurationService::validateLogoUpload()`
SHALL be invoked before a tenant logo is persisted. Both SHALL fail closed (reject the
write) on invalid input.

#### Scenario: An invalid KvK number is rejected on the verification path

- **GIVEN** a supplier submitting a KvK master-data verification with a malformed KvK number
- **WHEN** the verification is submitted
- **THEN** `validateKvk()` SHALL run and the submission SHALL be rejected
- **AND** no verification case SHALL be created

@e2e exclude KvK validator wiring on the write path (SupplierMasterDataMutationService) is server-side input validation verified by PHPUnit; not UI-exercisable.

#### Scenario: An oversized or wrong-type logo is rejected on the branding path

- **GIVEN** a tenant branding update carrying a logo of a disallowed MIME type or over the size cap
- **WHEN** the branding is sanitised and saved
- **THEN** `validateLogoUpload()` SHALL run and the save SHALL be rejected
- **AND** the offending logo SHALL NOT be persisted

@e2e exclude Logo MIME/size validator wiring on the branding write path (TenantConfigurationService::sanitiseBranding) is server-side validation verified by PHPUnit; not UI-exercisable.

### Requirement: REQ-PSH-004 — Auth/role resolvers fail closed on backend errors

The auth/role resolvers SHALL NOT silently fall open: when the backing lookup throws, the
resolver SHALL NOT return `null` from inside the `catch (\Throwable)` block as if the role
were simply absent. The single caller SHALL treat a resolver failure as DENY.

#### Scenario: A transient backend error denies the role-gated action

- **GIVEN** the role lookup backing `resolveUserRole()` raises a `\Throwable`
- **WHEN** the mandate-matrix validation resolves the caller's role
- **THEN** the action SHALL be denied (`allowed = false`)
- **AND** the failure SHALL be logged as fail-closed

@e2e exclude Fail-closed-on-Throwable in the role resolver (TenantAuthenticationService::resolveUserRole) is a backend auth-resolver contract verified by PHPUnit + the unsafe-auth-resolver gate; not UI-exercisable.

### Requirement: REQ-PSH-005 — DSO dialogs are isolated, not inlined

The DSO omgevingsloket dialogs SHALL live as isolated components under `src/dialogs/`, and
the stale inline-`NcModal` duplicates under `src/views/dso/` SHALL be removed. The only
external consumer SHALL import the canonical isolated dialog.

#### Scenario: The dashboard uses the canonical isolated dialog

- **GIVEN** the VTH dashboard opening a DSO case detail
- **WHEN** the dashboard renders the case-detail dialog
- **THEN** it SHALL import the canonical component from `src/dialogs/`
- **AND** the stale `src/views/dso/` inline-modal duplicates SHALL NOT remain in the tree

@e2e exclude Modal-isolation refactor (canonical src/dialogs/DsoCaseDetail.vue, stale src/views/dso/ duplicates removed) is a static structure assertion verified by the modal-isolation gate + vitest; not a behavioural UI flow.

