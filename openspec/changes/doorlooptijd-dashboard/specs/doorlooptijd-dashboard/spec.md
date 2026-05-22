---
status: proposed
---
# doorlooptijd-dashboard Specification

## Purpose

Provide a dedicated dashboard in Procest for tracking case processing-time compliance. The primary objective is real-time visibility into the legally mandated 6-week bezwaar decision deadline (Awb art. 7:10), enabling behandelaars to identify at-risk and overdue cases before consequences materialise, and enabling managers to monitor team-level deadline compliance over time. A secondary objective is identifying which case types have the longest average doorlooptijd to support targeted process improvement.

## Context

The Wet dwangsom en beroep bij niet tijdig beslissen (Stb. 2009, 383) entitles citizens to financial compensation (€20–€40/day, maximum €1,260) if a Dutch municipality misses its statutory decision deadline and the citizen invokes dwangsom. For bezwaar, the standard beslistermijn is 6 weeks from receipt of the bezwaarschrift (Awb art. 7:10 lid 1), extendable once by 6 weeks with citizen notification (lid 3), or indefinitely by consent (lid 4). A missed deadline also gives the citizen direct access to beroep at the bestuursrechter, bypassing the ordinary bezwaar route.

Procest's `case` entity already stores `deadline`, `startDate`, `endDate`, and a reference to `caseType`. The `caseType` entity stores `processingDeadline` as an ISO 8601 duration (e.g. `P42D` = 42 calendar days, or 6 weeks). This dashboard aggregates these existing fields into actionable views without any schema change.

This feature has a demand score of 147 (49 distinct tender mentions), reflecting that deadline compliance monitoring is a standard requirement in Dutch municipal case-management procurement specifications. The processing-time-reduction feature (demand: 1) is addressed by the per-case-type breakdown chart.

## Requirements

### Requirement REQ-DD-001: KPI overview — at-a-glance deadline health

The dashboard MUST display four Key Performance Indicator (KPI) cards giving an immediate overview of the current caseload's deadline status.

#### Scenario REQ-DD-001-A: KPI cards render correct counts on load

- GIVEN Procest has open and closed cases in OpenRegister
- WHEN a behandelaar navigates to `/doorlooptijd`
- THEN the page MUST show a loading indicator immediately, then render four `CnStatsBlock` KPI cards:
  1. **Openstaand** — count of cases where `endDate` is null
  2. **Risico** — count of open cases where deadline is within the next 5 calendar days (inclusive)
  3. **Verlopen** — count of open cases where `deadline` is before today
  4. **Op tijd (%)** — percentage of cases closed in the last 12 months where `endDate ≤ deadline`
- AND the **Verlopen** card MUST use a red visual treatment
- AND the **Risico** card MUST use an amber visual treatment
- AND the **Op tijd (%)** card MUST use a green visual treatment

#### Scenario REQ-DD-001-B: KPI cards update when case type filter is applied

- GIVEN a teammanager is viewing the doorlooptijd dashboard showing all case types
- WHEN the manager selects "Bezwaar omgevingsvergunning" from the case type filter
- THEN all four KPI cards MUST recalculate to show values for that case type only
- AND the URL MUST update to include `?caseType=<uuid>` so the view is bookmarkable

#### Scenario REQ-DD-001-C: Empty state when no cases exist

- GIVEN no cases are present in OpenRegister
- WHEN a user navigates to `/doorlooptijd`
- THEN the KPI cards MUST all display `0` values
- AND a `CnEmptyState` MUST appear below the cards with the message: "Geen zaken gevonden."

### Requirement REQ-DD-002: Deadline case list with RAG status

The dashboard MUST include a sortable table of all open cases ordered by urgency (days remaining ascending), each annotated with a Red/Amber/Green status badge.

#### Scenario REQ-DD-002-A: Case list shows all open cases sorted by urgency

- GIVEN 10 open bezwaar cases with varying deadlines
- WHEN the doorlooptijd dashboard loads
- THEN the `DeadlineCaseTable` MUST show, for each open case:
  - Zaaknummer (e.g. `2026-0042`)
  - Zaaktitel
  - Zaaktype name
  - Startdatum
  - Deadline date
  - Resterende dagen (negative integer if overdue)
  - `CnStatusBadge`: red with label "Verlopen" (overdue), amber with label "Risico" (≤5 days), green with label "Op tijd" (>5 days)
