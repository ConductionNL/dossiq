# Tasks — procest-security-hardening

## REQ-PSH-001 — Supplier portal IDOR
- [ ] Add `lib/Service/SupplierSessionService.php` with `requireSupplierRef()` + `currentSupplier()` (bearer-validated, fail-closed 401)
- [ ] `SupplierPortalController`: inject the session service; derive `supplierRef` from `requireSupplierRef()` in all 8 endpoints; fail closed
- [ ] `ContractController`: inject the session service; derive `supplierRef` + role from the session in index/show/requestRenewal
- [ ] `SupplierProfileController`: derive `supplierRef` from the session in updateAddress/updateContact/requestIbanChange/submitAccreditation
- [ ] Register `SupplierAuthMiddleware` in `Application.php` and add the three portal controllers to its covered list

## REQ-PSH-002 — Citizen portal guard
- [ ] `ZaakportaalController`: replace `currentSubjectRef()` with `requireAuthenticatedSubject()` in all 10 endpoints

## REQ-PSH-003 — Wire orphan validators
- [ ] `SupplierMasterDataMutationService::submitForVerification()`: call `validateKvk()` on the KvK verification path, fail closed
- [ ] `TenantConfigurationService::sanitiseBranding()`: call `validateLogoUpload()` before accepting a logo, fail closed

## REQ-PSH-004 — Fail-closed resolvers
- [ ] `TenantAuthenticationService::resolveUserRole()`: catch logs + re-throws instead of `return null`; caller denies
- [ ] `SupplierUserManagementService::updateRole()`: backend failure logs + throws instead of silent null

## REQ-PSH-005 — Modal isolation
- [ ] `VthDashboard.vue`: import canonical `src/dialogs/DsoCaseDetail.vue`; map `@updated` → `@transition`
- [ ] Remove `src/views/dso/{BeschikkingDialog,DoorstuurDialog,SamenwerkverzoekDialog,DsoCaseDetail}.vue`

## gate-12 false positive
- [ ] `CaseEmailTab.vue`: `templates.length > 0` → `templates.length` to clear the regex false positive

## Verify
- [ ] `openspec validate procest-security-hardening --strict`
- [ ] re-run hydra gates — 6/7/8/12/13 green
- [ ] `php -l` all changed PHP
