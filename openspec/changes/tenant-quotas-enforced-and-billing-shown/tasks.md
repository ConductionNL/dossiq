# Tasks: tenant-quotas-enforced-and-billing-shown

## 1. Enforcement

- [x] 1.1 `lib/Listener/QuotaConsumptionListener.php` on case create (OpenRegister object created on the case schema) calling `TenantQuotaService::consume($tenantId, 'cases')`, refusing past the hard limit of a `block` or `throttle` quota (OpenRegister answers a hook refusal with 422); registered in `lib/AppInfo/Registrar/SaasServiceRegistrar.php`. The check and count live in `lib/Service/QuotaGate.php`; the quota names are the existing `cases_per_month` and `active_users`.
- [ ] 1.2 The same check on document upload (`storage_mb`, by file size) and on user provisioning (`users`). User provisioning done: a `tenantUser` create consumes `active_users` through the same listener. Document upload open: `storage_gb` counts whole gigabytes, so a file size cannot be added to it without a unit decision.
- [x] 1.3 Soft limit notice once per period through `INotificationManager`.
- [x] 1.4 `tests/Unit/Listener/QuotaConsumptionListenerTest.php`: under, at, past the limit; soft notice once.

## 2. Billing card

- [x] 2.1 Tenant detail page in `src/manifest.d/67-environment-organisations.json`: the billing card is the event list board DqTenant draws (Facturatie: date, event, amount), already on the page as `organisation-billing` and `organisation-billing-list`. The board draws no month picker or total, so none is added (decision 130).
- [x] 2.2 Header action `tenant-billing-run` (`POST .../billing/previous/run`, under More), admin only; the run is idempotent per tenant and month (`tests/Unit/Service/TenantBillingServiceTest.php`), and a run that exports nothing answers 409 with the reason.

## 3. Checks

- [ ] 3.1 `tests/e2e/tenant-quota.spec.ts`: the refusal and the billing run.
- [ ] 3.2 Live pass against board DqTenant with the example tenant Waterland.
- [ ] 3.3 `openspec/parity/capabilities.json` row 17.2: built when 1 and 2 are live.
