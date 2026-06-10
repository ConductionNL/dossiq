# Tasks: tenant-zaaksysteem-saas-10-billing-shillinq

Member 10 of 12 (code). Depends on member 09. Traces to giant Task 16 + Task 17 + Task 18 + REQ-007.

## 1. Billing event service

- [ ] Implement `TenantBillingService.emitEvent()` (insert TenantBillingEvent, invoiceRef NULL)
- [ ] Emit case_created on creation, case_closed on closure, user_activated on first monthly login
- [ ] Emit case_refund (quantity -1) on withdrawal/delete
- [ ] Implement `getMonthBilling(tenantId, month)` aggregation

## 2. Shillinq export job

- [ ] Implement `ShillinqIntegrationService` + `ExportBillingToShillinq` daily job
- [ ] Select invoiceRef-NULL events, group by tenant + month
- [ ] POST to Shillinq invoices API with line_items; set invoiceRef on success (idempotent)
- [ ] Retry 3× with exponential backoff; defer + alert ops on final failure
- [ ] Register the job in `appinfo/info.xml` (daily 02:00 UTC)

## 3. Dashboard + tests

- [ ] Implement `TenantBillingController` (dashboard, events, export) tenant-admin scoped
- [ ] Implement `BillingDashboardService` (getMonthSummary, getYTDBreakdown, getForecast) + invoice links
- [ ] Create the billing dashboard Vue component (summary, YTD chart, forecast, invoices, quota; i18n nl+en)
- [ ] Unit test: event emission per lifecycle transition + refund netting
- [ ] Integration test: export grouping + invoiceRef set; retry/backoff/defer
- [ ] Integration test: dashboard aggregation + invoice link generation
