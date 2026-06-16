# Design: doorlooptijd-dashboard

## Architecture Overview

The dashboard reads exclusively from existing `case` and `caseType` objects in OpenRegister — no new schema. A thin PHP service aggregates deadline metrics server-side to avoid shipping thousands of raw case objects to the browser. The frontend is built on `CnDashboardPage` with four `CnStatsBlock` KPI cards, a `CnChartWidget` bar chart, a `CnChartWidget` donut chart, and a `CnTableWidget` for the case list.

```
src/views/doorlooptijd/
└── DoorlooptijdDashboard.vue          ← route /doorlooptijd, CnDashboardPage layout
    ├── DeadlineKpiRow.vue             ← 4× CnStatsBlock: open / at-risk / overdue / on-time%
    ├── DeadlineCaseTable.vue          ← CnTableWidget: cases sorted by days-remaining, RAG badge
    ├── ComplianceChart.vue            ← CnChartWidget bar: monthly compliance % (12 months)
    └── CaseTypeBreakdown.vue          ← CnChartWidget donut: avg doorlooptijd per case type
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/DoorlooptijdService.php` | Compute deadline KPIs, monthly compliance series, per-case-type avg doorlooptijd, and RAG-annotated case list |
| `lib/Controller/DoorlooptijdController.php` | `GET /api/doorlooptijd/metrics` — authenticated, returns aggregated metrics JSON |
| `src/views/doorlooptijd/DoorlooptijdDashboard.vue` | Main dashboard page built on `CnDashboardPage`; owns data fetch and case-type filter state |
| `src/views/doorlooptijd/components/DeadlineKpiRow.vue` | Four `CnStatsBlock` KPI cards: Openstaand, Risico, Verlopen, Op tijd % |
| `src/views/doorlooptijd/components/DeadlineCaseTable.vue` | Sortable `CnTableWidget`: case id, title, type, start date, deadline, days remaining, RAG badge |
| `src/views/doorlooptijd/components/ComplianceChart.vue` | `CnChartWidget` stacked bar: on-time vs. late per calendar month |
| `src/views/doorlooptijd/components/CaseTypeBreakdown.vue` | `CnChartWidget` donut: average doorlooptijd in days per case type |
| `src/services/doorlooptijdApi.js` | Frontend API service — `fetchMetrics({ caseType, period, atRiskDays })` |

### Modified Files

| File | Changes |
|------|---------|
| `appinfo/routes.php` | Add `GET /api/doorlooptijd/metrics` route |
| `src/router/index.js` | Add `/doorlooptijd` named route pointing to `DoorlooptijdDashboard` |
| `src/views/MainMenu.vue` | Add "Doorlooptijd" navigation item (`mdi-clock-alert-outline` icon) |
| `l10n/en.json` | Add English translation keys for all new strings |
| `l10n/nl.json` | Add Dutch translations for all new strings |

## Data Model

No new OpenRegister schemas are introduced. The service reads:

**case** fields consumed:
| Field | Used for |
|-------|---------|
| `id`, `title`, `identifier` | Case identity in the list |
| `caseType` | Reference to caseType object for deadline duration and display name |
| `startDate` | Case start date; base for elapsed-time calculation |
| `endDate` | Case completion date (null = open) |
| `deadline` | Explicit deadline date; if absent, derived from `caseType.processingDeadline` + `startDate` |
| `status` | Used to determine open vs. closed state |

**caseType** fields consumed:
| Field | Used for |
|-------|---------|
| `id`, `title` | Case type identity and display label |
| `processingDeadline` | ISO 8601 duration (e.g. `P42D` = 6 weeks) used when `case.deadline` is absent |

**Metric computation (server-side, `DoorlooptijdService`):**

| Metric | Computation |
|--------|-------------|
| `kpi.open` | Count of cases where `endDate` is null |
| `kpi.atRisk` | Open cases where `0 ≤ daysRemaining ≤ atRiskDays` (default 5) |
| `kpi.overdue` | Open cases where `deadline < today` (daysRemaining < 0) |
| `kpi.onTimePercent` | Closed cases (last 12 months) where `endDate ≤ deadline`, expressed as % of all closed |
| `compliance[month]` | Per calendar month: `onTime` count, `late` count, `percent` |
| `caseTypeBreakdown` | Mean of (`endDate − startDate`) in days per case type, closed cases only |
| `cases` (list) | All open cases, sorted by `daysRemaining` ASC; each annotated with `ragStatus`: `overdue` / `at-risk` / `on-time` |

## API Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/doorlooptijd/metrics` | Required | Aggregated dashboard metrics |

