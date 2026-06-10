# Tasks — Member 13: KPI Aggregation Backend (code)

Traces to giant task 3.7; spec REQ-008-D.

- [ ] Implement `SupplierKPIAggregationService.aggregateKPIs(supplierRef, period)` — all 4 metrics for a month
- [ ] Implement `calculatePaymentDaysMetric` — mean(actualPaymentDate − invoiceDate), exclude >200d outliers
- [ ] Implement `calculateOnTimePercentage` — paid-by-dueDate / total × 100
- [ ] Implement `calculateDisputeRate` — disputed / total × 100
- [ ] Implement `calculateComplianceScore` — weighted average of sub-scores
- [ ] Implement municipal benchmark: average all suppliers' metrics for the period, store in benchmark
- [ ] Implement insufficient-data handling (<3 invoices): sufficientData=false, skip from trends
- [ ] Implement `AggregateSupplierKPIsJob` — nightly 02:00 UTC, iterate suppliers, prior month
- [ ] Create `KPIController`: GET /kpis, GET /kpis/trends, GET /kpis/export (CSV)
- [ ] Apply scope validation; audit-log export events
- [ ] Test metric calculations with real invoice data
- [ ] Test insufficient-data handling and benchmark comparison
- [ ] Test CSV export (48 rows + header, 1-decimal values, ISO dates)
