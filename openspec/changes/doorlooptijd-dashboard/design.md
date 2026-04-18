## Context

Procest has an existing main dashboard (`src/views/Dashboard.vue`) with KPI cards, status chart, and my-work widget. It uses `CnDashboardPage` from `@conduction/nextcloud-vue` with a grid-based widget layout. Data is fetched client-side from OpenRegister via the `objectStore` (Pinia). Cases have `startDate`, `endDate`, `deadline`, `plannedEndDate` fields; case types have `processingDeadline` (ISO 8601 duration, e.g., `P30D`). Status types have `isFinal` to determine completion.

The existing `dashboardHelpers.js` already computes basic KPIs including average processing days for completed cases. The doorlooptijd dashboard extends this with deeper SLA analytics.

## Goals / Non-Goals

**Goals:**
- Provide a dedicated doorlooptijd analytics view accessible from the main dashboard
- Show SLA compliance rates per case type with visual breakdown
- Show processing time distribution (histogram) per case type
- Show trend data (monthly compliance rate over 12 months)
- Identify at-risk open cases (less than 25% time remaining)
- Show per-case-type performance table comparing actual vs target processing time
- All calculations purely client-side using existing OpenRegister data

**Non-Goals:**
- No pre-computed analytics or materialized views
- No export/PDF generation of analytics (future scope)
- No comparison across organizational units (multi-tenant analytics)
- No predictive analytics or ML-based forecasting

**Optional Backend Addition:**
- Optional backend aggregation API at GET `/api/procest/doorlooptijd/metrics` for server-side metric calculation
- Complements client-side approach without breaking changes
- Supports date range filtering and case type filtering
- Useful for large datasets where client-side computation may be slow

## Decisions

### D1: Separate view vs. dashboard tab

**Decision**: Add a new Vue route `/doorlooptijd` that renders `DoorlooptijdDashboard.vue`, accessible via a tab in the main navigation and a link from the main dashboard KPI row.

**Rationale**: The main dashboard uses `CnDashboardPage` with a fixed widget grid. Embedding complex analytics charts into that grid would be constrained. A dedicated view gives full layout control for charts and tables. The main dashboard gets a lightweight SLA compliance KPI card that links to the full view.

**Alternative considered**: Adding widgets to the existing dashboard grid. Rejected because the grid cells are too small for trend charts and histograms.

### D2: Chart library

**Decision**: Use ApexCharts via `@conduction/nextcloud-vue` (already a dependency). Specifically: `apexcharts` and `vue-apexcharts`.

**Rationale**: Already available as a transitive dependency. Supports bar charts (histogram), line charts (trends), donut charts (compliance breakdown), and responsive resizing. No new dependency needed.

### D3: SLA calculation logic

**Decision**: Create `src/utils/doorlooptijdHelpers.js` with pure functions for all SLA calculations. Parse `caseType.processingDeadline` (ISO 8601 duration) into days, compare against actual `endDate - startDate` for completed cases, and against `today - startDate` for open cases.

**Rationale**: Keeping calculations in pure utility functions enables unit testing and reuse. The existing `dashboardHelpers.js` pattern works well.

**Duration parsing**: ISO 8601 durations like `P30D` or `P6W` will be parsed to calendar days. For durations with months (`P2M`), use 30 days per month as approximation, matching common Dutch government practice.

### D4: Data fetching strategy

**Decision**: Fetch all cases (completed + open) and all case types in a single batch on mount, then compute all analytics client-side. Cache results in component data. Re-fetch on explicit refresh.

**Rationale**: OpenRegister returns full objects. For typical municipal deployments (hundreds to low thousands of cases), client-side computation is fast enough. Avoids needing backend analytics endpoints. The `_limit: 5000` parameter covers realistic volumes.

**Risk**: Very large datasets (10K+ cases) may cause slow initial load. Mitigation: show loading skeleton, add date range filter to limit query scope.

### D5: Date range filtering

**Decision**: Default to last 12 months of completed cases for trend/distribution charts. Provide a date range picker to adjust the window. Open cases are always shown regardless of date range.

**Rationale**: 12 months gives meaningful trend data without overwhelming the browser. Date range picker allows zooming in on specific periods.

## Risks / Trade-offs

- **[Performance with large datasets]** All analytics computed client-side. For >5000 completed cases, chart rendering may lag. Mitigation: date range filter limits data volume; consider virtualization or pagination if needed in future.
- **[Missing processingDeadline]** Some case types may not have `processingDeadline` set. Mitigation: exclude from SLA calculations, show "No SLA target" label; display count of cases without SLA target.
- **[Duration approximation]** ISO 8601 month-based durations (P2M) approximated as 30 days/month. Mitigation: document the approximation; most Dutch government deadlines use day-based durations (P30D, P56D).
- **[No historical snapshots]** Trend data relies on case `startDate`/`endDate` — if cases are deleted, history is lost. Mitigation: cases are rarely deleted in government workflows; activity log provides audit trail.
