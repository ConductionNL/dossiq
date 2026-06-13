---
status: implemented
---
# doorlooptijd-dashboard Specification

## Purpose

Provide a dedicated dashboard in Procest for tracking case processing-time (doorlooptijd) compliance — SLA adherence, at-risk and overdue cases, monthly compliance trend, weekly throughput, and average processing time per case type. The primary objective is visibility into statutory decision deadlines (e.g. the 6-week bezwaar beslistermijn, Awb art. 7:10) so behandelaars can act before consequences materialise and managers can monitor team-level compliance over time.

This delta records the **component decomposition** delivered by this change: the originally monolithic `DoorlooptijdDashboard.vue` (≈1195 lines) was split into four focused, independently-testable sub-components, with the chart/table data-shaping logic extracted to a pure, Vitest-covered helper module, while preserving the rendered behaviour and all i18n strings.

The full set of SLA / compliance / at-risk / trend / throughput / breakdown requirements for this dashboard is captured in the canonical capability spec at `openspec/specs/doorlooptijd-dashboard/spec.md`; this change modifies only the page-render requirement to add the decomposition guarantee.

## MODIFIED Requirements

### Requirement: Doorlooptijd page render [MVP]

The doorlooptijd page SHALL mount and render its page shell on navigation
(`DoorlooptijdDashboard.vue`, route `/doorlooptijd`), independently of whether
case data is present. The dashboard SHALL be composed of focused sub-components
under `src/views/doorlooptijd/components/` — a KPI strip (`DeadlineKpiRow`), the
chart cluster (`ComplianceCharts`: SLA-by-case-type donut, processing-time
histogram, monthly compliance trend, weekly throughput), the at-risk deadline
list (`DeadlineCaseTable`), and the per-case-type breakdown table
(`CaseTypeBreakdown`) — each receiving already-aggregated data as props from the
page-level container, with the chart/table data-shaping logic extracted to a
pure helper module (`chartShaping.js`). Decomposition MUST NOT change the
rendered behaviour, the data fetched, or the displayed strings.

#### Scenario: Doorlooptijd page renders heading
- **GIVEN** an authenticated user on the Procest app
- **WHEN** they navigate to the doorlooptijd page
- **THEN** the main content MUST render a "Processing Time Analytics" page heading
- **AND** the page MUST NOT show an Internal Server Error

#### Scenario: Dashboard is composed of focused sub-components

@e2e exclude Structural decomposition guarantee — verified by the chartShaping Vitest suite (data-shaping parity) and by component review, not by browser e2e, since the rendered output is identical to the monolith and carries no new user-observable surface.

- **GIVEN** the doorlooptijd page has mounted with case data present
- **WHEN** the dashboard renders
- **THEN** the page-level container MUST delegate the KPI strip, charts,
  at-risk list, and per-case-type breakdown to dedicated sub-components,
  passing each its already-aggregated data as props
- **AND** the rendered output (KPI values, chart series, table rows, status
  badges, empty states) MUST be identical to the pre-decomposition monolith
