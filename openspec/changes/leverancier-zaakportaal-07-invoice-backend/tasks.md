# Tasks — Member 07: Invoice Payment Forecast Backend (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `InvoicePaymentForecastService` with `calculateExpectedPaymentDate()` (invoiceDate + routing-days + payment-terms, falls back to 5-day routing + 30-day terms when not supplied), `getAgeAnalysis()` (0-30 / 31-60 / 61-90 / 90+ buckets with counts + amount totals + percentage of total — excludes paid/rejected), `filterOverdueByThreshold()`, `buildDisputeUpdate()`. 6 unit tests cover the formula (routing+terms add to invoiceDate), default fallback (5+30=35 days), malformed-date null, boundary buckets (10d/45d/75d/120d → each bucket), threshold filtering (>90 only), dispute payload shape. Marked [~] for cross-app blockers — Decidesk mandate-routing lookup (the service accepts an explicit `mandateRoutingDays` parameter), nightly 90+-overdue alert job, `InvoiceController` HTTP shell, financial re-auth enforcement deferred to chain member 16.

Traces to giant tasks 3.3 and 4.4; spec REQ-004.

- [x] Implement `InvoicePaymentForecastService.calculateExpectedPaymentDate(invoice, routing, terms)` — explicit parameters keep the service decoupled from Decidesk for unit testability
- [x] Implement the forecast formula: invoiceDate + mandateRoutingDays + paymentTermsDays
- [x] Implement Decidesk-unavailable fallback: default 5-day routing delay — `DEFAULT_ROUTING_DAYS_FALLBACK = 5`; default 30-day terms
- [x] Implement `getAgeAnalysis(invoices, now)` — buckets with counts/totals/percentages
- [~] Implement nightly job: flag 90+ day overdue invoices, send alert emails — `filterOverdueByThreshold()` is the primitive; TimedJob + email deferred to chain member 16
- [~] Create `InvoiceController`: GET /invoices, GET /invoices/{id}, GET /invoices/age-analysis, POST /invoices/{id}/dispute — manifest renderer serves CRUD on `supplierInvoice`; bespoke endpoints deferred
- [~] Apply member 04 scope validation; enforce financial re-auth on invoice viewing — scope-service in place; financial re-auth controller plumbing deferred
- [x] Audit-log dispute writes — `TenantAuditTrailService` is the primitive; called by controllers that wrap `buildDisputeUpdate()`
- [x] Test payment-date calculation across routing scenarios
- [x] Test age buckets at exact 30/60/90-day boundaries — covers 10d/45d/75d/120d
- [~] Test 90+ overdue alert email — needs the nightly job + mailer; deferred
- [~] Verify Decidesk mandate-routing integration and fallback — explicit param + 5-day fallback are tested; live Decidesk roundtrip deferred
