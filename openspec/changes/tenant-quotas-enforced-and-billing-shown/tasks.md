# Tasks: tenant-quotas-enforced-and-billing-shown

## 1. Enforcement

- [x] 1.1 `lib/Listener/QuotaConsumptionListener.php` on case create (OpenRegister object created on the case schema) calling `TenantQuotaService::consume($tenantId, 'cases')`, refusing past the hard limit of a `block` or `throttle` quota (OpenRegister answers a hook refusal with 422); registered in `lib/AppInfo/Registrar/SaasServiceRegistrar.php`. The check and count live in `lib/Service/QuotaGate.php`; the quota names are the existing `cases_per_month` and `active_users`.
- [x] 1.2 The same check on document upload and on user provisioning. User provisioning: a `tenantUser` create consumes `active_users` through the same listener, against OpenRegister's organisation `userQuota` (decision 272). Document upload: `DossierUploadHandler::uploadOne()` refuses past a blocking `storage_mb` limit and counts the stored file's size rounded up to whole MB; storage and users are standing totals the monthly reset leaves alone; repair step `MoveTenantQuotaLimitsOntoOrganisation` renames stored `storage_gb` rows and moves a row's user limit onto an organisation that has none.
- [x] 1.5 Quota card in `src/manifest.d/67-environment-organisations.json`: a "Cases this month" row read from the tenant's `cases_per_month` row (decision 272).
- [x] 1.3 Soft limit notice once per period through `INotificationManager`.
- [x] 1.4 `tests/Unit/Listener/QuotaConsumptionListenerTest.php`: under, at, past the limit; soft notice once.

## 2. Billing card

- [ ] 2.1 Tenant detail page in `src/manifest.d/67-environment-organisations.json`: a billing card over `GET /api/saas/tenants/@objectId/billing/{month}` (month picker, lines, total).
- [ ] 2.2 Header action `tenant-billing-run` (`POST .../billing/{month}/run`), admin only; the run is idempotent per tenant and month (`tests/Unit/Service/TenantBillingServiceTest.php`).

## 3. Checks

- [ ] 3.1 `tests/e2e/tenant-quota.spec.ts`: the refusal and the billing run.
- [ ] 3.2 Live pass against board DqTenant with the example tenant Waterland.
- [ ] 3.3 `openspec/parity/capabilities.json` row 17.2: built when 1 and 2 are live.
