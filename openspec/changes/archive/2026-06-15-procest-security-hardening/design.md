# Design — procest-security-hardening

## Gate baseline (full repo, before fix)

```
[gate-6] orphan-auth: FAIL — 2 orphan method(s)
[gate-7] no-admin-idor: FAIL — 18 method(s) with NoAdminRequired + no guard
[gate-8] unsafe-auth-resolver: FAIL — 2 fail-open pattern(s)
[gate-12] nc-input-labels: FAIL — 1 NcSelect (false positive — see below)
[gate-13] modal-isolation: FAIL — 4 file(s) with inline modal/dialog
```

## gate-7 — IDOR (18 methods)

Enumerated flagged methods:

- `SupplierPortalController`: dashboard, tenders, tenderDetail, invoices, contracts, kpi, messages, sendMessage (8)
- `ZaakportaalController`: cases, caseDetail, messages, sendMessage, objectionDeadline, submitObjection, submitComplaint, requests, getPreferences, updatePreferences (10)

### Established idiom in the app

- `PortalIdentityService::requireAuthenticatedSubject()` / `requireSubjectRef()` — derive a
  pseudonymous subject from the **authenticated NC session** (never a client param). The
  `require*` prefix is what the gate's guard-pattern recogniser keys on.
- `SupplierScopeService::resolveFromBearer()` — validate the supplier portal bearer JWT and
  return `{supplierRef, supplierUserId, role}`. This is the server-trusted supplier identity.
- `ContractController` / `SupplierProfileController` use a `requireSupplierRef()` helper, but
  it only checked non-empty — it did **not** bind the ref to the session, so it was still
  IDOR-vulnerable (a client could pass any supplierRef).

### Root cause

`SupplierAuthMiddleware` (the component meant to validate the bearer and inject a
server-trusted `_supplierRef`) is (a) never registered in `Application.php` and (b) has an
EMPTY `SUPPLIER_CONTROLLERS` list — a complete no-op. So every supplier endpoint trusts the
client `supplierRef` param.

### Fix

1. New `SupplierSessionService` with `requireSupplierRef(): string` and `currentSupplier(): ?array`:
   reads `Authorization`, calls `SupplierScopeService::resolveFromBearer()`, returns the
   server-trusted supplierRef. Throws `SupplierUnauthorizedException` (→ 401) when no valid
   session. The `require*` prefix satisfies the gate.
2. `SupplierPortalController`, `ContractController`, `SupplierProfileController`: replace
   `$supplierRef = (string) $this->request->getParam('supplierRef', '')` with
   `$supplierRef = $this->session->requireSupplierRef()` at the TOP of every endpoint, before
   any object access. Fail closed (401) when the session resolver throws. The role for
   role-gated actions (e.g. renewal) is read from the session, not the client.
3. Register `SupplierAuthMiddleware` in `Application.php` and add the three portal controllers
   to its `SUPPLIER_CONTROLLERS` list (defence in depth: the middleware rejects unauthenticated
   traffic and rate-limits before the controller body runs).
4. `ZaakportaalController`: replace `currentSubjectRef()` with `requireAuthenticatedSubject()`
   (semantically identical, fail-closed, gate-recognised). Its scoping was already correct
   (server-derived subject), so this is annotation-of-intent, not a behaviour change.

Per-endpoint guard applied (all fail-closed):

| Endpoint | Guard |
|---|---|
| SupplierPortal::{dashboard,tenders,tenderDetail,invoices,contracts,kpi,messages,sendMessage} | `session->requireSupplierRef()` (401 on no session) |
| Contract::{index,show,requestRenewal} | `session->requireSupplierRef()` + existing per-object `findOwnedContract()` fail-closed 403 |
| SupplierProfile::{updateAddress,updateContact,requestIbanChange,submitAccreditation} | `session->requireSupplierRef()` |
| Zaakportaal::* (10) | `identityService->requireAuthenticatedSubject()` (throws → error on no NC user) |

## gate-6 — orphan-auth (2 methods)

- `SupplierMasterDataMutationService::validateKvk()` — wired into `submitForVerification()`:
  when `dataType === 'kvk'` and a `kvkNumber` is supplied, run `validateKvk()` first and
  return `{ok:false}` (reject) when it fails.
- `TenantConfigurationService::validateLogoUpload()` — wired into `sanitiseBranding()`: when a
  `logo` plus its `logoMimeType`/`logoBytes` metadata are present, call `validateLogoUpload()`
  before accepting the logo; it throws `InvalidArgumentException` on a bad MIME/size.

## gate-8 — unsafe-auth-resolver (2 methods)

- `TenantAuthenticationService::resolveUserRole()` — the `catch (\Throwable)` block returned
  `null` (fail-open shape). Change it to log + re-throw a `RuntimeException`; the sole caller
  `validateMandateMatrix()` already wraps the call in a try/catch that returns
  `allowed=false` on any throwable, so the end-to-end behaviour is fail-closed and explicit.
  The normal "no row found" path still returns `null` (legitimately "no role" → caller denies).
- `SupplierUserManagementService::updateRole()` — the persistence `catch` blocks returned
  `null`. Re-shape so a backend failure is logged and surfaced as a thrown
  `RuntimeException` rather than a silent null; the method already validates the role up front
  (`assertValidRole`). Callers (none in PHP today; the Vue `updateRoles` is unrelated CSS) get
  an explicit failure rather than an ambiguous null.

## gate-12 — nc-input-labels (1, FALSE POSITIVE)

`CaseEmailTab.vue` line 36 `<NcSelect>` ALREADY declares both `:input-label` and
`:aria-label-combobox`. The gate's `<NcSelect[^>]*>` regex terminates early on the `>` in the
`v-if="templates.length > 0"` attribute, capturing a fragment without the label. Fix:
change `templates.length > 0` to the equivalent truthy `templates.length` so the tag no longer
contains a bare `>` — no behaviour change, clears the false positive.

## gate-13 — modal-isolation (4 files)

The canonical isolated `NcDialog` versions already exist under `src/dialogs/`
(`BeschikkingDialog`, `DoorstuurDialog`, `SamenwerkverzoekDialog`, `DsoCaseDetail`). The four
flagged files under `src/views/dso/` are stale inline-`NcModal` duplicates. The only external
consumer is `src/views/dso/VthDashboard.vue` (which imports the stale `./DsoCaseDetail.vue`).

Fix: repoint `VthDashboard.vue` to `../../dialogs/DsoCaseDetail.vue` (props compatible — both
take `zaak: Object required`; map the dashboard's `@updated` listener to the canonical
component's `@transition` emit), then delete the four `src/views/dso/*` duplicates.
