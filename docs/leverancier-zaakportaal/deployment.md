# Procest Leverancier Zaakportaal — Deployment Guide

This guide covers the supplier-portal (`leverancier-zaakportaal-*`) chain
shipped in the Procest app. The portal layers on top of the existing
Procest case-management surface and reuses the OpenRegister schemas
declared in chain member 01.

## Prerequisites

- Nextcloud ≥ 30
- `openregister` app installed and enabled
- PostgreSQL backend (the schema-per-tenant primitives in chain
  member 03 are Postgres-specific)
- OpenConnector app for the eHerkenning broker
- (Optional) Shillinq backend for invoicing — only required for the
  parent `tenant-zaaksysteem-saas` chain

## Repair-step bootstrap

The 7 supplier schemas + 4 supplier case types ship as seed objects in
`lib/Settings/procest_register.json`. They land via the existing
`Procest\Repair\InitializeSettings` repair step on app enable /
upgrade — no separate migration needed.

```bash
# Re-import register seed (idempotent).
occ maintenance:repair
```

## App-config keys

Set these via `occ config:app:set procest <key> --value '<value>'`:

| Key                            | Default | Description                                                                                  |
|--------------------------------|---------|----------------------------------------------------------------------------------------------|
| `jwt_signing_secret`           | NC system secret | HMAC HS256 signing secret for the supplier-portal session JWT (`TenantJwtService`)   |
| `eherkenning_broker_url`       | _unset_ | OpenConnector eHerkenning broker base URL                                                    |
| `eherkenning_client_id`        | _unset_ | OAuth client ID for the eHerkenning broker                                                   |
| `eherkenning_client_secret`    | _unset_ | OAuth client secret (use NC secret vault — never commit)                                     |
| `kvk_api_url`                  | _unset_ | KvK API base URL (used during supplier validation)                                           |
| `shillinq_base_url`            | _unset_ | Shillinq invoices API base URL (only needed for parent SaaS chain)                           |
| `shillinq_api_key`             | _unset_ | Shillinq bearer key                                                                          |

## Routes

The supplier-portal endpoints are declared in
`docs/openapi/leverancier-zaakportaal.yaml`. The wiring shape is:

- All endpoints are admin-route under `/index.php/apps/procest/...`
- `SupplierAuthMiddleware` (chain member 04) gates every supplier
  controller — it requires a bearer JWT issued by
  `SupplierAuthService::issueSessionToken()` and enforces a 100
  req/min/IP rate limit
- The generic OpenRegister manifest renderer at `/settings/<schema>`
  serves CRUD on the `supplier*` schemas for admin users (per
  ADR-022 apps-consume-or-abstractions)

## Background jobs

| Job                                  | Frequency      | Description                                                                                  |
|--------------------------------------|----------------|----------------------------------------------------------------------------------------------|
| `ResetMonthlyQuotasJob`              | Daily          | Resets monthly + hourly tenant quotas after their window elapses (parent SaaS chain)         |
| `ScanExpiringContractsJob` (planned) | Nightly 03:00  | Flags supplier contracts within 90 days of expiry — `ContractRenewalService::scanExpiring`   |
| `ExportBillingToShillinqJob` (planned)| Daily 02:00 UTC | Exports unsettled tenant billing events into Shillinq invoices                                |
| `AggregateSupplierKpisJob` (planned) | Nightly 02:00  | Computes per-month KPI snapshot per supplier                                                  |
| `RouteSupplierMessageJob` (planned)  | Real-time      | Dispatches new supplier messages to handler inboxes + sends email notifications              |

`ResetMonthlyQuotasJob` is registered in `appinfo/info.xml`; the
remaining jobs ship as planned wiring in chain member 16 once their
dependencies (Shillinq URL, mailer template, OpenConnector broker
URL) are configured.

## Security checklist

- All endpoints behind `SupplierAuthMiddleware` (bearer JWT + 100
  req/min/IP rate-limit + IP-bucket fail counter on 5+ failures)
- `SupplierScopeService` masks IBAN / email / phone in audit logs
- `supplierMessage` schema is write-once (`x-insert-only:true`)
- IBAN changes go through a 4-eyes Procest case
  (`leverancier-iban-wijziging`) — the supplier row is never directly
  mutated by the supplier user
- `TenantAuditTrailService::emit()` is called on every mutating
  service path (invite, role change, revoke, message send, mutation
  request, IBAN-change request, accreditation submit)

## Troubleshooting

- **"Onbekende leverancier" on login** — the eHerkenning KvK number
  did not match any `supplier` row. Seed the supplier or check the
  KvK number format (6-12 digits).
- **HTTP 429** — rate limit hit (100 req/min/IP); back off or
  shard traffic.
- **HTTP 401 on dashboard** — bearer JWT expired (2-hour TTL);
  call `POST /auth/refresh` or re-login.
- **"Procest TENANT_SCHEMA_DELETED" log line** — emitted by
  `TenantLifecycleControlService::archiveAndDelete()` after a
  tenant is fully terminated.
