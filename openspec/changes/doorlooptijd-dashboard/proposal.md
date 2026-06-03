# Proposal: Doorlooptijd Dashboard

## Summary

Add a dedicated Doorlooptijd Dashboard to Procest that tracks case processing-time compliance, with a primary focus on the legally mandated 6-week bezwaar decision deadline (Awb art. 7:10). Behandelaars see a prioritised view of open cases ranked by deadline urgency (Red/Amber/Green); managers see aggregate compliance KPIs, month-by-month trend charts, and average doorlooptijd per case type. No new OpenRegister schema is required — the dashboard reads from existing `case` and `caseType` objects.

## Problem

Dutch municipalities must issue a beslissing op bezwaar within 6 weeks of receiving the bezwaarschrift (Awb art. 7:10 lid 1). Missing this deadline without a formal extension (lid 3-4) exposes the municipality to dwangsom claims under the Wet dwangsom en beroep bij niet tijdig beslissen (€20–€40/day, up to €1,260) and gives the citizen direct access to beroep at the bestuursrechter.

Procest currently has no aggregated deadline view. Behandelaars must open individual cases to check remaining time. Managers cannot assess compliance across the caseload without exporting to a spreadsheet. Cases silently approach — and sometimes exceed — their legal deadline without anyone noticing in time.

A secondary need is general processing time reduction: managers want to identify which case types take the longest on average so they can target process improvements.

## Demand Evidence

### Feature Demand (from context-brief)

| Feature | Demand Score | Tender Mentions |
|---------|-------------|-----------------|
| Track the 6-week statutory bezwaar processing deadline | 147 | 49 |
| Processing time reduction | 1 | — |

### Representative Requirements from Tenders

1. "Binnen vooraf gedefinieerde doorlooptijden afgehandeld."
2. "Termijnbewaking."
3. "De Oplossing kent een interface waarin grafisch wordt weergegeven: Trendanalyses, Termijnbewaking, Procesvoortgang."
4. "Planning en verwachte doorlooptijd."

## Scope — MVP

**In scope:**
- Dashboard page at `/doorlooptijd` with four KPI cards: open cases, at-risk (≤5 days to deadline), overdue, and on-time completion % (last 12 months)
- Sortable case list for all open cases ordered by deadline proximity with RAG status badges (green/amber/red)
- Monthly deadline compliance bar chart (last 12 months: % resolved on time vs. late)
- Average doorlooptijd breakdown per case type (donut chart) — supports the processing-time-reduction feature
- Case type filter updating all widgets simultaneously
- Backend metrics endpoint `GET /api/doorlooptijd/metrics` — aggregates from existing `case` and `caseType` objects, no new schema
- "Doorlooptijd" navigation item in the app sidebar

**Out of scope:**
- Push notifications or in-app alerts when a case approaches its deadline (post-MVP)
- Automatic deadline extension request workflow (post-MVP)
- Citizen-facing deadline status portal (Mijn Overheid integration — separate spec)
- Export of doorlooptijd compliance reports (post-MVP; use existing `CnMassExportDialog`)
- Opschorting (suspension) period exclusion from elapsed time (post-MVP — requires workflow engine change)
- Configurable SLA targets per case type (post-MVP)

## Dependencies

- OpenRegister (read access to existing `case` and `caseType` objects via `ObjectService.findAll()`)
- `@conduction/nextcloud-vue`: `CnDashboardPage`, `CnChartWidget`, `CnStatsBlock`, `CnTableWidget`, `CnStatusBadge`, `CnEmptyState` — all pre-built, no new library needed
- Existing Procest case store (`createObjectStore`) — no new Pinia store required

## Acceptance Criteria

1. GIVEN open bezwaar cases in Procest, WHEN a behandelaar opens `/doorlooptijd`, THEN they see four KPI cards (open, at-risk, overdue, on-time %) and a case list sorted by days-remaining ascending with colour-coded urgency badges
2. GIVEN a case with `deadline` earlier than today, WHEN the dashboard loads, THEN that case appears at the top of the list with a red "Verlopen" badge and a negative days-remaining value
3. GIVEN closed cases over the last 12 months, WHEN the compliance chart renders, THEN it shows one stacked bar per calendar month with on-time (green) and late (red) proportions
4. GIVEN completed cases grouped by case type, WHEN the breakdown donut renders, THEN each segment shows the case type title and average doorlooptijd in days
5. GIVEN the manager selects case type "Bezwaar omgevingsvergunning" in the filter, WHEN all widgets update, THEN every KPI, chart, and table row reflects only that case type
6. GIVEN the API is called unauthenticated, WHEN `GET /api/doorlooptijd/metrics` is requested, THEN the response MUST be 403 Forbidden
