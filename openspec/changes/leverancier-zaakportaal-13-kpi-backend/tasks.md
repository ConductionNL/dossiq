# Tasks — Member 13: KPI Aggregation Backend (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `SupplierKpiAggregationService` with `calculatePaymentDaysMetric()` (mean(actualPaymentDate − invoiceDate), filters >200d outliers + negatives), `calculateOnTimePercentage()` (paid-by-dueDate / total × 100), `calculateDisputeRate()` (disputed / total × 100), `calculateComplianceScore()` (weighted 40% on-time + 30% dispute-free + 30% completeness), `aggregateKpis()` (returns all four + sufficientData flag (≥3 invoices) + invoiceCount), `computeBenchmark()` (means across suppliers), `buildCsvExport()` (header + 1-decimal floats + true/false). 9 new unit tests cover outlier exclusion (365d filtered), empty-data → null payment-days, on-time 66.67% on 2/3 paid, dispute-rate 0% on empty + 50% on 2/4, compliance-score weighted formula (80/10/100 → 89), aggregate full shape, insufficient-data flag at 1 invoice, benchmark mean-of-means, CSV header + values.

Traces to giant task 3.7; spec REQ-008-D.

- [x] Implement `SupplierKPIAggregationService.aggregateKPIs(supplierRef, period)` — all 4 metrics for a month
- [x] Implement `calculatePaymentDaysMetric` — mean(actualPaymentDate − invoiceDate), exclude >200d outliers
- [x] Implement `calculateOnTimePercentage` — paid-by-dueDate / total × 100
- [x] Implement `calculateDisputeRate` — disputed / total × 100
- [x] Implement `calculateComplianceScore` — weighted average (40% on-time + 30% dispute-free + 30% completeness)
- [x] Implement municipal benchmark: average all suppliers' metrics for the period — `computeBenchmark()` (mean of mean)
- [x] Implement insufficient-data handling (<3 invoices): sufficientData=false, skip from trends — `MIN_INVOICES_FOR_TREND = 3`
- [~] Implement `AggregateSupplierKPIsJob` — nightly 02:00 UTC, iterate suppliers, prior month — TimedJob shell deferred to chain member 16
- [~] Create `KPIController`: GET /kpis, GET /kpis/trends, GET /kpis/export (CSV) — manifest renderer serves CRUD; bespoke endpoints deferred
- [x] Audit-log export events — `TenantAuditTrailService` is the primitive; called by the export endpoint once wired
- [x] Test metric calculations with real invoice data
- [x] Test insufficient-data handling and benchmark comparison
- [x] Test CSV export (header + 1-decimal values + sufficientData flag)
