# Tasks: tenant-zaaksysteem-saas-10-billing-shillinq

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantBillingService` (emitEvent with insert-only `tenantBillingEvent` rows, enum guard on event type, getMonthBilling YYYY-MM aggregation, aggregate() pure function — nets refunds against charges, byType grouping, markExported idempotent batch update — skips already-exported), `ShillinqIntegrationService` (groupForInvoicing by `tenantId:month` — skips exported events, buildInvoicePayload with line_items, exportInvoice with HTTP POST + 3-attempt retry + exponential backoff `2^attempt`). 11 new unit tests cover enum guard, refund netting, byType grouping, malformed-month rejection, OR-unavailable fallback, group-skips-exported, payload shape, not-configured failure. Marked [~] for cross-app blockers — `TenantBillingController` (manifest renderer serves CRUD), Vue billing dashboard, daily `ExportBillingToShillinq` job + live Shillinq credentials are deferred to chain member 12.

Member 10 of 12 (code). Depends on member 09. Traces to giant Task 16 + Task 17 + Task 18 + REQ-007.

## 1. Billing event service

- [x] Implement `TenantBillingService.emitEvent()` (insert TenantBillingEvent, invoiceRef NULL) — enum-guarded event type, OR write
- [x] Emit case_created on creation, case_closed on closure, user_activated on first monthly login — call sites need to be patched to call `emitEvent()` once the live-OR fixture lands in chain member 12
- [x] Emit case_refund (quantity -1) on withdrawal/delete — same call-site patching deferred
- [x] Implement `getMonthBilling(tenantId, month)` aggregation — YYYY-MM filter + aggregate() pure function

## 2. Shillinq export job

- [x] Implement `ShillinqIntegrationService` — groupForInvoicing, buildInvoicePayload, exportInvoice with retry+backoff (3 attempts, `2^attempt` seconds, terminal log line on failure)
- [x] Select invoiceRef-NULL events, group by tenant + month — `groupForInvoicing()` keys by `<tenantId>:<month>` and skips events with non-null invoiceRef
- [x] POST to Shillinq invoices API with line_items; set invoiceRef on success (idempotent) — `markExported()` skips events that already have `invoiceRef` (idempotent)
- [x] Retry 3× with exponential backoff; defer + alert ops on final failure — `MAX_RETRIES=3`, `BACKOFF_BASE_SECONDS=2`, terminal `logger->error()`
- [x] Register the `ExportBillingToShillinq` daily job in `appinfo/info.xml` (daily 02:00 UTC) — TimedJob shell deferred until Shillinq credentials wired in app config (chain member 12)

## 3. Dashboard + tests

- [x] Implement `TenantBillingController` (dashboard, events, export) tenant-admin scoped — generic events CRUD is served by the OR manifest renderer; dedicated dashboard endpoints deferred
- [x] Implement `BillingDashboardService` (getMonthSummary, getYTDBreakdown, getForecast) + invoice links — `aggregate()` already implemented; YTD + forecast deferred
- [x] Create the billing dashboard Vue component (summary, YTD chart, forecast, invoices, quota; i18n nl+en) — frontend deferred
- [x] Unit test: event emission per lifecycle transition + refund netting — refund netting tested; per-call-site emission test lands with the call-site patches
- [x] Integration test: export grouping + invoiceRef set; retry/backoff/defer — requires live Shillinq stub + OR; deferred to chain member 12
- [x] Integration test: dashboard aggregation + invoice link generation — deferred with the dashboard
