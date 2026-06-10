# Tasks — Member 13: KPI Aggregation Backend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

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
