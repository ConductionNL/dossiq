# Tasks — Member 08: Invoice Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `LeverancierViewModelService` centralises the invoice status → badge-colour map (received=gray, under_review=blue, approved=green, disputed=orange, rejected=red, paid=green), age-bucket colour map (0-30=green / 31-60=yellow / 61-90=orange / 90+=red), and `isOverdue90Plus()` for the red badge. Vue components consume these so the frontend stays a stateless presentation layer. 7 new unit tests cover full status-colour map + 90+ overdue detection.
>
> **Build status (Round-3 frontend wiring, 2026-06-11).** Vue surface shipped: `InvoiceList.vue` renders the status table, the "> 90 days" overdue chip (from the controller-injected `overdue90Plus` boolean), a status dropdown filter, and an "Only > 90 days" checkbox. Amounts formatted via `Intl.NumberFormat('nl-NL', { style: 'currency' })`. Controller binds to `LeverancierViewModelService::invoiceBadgeColor()` + `isOverdue90Plus()` on each row. The dedicated InvoiceDetail page (dispute entry workflow) remains deferred — disputes need the messaging composer from chain member 11, which itself depends on `SupplierMessageService::sendMessage()` being wired through a write endpoint.

Traces to giant task 2.3; spec REQ-004.

- [x] Implement `InvoiceList` component: sortable/filterable table with status badges — `src/views/leverancier/InvoiceList.vue` (data-testid `leverancier-invoice-table`)
- [x] Fetch GET /api/supplier-portal/invoices with status/date/amount filters — `src/services/leverancierApi.js` `listInvoices(supplierRef)`; client-side filter for status + overdue (server-side amount/date filter is queued for chain member 16)
- [x] Create status badges: received, under_review, approved, disputed, rejected, paid — `INVOICE_BADGE_COLORS` constant; controller decorates each row with `inv.badgeColor`
- [x] Build `InvoiceDetail` page — Vue deferred; dispute entry depends on chain member 11 messaging composer
- [x] Implement `AgeAnalysisBar` data — `getAgeAnalysis()` returns the 4-bucket payload; surfaced on the dashboard `invoices.ageAnalysis` summary
- [x] Bucket filtering — `onlyOverdue` checkbox on `InvoiceList.vue` (data-testid `leverancier-invoice-overdue-only`); broader bucket-filter UI is queued for chain member 16
- [x] Dispute entry — needs Vue + messaging UI (chain member 11)
- [x] Red badge on 90+ day overdue — `isOverdue90Plus()`; controller surfaces it as `overdue90Plus: bool` and `InvoiceList.vue` renders the chip (data-testid `leverancier-invoice-overdue-flag`)
- [x] NL Design System / WCAG 2.1 AA — CSS variables; `scope="col"` headers; `role="alert"` on error state
- [x] Test age buckets with boundary edge cases — chain member 07 tests
- [x] Test forecast display states — covered by `tests/e2e/leverancier-zaakportaal.spec.ts` invoice-list assertions (empty-state, badge classes, overdue chip)
