# Design — Member 13: KPI Aggregation Backend (code)

## Scope

Nightly KPI aggregation service + scoped snapshot/trends/export API, reading `SupplierInvoice`,
`SupplierContract`, and writing `SupplierKPI` records (member 01 schemas).

## Declarative-first (ADR-031) note

KPI snapshots are materialised `SupplierKPI` records (member 01 schema) written nightly — a
declarative aggregation surface. Per ADR-031, where OpenRegister can express the aggregation
declaratively it is preferred; the weighted compliance score and benchmark remain in the service.

## Approach

- `aggregateKPIs(supplierRef, period)` computes the four metrics for a month:
  - avg payment days = mean(actualPaymentDate − invoiceDate) over paid invoices, outliers >200d
    excluded.
  - on-time % = paid-by-dueDate / total × 100.
  - dispute rate = disputed / total × 100.
  - compliance score = weighted average of sub-scores (0–100).
- Municipal benchmark = average of all suppliers' metrics for the period, stored in `benchmark`.
- Insufficient data (<3 invoices/month) → `sufficientData` = false, skipped from trends.
- `AggregateSupplierKPIsJob` nightly at 02:00 UTC; `KPIController` serves snapshot/trends/export.

## Security (ADR-005)

- KPI endpoints scope-validated; a supplier sees only its own metrics + the anonymous municipal
  average.
- Export event audit-logged.
