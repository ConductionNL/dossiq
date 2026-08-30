## Why

Municipal case managers and team leads lack visibility into processing time (doorlooptijd) compliance. The existing dashboard provides basic KPI cards and status counts, but offers no way to analyze SLA adherence by case type, identify processing bottlenecks, or track trends over time. Dutch government regulations (Awb, Wob/Woo) mandate strict processing deadlines, and non-compliance carries legal and political consequences. A dedicated doorlooptijd dashboard enables proactive management of processing time compliance.

## What Changes

- Add a new "Doorlooptijd" tab/view to the dashboard with SLA adherence analytics
- Add processing time distribution chart: histogram of actual vs. allowed processing days per case type
- Add SLA compliance rate widget: percentage of cases completed within their `processingDeadline` (from caseType), broken down by case type
- Add trend line chart: monthly SLA compliance rate over the last 12 months
- Add "at risk" cases panel: open cases where remaining time is less than 25% of the allowed processing deadline
- Add average processing time per case type table with comparison to the SLA target
- All data derived from existing case fields (`startDate`, `endDate`, `deadline`, `plannedEndDate`) and caseType `processingDeadline` — no schema changes needed

## Capabilities

### New Capabilities
- `doorlooptijd-dashboard`: SLA adherence analytics view with processing time distribution, compliance rate breakdown, trend charts, at-risk case identification, and per-case-type performance table

### Modified Capabilities
- `dashboard`: Add navigation tab/link to the doorlooptijd analytics view from the main dashboard; add a summary SLA compliance KPI card to the existing KPI row

## Impact

- **Frontend**: New Vue view (`DoorlooptijdDashboard.vue`), new dashboard helper functions for SLA calculations, new route entry, chart components (using apexcharts via @conduction/nextcloud-vue)
- **Backend**: No changes — all data already available via OpenRegister queries on `case` and `caseType` schemas
- **Dependencies**: Existing apexcharts dependency (from @conduction/nextcloud-vue) for charts
- **Data**: Uses existing fields: `case.startDate`, `case.endDate`, `case.deadline`, `case.plannedEndDate`, `case.status`, `case.caseType`, `caseType.processingDeadline`, `statusType.isFinal`
- **Pipelinq**: No impact — doorlooptijd analytics are read-only views on existing case data