- AND cases MUST be sorted by days remaining ascending (most urgent first)
- AND clicking a row MUST navigate to the case detail page at `/cases/:id`

#### Scenario REQ-DD-002-B: Overdue cases display negative days

- GIVEN case `2026-0042` has `deadline = 2026-04-10` and today is `2026-04-16`
- WHEN the case list renders
- THEN `2026-0042` MUST show `−6 dagen` in the resterende-dagen column
- AND its `CnStatusBadge` MUST be red with label "Verlopen"
- AND it MUST appear at the top of the list

#### Scenario REQ-DD-002-C: Case list is sortable by column header click

- GIVEN the case list is displayed
- WHEN the user clicks the "Deadline" column header
- THEN the list MUST toggle sort between ascending and descending order by deadline date
- AND the active sort direction MUST be indicated by a directional arrow

#### Scenario REQ-DD-002-D: Case list filters when case type filter is applied

- GIVEN the manager has selected case type "Bezwaar bijstandsuitkering"
- WHEN the case list renders
- THEN only cases of type "Bezwaar bijstandsuitkering" MUST appear in the table

#### Scenario REQ-DD-002-E: Case list is empty when no open cases match filter

- GIVEN the selected case type has no open cases
- WHEN the case list renders
- THEN the table MUST show an empty-state row: "Geen openstaande zaken gevonden voor dit zaaktype."

### Requirement REQ-DD-003: Monthly deadline compliance chart

The dashboard MUST show a stacked bar chart of deadline compliance for the last 12 calendar months to enable trend analysis.

#### Scenario REQ-DD-003-A: Compliance chart shows 12 months with on-time and late split

- GIVEN closed cases exist across the last 12 calendar months
- WHEN the `ComplianceChart` renders using `CnChartWidget` (bar type)
- THEN it MUST show one bar per calendar month, chronological left-to-right
- AND each bar MUST be stacked: on-time completions (green) on top of late completions (red)
- AND the Y-axis MUST show percentage 0–100%
- AND hovering a bar MUST display a tooltip such as: "April 2026 — Op tijd: 8 (89%), Te laat: 1 (11%)"

#### Scenario REQ-DD-003-B: Months with no closed cases render as empty bars

- GIVEN a calendar month where no cases were closed
- WHEN that month appears on the chart
- THEN the bar for that month MUST have height 0% with no data errors
- AND the month label MUST still appear on the X-axis to maintain visual continuity

#### Scenario REQ-DD-003-C: Chart respects case type filter

- GIVEN the teammanager has selected "Bezwaar omgevingsvergunning" in the filter
- WHEN the compliance chart renders
- THEN all 12 bars MUST reflect only cases of that case type

### Requirement REQ-DD-004: Average doorlooptijd per case type breakdown

The dashboard MUST show a donut chart of average doorlooptijd per case type to support processing-time-reduction analysis.

#### Scenario REQ-DD-004-A: Donut chart shows one segment per case type

- GIVEN multiple case types each with at least one closed case
- WHEN the `CaseTypeBreakdown` chart renders using `CnChartWidget` (donut type)
- THEN it MUST show one segment per case type with completed cases
- AND each segment MUST be labelled with the case type title
- AND hovering MUST display a tooltip: "Bezwaar omgevingsvergunning: gemiddeld 31,4 dagen (12 zaken)"

#### Scenario REQ-DD-004-B: Case types with no closed cases are excluded

- GIVEN a case type "Bezwaar nieuw" with only open cases and zero completed cases
- WHEN the breakdown chart renders
- THEN "Bezwaar nieuw" MUST NOT appear as a chart segment

#### Scenario REQ-DD-004-C: Chart legend sorted by average doorlooptijd descending

- GIVEN case types with varying average doorlooptijden
- WHEN the chart legend renders
- THEN case types MUST be listed in the legend sorted by `avgDays` descending (longest first)
- AND the case type with the highest average doorlooptijd MUST receive a visually distinct accent colour to draw attention

### Requirement REQ-DD-005: Case type filter

The dashboard MUST support filtering the entire view by case type so that users can focus on a specific category.

#### Scenario REQ-DD-005-A: Filter dropdown lists all case types with cases

