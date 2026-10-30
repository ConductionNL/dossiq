# Tasks: tenant-quotas-enforced-and-billing-shown

## 1. Enforcement

- [ ] 1.1 `lib/Listener/QuotaConsumptionListener.php` on case create (OpenRegister object created on the case schema) calling `TenantQuotaService::consume($tenantId, 'cases')`, refusing with 429 past the hard limit; register in `lib/AppInfo/Application.php`.
- [ ] 1.2 The same check on document upload (`storage_mb`, by file size) and on user provisioning (`users`).
- [ ] 1.3 Soft limit notice once per period through `INotificationManager`.
- [ ] 1.4 `tests/Unit/Listener/QuotaConsumptionListenerTest.php`: under, at, past the limit; soft notice once.

## 2. Billing card

- [ ] 2.1 Tenant detail page in `src/manifest.d/67-environment-organisations.json`: a billing card over `GET /api/saas/tenants/@objectId/billing/{month}` (month picker, lines, total).
- [ ] 2.2 Header action `tenant-billing-run` (`POST .../billing/{month}/run`), admin only; the run is idempotent per tenant and month (`tests/Unit/Service/TenantBillingServiceTest.php`).

## 3. Checks

- [ ] 3.1 `tests/e2e/tenant-quota.spec.ts`: the refusal and the billing run.
- [ ] 3.2 Live pass against board DqTenant with the example tenant Waterland.
- [ ] 3.3 `openspec/parity/capabilities.json` row 17.2: built when 1 and 2 are live.
