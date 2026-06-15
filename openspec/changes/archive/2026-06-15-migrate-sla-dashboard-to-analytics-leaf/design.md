# Design: migrate-sla-dashboard-to-analytics-leaf

## Context

OR's analytics integration leaf (ADR-019) renders chart/series widgets over data. The doorlooptijd
dashboard currently couples two concerns that ADR-022 separates: (1) SLA-compliance **computation**
(case-domain logic) and (2) chart **rendering** (generic plumbing). Only (2) is the shared
abstraction. This change splits them: the leaf renders, procest computes.

## File-by-File Mapping

| Existing procest artifact | Disposition |
|---|---|
| `src/views/DoorlooptijdDashboard.vue` | Chart cards / apexcharts embedding replaced by the analytics leaf; view reduced to "compute then hand series to leaf" |
| `src/utils/doorlooptijdHelpers.js` | **Kept** — `parseDurationToDays`, `getProcessingDays`, `getSlaTargetDays`, `buildCaseTypeMap`, `computeSlaCompliance` are case-domain logic |
| direct `apexchart` imports in the view | Removed — the leaf owns the chart engine |

## Why the SLA calc stays (ADR-022 boundary)

`computeSlaCompliance` derives the SLA target from each case type's `processingDeadline`, applies
case-type-specific exclusions, and produces a per-case-type compliance breakdown (`overallRate`,
`withinSla`, `total`, `excluded`, `byType[]`). This is zaak-domain knowledge — the analytics leaf
renders series but does not know what "within SLA" means for a VTH case vs a bezwaar. ADR-022's
boundary is: shared *visualisation* abstraction (leaf) vs app *domain* logic (procest). The calc
stays.

### Optional unification (favor-unification preference)

The long-term unified answer is to express the SLA target/compliance as a **schema-declarative
derived field** on the case / case-type via OR's `x-openregister-calculations` (ADR-031), so the
compliance number is computed once in the schema engine and the analytics leaf reads a plain field.
That is a larger follow-up; this change keeps the calc in `doorlooptijdHelpers.js` and flags the
ADR-031 path as the eventual unification (GH issue at planning time).

## DEFERRED_QUESTIONS

- Confirm the OR analytics leaf `id`, its series/input contract, and whether it renders dashboard-page
  widgets (not just object-sidebar widgets) — the doorlooptijd dashboard is a page, not an object tab.
- Confirm whether the analytics leaf accepts pre-computed series (procest computes) or expects to
  query OR objects itself (would require the ADR-031 derived-field path).
- Confirm `@conduction/nextcloud-vue` version shipping the analytics leaf.