- GIVEN case types "Bezwaar omgevingsvergunning" and "Bezwaar bijstandsuitkering" each have at least one case
- WHEN a user opens the case type filter dropdown
- THEN both case types MUST be listed
- AND "(Alle zaaktypen)" MUST be the first (default) option
- AND selecting a case type MUST simultaneously update the four KPI cards, the case list, the compliance chart, and the case-type breakdown

#### Scenario REQ-DD-005-B: Filter state is preserved via URL query parameter

- GIVEN a manager has selected "Bezwaar omgevingsvergunning" and bookmarked the resulting URL
- WHEN the manager later opens the bookmarked URL
- THEN the dashboard MUST load with the case type pre-selected
- AND the `?caseType=<uuid>` query parameter MUST drive the initial filtered state on page load

### Requirement REQ-DD-006: Loading and error handling

The dashboard MUST communicate loading state clearly and handle API errors gracefully.

#### Scenario REQ-DD-006-A: Loading skeleton shown during fetch

- GIVEN the `/api/doorlooptijd/metrics` response takes longer than 200 ms
- WHEN the user navigates to `/doorlooptijd`
- THEN a `NcLoadingIcon` or loading skeleton MUST be visible in each widget area
- AND no broken or partially-rendered widgets MUST appear before the data arrives

#### Scenario REQ-DD-006-B: Dashboard renders within 3 seconds for 500 open cases

- GIVEN OpenRegister contains 500 open bezwaar cases
- WHEN a behandelaar navigates to `/doorlooptijd`
- THEN the `/api/doorlooptijd/metrics` endpoint MUST return a response within 3 seconds
- AND the browser MUST render all four KPI cards and begin rendering charts upon response arrival

#### Scenario REQ-DD-006-C: API error shown as user-facing notification

- GIVEN the `/api/doorlooptijd/metrics` endpoint returns a 500 error
- WHEN the dashboard attempts to load
- THEN a `NcDialog` or Nextcloud notification MUST display: "Kan doorlooptijdgegevens niet laden. Probeer het opnieuw."
- AND the KPI cards MUST NOT display stale or zero values that could be misread as valid data

## Dependencies

- OpenRegister (`case` and `caseType` objects — read access via `ObjectService.findAll()`)
- `@conduction/nextcloud-vue` v1.x: `CnDashboardPage`, `CnChartWidget`, `CnStatsBlock`, `CnTableWidget`, `CnStatusBadge`, `CnEmptyState`

---

## Current Implementation Status

**Not yet implemented.** No doorlooptijd metrics endpoint, aggregation service, or dedicated dashboard Vue component exists in the Procest codebase.

**Foundation available:**
- The `case` entity in OpenRegister already stores `deadline`, `startDate`, `endDate`, and `caseType` — the complete raw inputs for all metrics.
- `caseType.processingDeadline` (ISO 8601 duration) provides the 6-week bezwaar deadline configuration.
- `CnDashboardPage`, `CnStatsBlock`, `CnChartWidget`, and `CnTableWidget` are available in `@conduction/nextcloud-vue` without any new dependency.
- The existing `Dashboard.vue` in `src/views/dashboard/` demonstrates the established pattern for dashboard pages in Procest.

**Partial implementations:** None.

## Standards & References

- **Awb art. 7:10**: Statutory 6-week bezwaar beslistermijn (lid 1); extension by 6 weeks with citizen notification (lid 3); extension by consent (lid 4).
- **Wet dwangsom en beroep bij niet tijdig beslissen (Stb. 2009, 383)**: Citizen entitlement to dwangsom (€20–€40/day, max €1,260) for late decisions; right to beroep after second missed deadline.
- **VNG GEMMA Zaakgericht Werken**: Doorlooptijdmonitoring is a standard monitoring requirement in the GEMMA ZGW reference architecture.
- **WCAG AA**: RAG status MUST NOT rely solely on colour — `CnStatusBadge` includes a text label ("Verlopen", "Risico", "Op tijd") alongside colour coding.
- **ADR-004 frontend**: All strings via `t(appName, 'key')`, Dutch translations in `l10n/nl.json`, CSS via Nextcloud variables only.
- **ADR-003 backend**: Controller → Service layering; `@spec` PHPDoc annotation on every new class and public method.
