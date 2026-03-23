## 1. Utility Functions [V1]

- [x] 1.1 Create `src/utils/doorlooptijdHelpers.js` with ISO 8601 duration parser (`parseDurationToDays`) that converts `P30D`, `P6W`, `P2M` etc. to calendar days
- [x] 1.2 Add `computeSlaCompliance(completedCases, caseTypes)` — returns overall compliance rate, per-case-type breakdown, and excluded count (cases without SLA target)
- [x] 1.3 Add `computeProcessingTimeDistribution(completedCases, caseTypes, bins)` — returns histogram data with configurable bin ranges
- [x] 1.4 Add `computeMonthlyTrend(completedCases, caseTypes, months)` — returns monthly compliance rate array for the last N months
- [x] 1.5 Add `getAtRiskCases(openCases, caseTypes, thresholdPct)` — returns open cases with less than `thresholdPct` (default 25%) time remaining, sorted by urgency
- [x] 1.6 Add `computePerformanceTable(completedCases, caseTypes)` — returns per-case-type rows with target days, avg actual days, compliance %, and status indicator

## 2. Doorlooptijd Dashboard View [V1]

- [x] 2.1 Create `src/views/DoorlooptijdDashboard.vue` with layout: KPI header, date range filter, charts row, at-risk panel, performance table
- [x] 2.2 Implement date range filter component with presets (Last 3 months, Last 6 months, Last 12 months, This year, All time) — default to Last 12 months
- [x] 2.3 Implement SLA compliance rate KPI display (percentage, count label, excluded cases note)
- [x] 2.4 Implement SLA compliance breakdown donut chart using vue-apexcharts (within-SLA vs overdue per case type)
- [x] 2.5 Implement processing time distribution histogram using vue-apexcharts with SLA target reference line when single case type is filtered
- [x] 2.6 Implement monthly SLA trend line chart using vue-apexcharts with 100% reference line and gap handling for empty months
- [x] 2.7 Implement at-risk cases panel with progress bars, overdue badges, and urgency sorting
- [x] 2.8 Implement performance table with sortable columns (Case Type, Target, Avg Actual, Compliance %, Status indicator)
- [x] 2.9 Implement case type filter dropdown that updates all charts and tables
- [x] 2.10 Implement empty states: no cases, no SLA targets configured, no data in range
- [x] 2.11 Implement loading skeletons for initial data fetch

## 3. Data Fetching [V1]

- [x] 3.1 Add data fetching in DoorlooptijdDashboard mounted hook: fetch all cases (limit 5000), case types, and status types via objectStore
- [x] 3.2 Separate completed and open cases using statusType.isFinal, apply date range filter to completed cases
- [x] 3.3 Wire date range changes and case type filter to recompute all analytics from cached data

## 4. Main Dashboard Integration [V1]

- [x] 4.1 Add SLA Compliance KPI card to Dashboard.vue KPI row (fifth card) with percentage display and link to doorlooptijd view
- [x] 4.2 Compute SLA compliance for current month's completed cases in Dashboard.vue loadDashboardData
- [x] 4.3 Update DEFAULT_LAYOUT grid to accommodate fifth KPI card (adjust gridWidth from 3 to 2.4 or use responsive 5-column)
- [x] 4.4 Add "Doorlooptijd" navigation tab/button in dashboard header linking to /doorlooptijd route

## 5. Routing [V1]

- [x] 5.1 Add `/doorlooptijd` route entry in the Vue router configuration pointing to DoorlooptijdDashboard.vue

## 6. Internationalization [V1]

- [x] 6.1 Add Dutch (nl) and English (en) translation strings for all doorlooptijd dashboard labels, tooltips, empty states, and date range presets
