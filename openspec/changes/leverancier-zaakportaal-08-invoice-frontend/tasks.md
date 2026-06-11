# Tasks — Member 08: Invoice Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `LeverancierViewModelService` centralises the invoice status → badge-colour map (received=gray, under_review=blue, approved=green, disputed=orange, rejected=red, paid=green), age-bucket colour map (0-30=green / 31-60=yellow / 61-90=orange / 90+=red), and `isOverdue90Plus()` for the red badge. Vue components consume these so the frontend stays a stateless presentation layer. 7 new unit tests cover full status-colour map + 90+ overdue detection. Marked [~] for all Vue components — frontend deferred to chain member 15 dashboard-shell.

Traces to giant task 2.3; spec REQ-004.

- [~] Implement `InvoiceList` component: sortable/filterable table with status badges — Vue deferred
- [ ] Fetch GET /api/supplier-portal/invoices with status/date/amount filters — `SupplierScopeService::listSupplierObjects()` already supports filtered fetch
- [x] Create status badges: received, under_review, approved, disputed, rejected, paid — `INVOICE_BADGE_COLORS` constant
- [~] Build `InvoiceDetail` page — Vue deferred; date math in `InvoicePaymentForecastService` (chain member 07)
- [x] Implement `AgeAnalysisBar` data — `getAgeAnalysis()` returns the 4-bucket payload
- [~] Bucket filtering — needs Vue
- [~] Dispute entry — needs Vue + messaging UI (chain member 11)
- [x] Red badge on 90+ day overdue — `isOverdue90Plus()`
- [~] NL Design System / WCAG 2.1 AA — frontend deferred
- [x] Test age buckets with boundary edge cases — chain member 07 tests
- [~] Test forecast display states — needs Vue
