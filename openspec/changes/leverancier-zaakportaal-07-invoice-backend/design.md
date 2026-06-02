# Design — Member 07: Invoice Payment Forecast Backend (code)

## Scope

Backend service + scoped API for invoice status, expected payment date, age analysis, dispute,
and the 90+ overdue alert. Reads the `SupplierInvoice` schema from member 01.

## Declarative-first (ADR-031) note

No new schema. `SupplierInvoice` records via OpenRegister ObjectService (ADR-001). The forecast is
computed (invoice date + mandate routing days + payment term days), not stored as authoritative.

## Approach

- `calculateExpectedPaymentDate(invoiceRef)` joins Decidesk mandate routing + payment terms;
  fallback to a default 5-day routing delay when Decidesk is unavailable.
- `getAgeAnalysis(supplierRef)` returns 0–30/30–60/60–90/90+ buckets with counts, totals,
  percentages.
- Nightly job flags 90+ day overdue invoices and emails the supplier's primary contact.
- `InvoiceController` exposes list/detail/age-analysis/dispute, scope-validated via member 04.

## Security (ADR-005)

- Invoice viewing is a financial view → re-auth flag from member 02 enforced.
- All endpoints scope-validated (no cross-supplier invoices).
- Dispute writes audit-logged.
- Decidesk lookup failures degrade safely to the default routing delay, never block the response.
