## Why

The Hydra mechanical gates flag a cluster of real, pre-existing security and quality
debt in procest that fails identically on `development` HEAD (these are not
false-positives):

- **gate-7 no-admin-idor** — the supplier portal (`SupplierPortalController`) and the
  supplier profile / contract controllers derive their data scope from a
  **client-supplied `supplierRef` request parameter** that is never validated against
  the authenticated supplier session. Any authenticated caller can pass an arbitrary
  `supplierRef` and read or write another supplier's tenders, invoices, contracts,
  KPIs and messages — a classic IDOR (OWASP A01:2021). The `SupplierAuthMiddleware`
  that was meant to bind the session was never registered and its covered-controller
  list is empty, so it is a complete no-op. The citizen portal
  (`ZaakportaalController`) is structurally safe (it derives the subject from the NC
  session) but does not use a guard-prefixed resolver, so the gate cannot recognise
  its guard.
- **gate-6 orphan-auth** — `SupplierMasterDataMutationService::validateKvk()` and
  `TenantConfigurationService::validateLogoUpload()` are implemented validators that
  are never called, so the KvK lookup and the logo MIME/size guard never actually run
  on the write paths they were written for.
- **gate-8 unsafe-auth-resolver** — `TenantAuthenticationService::resolveUserRole()`
  and `SupplierUserManagementService::updateRole()` swallow every `\Throwable` into a
  `return null`, the silent fail-open shape (CWE-863): a transient backend error is
  indistinguishable from "no role / denied".
- **gate-13 modal-isolation** — four stale inline-`NcModal` copies under
  `src/views/dso/` duplicate the canonical isolated `NcDialog` versions already living
  in `src/dialogs/`. The duplicates couple modal markup to their parent and bloat the
  tree.

## What Changes

- **REQ-PSH-001 (IDOR)** — Introduce a single server-trusted supplier-session resolver
  (`SupplierSessionService::requireSupplierRef()`) that validates the portal bearer JWT
  and returns the authenticated `supplierRef`/role. Every supplier-portal controller
  method derives its scope from this resolver instead of the client `supplierRef`
  param, and fails CLOSED (401) when no valid supplier session is present. Register and
  wire `SupplierAuthMiddleware` so the portal controllers are actually covered.
- **REQ-PSH-002 (IDOR)** — `ZaakportaalController` calls the guard-prefixed
  `requireAuthenticatedSubject()` resolver so its already-correct server-derived
  scoping is explicit and gate-recognised.
- **REQ-PSH-003 (orphan-auth)** — Wire `validateKvk()` into the KvK master-data
  verification write path and `validateLogoUpload()` into the branding/logo save path;
  both fail closed on invalid input.
- **REQ-PSH-004 (unsafe-auth-resolver)** — Change the auth/role resolvers so a
  `\Throwable` no longer falls open: the catch logs and re-throws (or returns a deny
  sentinel) and the single caller treats the failure as DENY.
- **REQ-PSH-005 (modal-isolation)** — Repoint the only external consumer
  (`VthDashboard.vue`) to the canonical `src/dialogs/DsoCaseDetail.vue` and remove the
  four stale inline-modal duplicates under `src/views/dso/`.

## Capabilities

### New Capabilities
- `security-hardening`: Server-trusted supplier-session authorization, wired
  validators, fail-closed auth resolvers, and modal isolation for procest.

## Impact

- **Backend**:
  - `lib/Service/SupplierSessionService.php` (new) — bearer-validated supplier-session resolver
  - `lib/Controller/SupplierPortalController.php`, `SupplierProfileController.php`,
    `ContractController.php` — derive scope from the session resolver, fail closed
  - `lib/Controller/ZaakportaalController.php` — use `requireAuthenticatedSubject()`
  - `lib/AppInfo/Application.php` + `lib/Middleware/SupplierAuthMiddleware.php` — register + cover the portal controllers
  - `lib/Service/SupplierMasterDataMutationService.php` — call `validateKvk()`
  - `lib/Service/TenantConfigurationService.php` — call `validateLogoUpload()`
  - `lib/Service/TenantAuthenticationService.php`, `SupplierUserManagementService.php` — fail-closed resolvers
- **Frontend**:
  - `src/views/dso/VthDashboard.vue` — import canonical dialog
  - removed: `src/views/dso/{BeschikkingDialog,DoorstuurDialog,SamenwerkverzoekDialog,DsoCaseDetail}.vue`
  - `src/views/cases/components/CaseEmailTab.vue` — minor gate-12 false-positive avoidance
- **No data migration** — no schema or stored-object changes.
