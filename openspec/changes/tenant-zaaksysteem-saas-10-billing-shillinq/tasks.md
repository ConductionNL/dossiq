# Tasks: tenant-zaaksysteem-saas-10-billing-shillinq

Member 10 of 12 (code). Depends on member 09. Traces to giant Task 16 + Task 17 + Task 18 + REQ-007.

## 1. Billing event service

- [~] Implement `TenantBillingService.emitEvent()` (insert TenantBillingEvent, invoiceRef NULL) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit case_created on creation, case_closed on closure, user_activated on first monthly login — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit case_refund (quantity -1) on withdrawal/delete — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getMonthBilling(tenantId, month)` aggregation — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Shillinq export job

- [~] Implement `ShillinqIntegrationService` + `ExportBillingToShillinq` daily job — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Select invoiceRef-NULL events, group by tenant + month — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] POST to Shillinq invoices API with line_items; set invoiceRef on success (idempotent) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Retry 3× with exponential backoff; defer + alert ops on final failure — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register the job in `appinfo/info.xml` (daily 02:00 UTC) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Dashboard + tests

- [~] Implement `TenantBillingController` (dashboard, events, export) tenant-admin scoped — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `BillingDashboardService` (getMonthSummary, getYTDBreakdown, getForecast) + invoice links — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create the billing dashboard Vue component (summary, YTD chart, forecast, invoices, quota; i18n nl+en) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: event emission per lifecycle transition + refund netting — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: export grouping + invoiceRef set; retry/backoff/defer — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: dashboard aggregation + invoice link generation — deferred to downstream cycle / fleet-wide adoption (handoff)
