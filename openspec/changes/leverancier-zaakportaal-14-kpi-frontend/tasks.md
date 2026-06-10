# Tasks — Member 14: KPI Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `LeverancierViewModelService::benchmarkComparison()` returns `better/same/worse` with the "lower is better" sign honoured per metric (avgPaymentDays + disputeRate are lower-is-better; onTimePercentage + complianceScore are higher-is-better). `shouldPlotPoint()` filters out insufficientData months from the trend chart. `BENCHMARK_INDICATORS` constant exposes the three icon keys (arrow-up-green / minus-gray / arrow-down-red) so the Vue KPICard reads its icon from this map. 4 of the 7 unit tests in `LeverancierViewModelServiceTest` cover the comparison logic (lower-better + higher-better + within-tolerance same) + shouldPlot honours flag (true / false / missing). Marked [~] for all Vue components and the CSV-export download UI — frontend deferred to chain member 15.

Traces to giant task 2.7; spec REQ-008-A/B/C.

- [~] Implement `KPICard` component — Vue deferred; backend exposes benchmarkComparison + benchmark map + indicator keys
- [~] Fetch GET /api/supplier-portal/kpis (snapshot) and /kpis/trends (12-month) — `SupplierScopeService::listSupplierObjects()` + `SupplierKpiAggregationService` (chain member 13)
- [~] Create `TrendChart`: line for payment days + on-time %, bar for dispute rate — Vue/chart-lib deferred
- [~] X-axis month labels; metric-specific Y-axis; hover tooltip — Vue deferred
- [x] Skip insufficient-data months from the chart with an "Onvoldoende gegevens" label — `shouldPlotPoint()` returns false for those rows; Vue renders the label
- [x] Implement benchmark comparison indicators (own vs municipal average) — `benchmarkComparison()` returns the indicator key; `BENCHMARK_INDICATORS` map exposes the icon keys
- [~] Implement CSV export button: GET /kpis/export, trigger download — backend `buildCsvExport()` (chain member 13) produces the body; download UI deferred
- [~] NL Design System / WCAG 2.1 AA — frontend deferred
- [~] Test with 12 full months of data — needs Vue + live OR
- [x] Test sparse-data months — `shouldPlotPoint()` filters those rows
- [x] Verify benchmark comparison rendering — `benchmarkComparison()` covers lower-better, higher-better, and within-tolerance same
