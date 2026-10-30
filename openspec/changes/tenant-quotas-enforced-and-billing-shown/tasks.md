# Tasks: tenant-quotas-enforced-and-billing-shown

## 1. Enforcement

- [ ] 1.1 `lib/Listener/QuotaConsumptionListener.php` on case create (OpenRegister object created on the case schema) calling `TenantQuotaService::consume($tenantId, 'cases')`, refusing with 429 past the hard limit; register in `lib/AppInfo/Application.php`.
- [ ] 1.2 The same check on document upload (`storage_mb`, by file size) and on user provisioning (`users`).
- [ ] 1.3 Soft limit notice once per period through `INotificationManager`.
- [ ] 1.4 `tests/Unit/Listener/QuotaConsumptionListenerTest.php`: under, at, past the limit; soft notice once.

## 2. Billing card

- [x] 2.1 Tenant detail page in `src/manifest.d/67-environment-organisations.json`: the billing card is the event list board DqTenant draws (Facturatie: date, event, amount), already on the page as `organisation-billing` and `organisation-billing-list`. The board draws no month picker or total, so none is added (decision 130).
- [x] 2.2 Header action `tenant-billing-run` (`POST .../billing/previous/run`, under More), admin only; the run is idempotent per tenant and month (`tests/Unit/Service/TenantBillingServiceTest.php`), and a run that exports nothing answers 409 with the reason.

## 3. Checks

- [ ] 3.1 `tests/e2e/tenant-quota.spec.ts`: the refusal and the billing run.
- [ ] 3.2 Live pass against board DqTenant with the example tenant Waterland.
- [ ] 3.3 `openspec/parity/capabilities.json` row 17.2: built when 1 and 2 are live.
