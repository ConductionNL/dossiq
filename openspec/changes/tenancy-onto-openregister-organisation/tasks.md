# Tasks: tenancy-onto-openregister-organisation

Written 2026-09-10. The change had no tasks file, so its progress could only
be read out of the proposal's prose and `openspec status` had nothing to
report. These are the proposal's own five steps, one checkbox each, with the
current state measured against `development` rather than inferred.

**`openspec validate --strict` still fails on this change, on purpose.** It
carries no delta specs, and it should not get one until step 2 is done and
step 4 is ruled: a delta written now would fix requirements over a mapping
nobody has made and over three field drops nobody has approved. The failure
is the accurate signal. Do not silence it with a placeholder capability.

## The five steps

- [x] 1 **Pin.** All five tenant middlewares now have tests:
  `TenantContextMiddlewareTest.php`, `TenantClaimValidationMiddlewareTest.php`,
  `QuotaEnforcementMiddlewareTest.php`, `MandateValidationMiddlewareTest.php`
  and `TenantIsolationMiddlewareTest.php` are all in `tests/Unit/Middleware/`.
  The three the proposal named as untested are the first three of those.
- [ ] 2 **Map.** For each of the seven schemas, what OpenRegister's
  `Organisation` already covers and what it does not. Not started. This is
  analysis and it destroys nothing, so it is the step to take next.
  `tenantMandate` and `tenantOnboardingTask` are the two the proposal expects
  to have no counterpart.
- [x] 3 **Decide the fail-open.** The session leads; neither the header nor
  the claim binds the tenant. Recorded in the proposal under "Decided: the PHP
  session is the source of truth for tenant".
- [ ] 4 **Move.** Repoint the five middlewares, migrate the data, retire the
  schemas. NOT STARTED, AND NOT TO BE STARTED BY AN AGENT ON ITS OWN
  JUDGEMENT. It contains the one irreversible act in the change: dropping
  `contractRef`, `isolationMode` and `dataResidency`. The proposal's own rule
  is that each is verified against STORED DATA before removal and not only
  against readers, and stored data is per install, so no reading of this
  repository can discharge it. It needs a person to say yes.
- [ ] 5 **Remove the surface.** The `Tenants` and `TenantDetail` pages are
  both still in `src/manifest.json`. They go once the store they administer is
  gone, not before.

## Measured 2026-09-10

All seven tenant schemas are still declared: `tenant`, `tenantConfiguration`,
`tenantQuota`, `tenantUser`, `tenantMandate`, `tenantBillingEvent` and
`tenantOnboardingTask`. Five middlewares still resolve dossiq's own tenant
(`TenantMiddleware`, `TenantContextMiddleware`,
`TenantClaimValidationMiddleware`, `TenantIsolationMiddleware`, plus the
quota and mandate pair registered beside them). Nothing has moved onto
`Organisation`.
