# Proposal: Dashboard

## Summary

Implement V1 dashboard capabilities for Procest: Cases by Type chart, three signalering (alerting) widgets for deadline awareness, a Woo (Wet open overheid) deadline tracking panel, a process analytics view with SLA compliance and throughput charts, and a workflow tracking board. Fixes Application.php widget registration and the broken CasesOverviewWidget route.

## Motivation

Market demand analysis across 555 government tender documents identifies the following capability gaps (demand scores from context-brief):

| Feature | Demand | Mapping to this change |
|---------|--------|----------------------|
| Dashboard with real-time processing status overview | 84 | V1 dashboard widgets + signalering section |
| Process Analytics | 159 + 2 | Process analytics view at `/dashboard/analytics` |
| Woo request deadline tracking and workflow | 151 | Woo Deadline Panel on main dashboard |
| Agile Workflow Tracking | 116 | Workflow board at `/workflow-board` |
| Contract expiration tracking with proactive notification | 382 | Signalering Deadline Alerts widget |
| End user tracking (historic process/case state) | 377 | Analytics view: per-case-type SLA trends |
| Workflow and Process Analytics | 55 | Throughput + status distribution charts |
| Return-to-vendor / appeal workflow tracking | 283 | Bezwaar case count in signalering + analytics |

The existing dashboard implements the MVP tier (KPI cards, status chart, overdue panel, my work, activity feed). This change implements the V1 tier defined in `openspec/specs/dashboard/spec.md` and adds the capabilities specified in `openspec/specs/signalering-widgets/spec.md` and `openspec/specs/doorlooptijd-dashboard/spec.md`.

## Affected Projects

- [x] Project: `procest` — Add signalering widgets, Cases by Type chart, Woo deadline panel, process analytics view, workflow board; fix Application.php widget registration

## Scope

### In Scope (V1)

- **REQ-DASH-003**: Cases by Type horizontal bar chart (deferred from dashboard-mvp, now implemented)
- **REQ-DASH-010**: Doorlooptijd navigation link from main dashboard to analytics view
- **REQ-DASH-V1-001**: Signalering Deadline Alerts widget — cases approaching or past their processing deadline
- **REQ-DASH-V1-002**: Signalering Task Due Reminders widget — tasks approaching or past their due date for the current user
- **REQ-DASH-V1-003**: Signalering Stalled Cases widget — cases with no activity for 7+ days
- **REQ-DASH-V1-004**: Woo Deadline Tracking panel — open cases of Woo case types with statutory countdown and traffic-light severity
- **REQ-DASH-V1-005**: Process Analytics view — SLA compliance rate KPI, cases-completed-per-week throughput chart, processing time breakdown by case type
- **REQ-DASH-V1-006**: Workflow Board view — Kanban columns per status type, cases as cards, drag-to-advance status transition
- **REQ-DASH-FIX-001**: Register NC Dashboard widgets in Application.php and fix CasesOverviewWidget route

### Out of Scope

- AI-powered dashboard insights (demand 2) — deferred; requires ChatService integration not yet available in Procest
- Auto-refresh on timer interval — deferred; requires SSE or long-polling infrastructure
- Woo full lifecycle management (case creation, document upload, response generation) — covered by a separate VTH/Woo change
- Workload distribution widget — deferred to V2
- Full doorlooptijd analytics page with histograms — doorlooptijd-dashboard spec handled separately

## Approach

Frontend-only implementation. All data from OpenRegister via existing object stores (`case`, `task`, `caseType`, `statusType`). No new schemas or API endpoints.

- Signalering widgets are client-side computed from collections already fetched by the dashboard
- Woo panel filters cases by `caseType.title` containing "Woo" or tagged with Woo caseType UUIDs
- Process analytics uses `CnChartWidget` (ApexCharts) already available in `@conduction/nextcloud-vue`
- Workflow board uses `CnDashboardPage` GridStack layout with custom column components
- Application.php registers existing widget classes that were missing from the registration call

## Cross-Project Dependencies

- **dashboard-mvp** (archived, implemented) — provides the rewritten `Dashboard.vue` and `dashboardHelpers.js` this change extends
- **signalering-widgets** spec (`openspec/specs/signalering-widgets/spec.md`) — this change implements that spec
- **doorlooptijd-dashboard** spec (`openspec/specs/doorlooptijd-dashboard/spec.md`) — this change implements the analytics subset

## Rollback Strategy

All changes are frontend-only (new Vue components + modified Dashboard.vue + Application.php registration). No database migrations or schema changes. Rollback by reverting Application.php registration and removing the new route/component files.
