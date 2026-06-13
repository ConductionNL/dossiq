# Design: tenant-zaaksysteem-saas-10-billing-shillinq

## Scope of this member

Billing event emission, daily Shillinq export, and the billing dashboard. The termination final-invoice handoff is invoked from member 11 but the emission/export plumbing lives here.

## Declarative-first (ADR-031, ADR-001)

`TenantBillingEvent` is a declarative, insert-only OpenRegister schema (member 01). Event emission writes through the `ObjectService`. The Shillinq export and dashboard aggregation are imperative integration/reporting glue — `kind: code`.

## Service layer

### TenantBillingService
- `emitEvent(tenantId, eventType, quantity, unitPrice)` → insert `TenantBillingEvent` (invoiceRef NULL).
- Emit `case_created` on creation, `case_closed` on closure, `user_activated` on first monthly login, `case_refund` (quantity −1) on withdrawal/delete.
- `getMonthBilling(tenantId, month)` → aggregate.

### ShillinqIntegrationService / ExportBillingToShillinq (daily job)
- Select events with invoiceRef NULL, group by tenant + month.
- `POST /v1/invoices/tenant-{slug}/{YYYY-MM}` with line_items.
- On success set invoiceRef; on failure retry 3× with exponential backoff, then defer to next day + alert ops. Registered in `appinfo/info.xml` (daily 02:00 UTC).

### TenantBillingController / BillingDashboardService
- `dashboard()`, `events()`, `export()`.
- `getMonthSummary()`, `getYTDBreakdown()`, `getForecast()` + Shillinq invoice links by invoiceRef.

## Frontend (ADR-004)

Billing dashboard Vue: current-month summary (cases, refunds, total), YTD breakdown chart, forecast, invoice history with download links, quota status. i18n nl+en; modal-isolation; NcSelect inputLabel.

## Security (ADR-005)

Billing events are immutable (insert-only schema) — no update/delete path, preventing charge tampering. The Shillinq API credentials are read from secure config, never logged (no plaintext secrets). The export job is idempotent per tenant-month (re-running does not double-invoice — it only touches invoiceRef-NULL rows). Billing endpoints are tenant-admin scoped (mandate stack). Error messages must not leak other tenants' billing data.

## Tests

- Unit: event emission on each lifecycle transition; refund nets the prior charge.
- Integration: export groups by tenant-month, sets invoiceRef on mock success; retry/backoff + defer on failure.
- Integration: dashboard aggregation (summary, YTD, forecast) + invoice link generation.