**Query parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `caseType` | (all types) | Filter by case type UUID |
| `period` | `12m` | Reporting window for compliance trend chart |
| `atRiskDays` | `5` | Days-remaining threshold for at-risk classification |

**Response schema:**
```json
{
  "kpi": {
    "open": 18,
    "atRisk": 4,
    "overdue": 2,
    "onTimePercent": 84
  },
  "compliance": [
    { "month": "2025-05", "onTime": 9, "late": 0, "percent": 100 },
    { "month": "2026-03", "onTime": 6, "late": 1, "percent": 85 }
  ],
  "caseTypeBreakdown": [
    { "id": "uuid-a", "title": "Bezwaar omgevingsvergunning", "avgDays": 31.4, "count": 12 },
    { "id": "uuid-b", "title": "Bezwaar bijstandsuitkering", "avgDays": 22.1, "count": 7 }
  ],
  "cases": [
    {
      "id": "uuid-case-1",
      "identifier": "2026-0042",
      "title": "Bezwaar weigering bouwvergunning Kerkstraat 12",
      "caseTypeTitle": "Bezwaar omgevingsvergunning",
      "startDate": "2026-03-05",
      "deadline": "2026-04-16",
      "daysRemaining": -2,
      "ragStatus": "overdue"
    },
    {
      "id": "uuid-case-2",
      "identifier": "2026-0051",
      "title": "Bezwaar afwijzing bijstandsuitkering",
      "caseTypeTitle": "Bezwaar bijstandsuitkering",
      "startDate": "2026-03-18",
      "deadline": "2026-04-29",
      "daysRemaining": 13,
      "ragStatus": "on-time"
    }
  ]
}
```

## Design Decisions

### DD-01: Server-side metric aggregation

**Decision**: All aggregation (totals, percentages, date arithmetic, RAG classification) runs in `DoorlooptijdService.php`, not in the browser.

**Rationale**: A production municipality may have hundreds or thousands of cases. Fetching all case objects to the browser for client-side aggregation wastes bandwidth and increases page-load time. A single `/api/doorlooptijd/metrics` call returns only the pre-computed values the UI needs.

### DD-02: No new schema — reads existing `case` and `caseType`

**Decision**: Zero new OpenRegister schemas for this change.

**Rationale**: All required data (`deadline`, `startDate`, `endDate`, `caseType.processingDeadline`) already exists on ADR-000 entities. Adding a denormalised metrics cache would duplicate data and create synchronisation drift. This change is purely additive on the read path.

### DD-03: Configurable at-risk threshold via query parameter

**Decision**: The at-risk threshold (default 5 days) is a query parameter, not a stored config key.

**Rationale**: Different municipalities have different operational cadences. A municipality with daily case reviews needs a 2-day warning; one with weekly reviews needs 7 days. No schema or `IAppConfig` key required — the default serves most cases and power users can bookmark their preferred threshold.

### DD-04: Deadline derivation fallback

**Decision**: When `case.deadline` is null, the service derives the effective deadline as `startDate + caseType.processingDeadline`.

**Rationale**: Not all cases will have an explicit `deadline` set. The `caseType.processingDeadline` (ISO 8601 duration, e.g. `P42D`) is the authoritative default. Cases with neither field are excluded from deadline-specific metrics but included in doorlooptijd averages once closed.

## Reuse Analysis

Per ADR-012 (deduplication requirement):

| Existing capability | How reused in this change |
|---------------------|--------------------------|
| `ObjectService.findAll()` | Fetch `case` and `caseType` objects with filtering |
| `CnDashboardPage` | Overall dashboard layout with GridStack drag-drop support |
| `CnStatsBlock` | KPI metric cards (open, at-risk, overdue, on-time %) |
| `CnChartWidget` (ApexCharts) | Stacked bar chart (compliance) and donut chart (case-type breakdown) |
| `CnTableWidget` | Deadline case list with client-side sort |
| `CnStatusBadge` | RAG status badge on each case row |
| `CnEmptyState` | Empty state when no cases found |
| `createObjectStore` | Existing case store already registered in `store/store.js` |

**No overlap found** with existing services. The existing `Dashboard.vue` shows a general case overview (counts by status, "My Work" list); this change adds a dedicated doorlooptijd page with deadline arithmetic and compliance trending. No `DoorlooptijdService` or `/api/doorlooptijd/*` endpoint exists in the codebase.

## Seed Data

Seed data is **not required** for this change. No new OpenRegister schemas are introduced. The dashboard reads from existing `case` and `caseType` objects — seed data for those entities is owned by the `base-register-seed-data` change (archived). Per ADR seed-data rules, changes that introduce no new schemas are exempt from the seed-data requirement.
