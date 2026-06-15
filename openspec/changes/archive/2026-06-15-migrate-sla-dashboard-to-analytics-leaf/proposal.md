# Proposal: migrate-sla-dashboard-to-analytics-leaf

## Why

Procest ships a bespoke SLA dashboard: `src/views/DoorlooptijdDashboard.vue` renders processing-time
(doorlooptijd) charts with hand-rolled apexcharts, backed by the consumer spec `doorlooptijd-dashboard`.
The chart-rendering surface — donut/series cards, skeletons, chart layout — is generic analytics
plumbing that the fleet now provides centrally.

OpenRegister provides an **analytics** integration leaf (ADR-019) that renders charts/widgets over
OR object data. Re-implementing chart layout, series binding, and skeletons in-app is an **ADR-022**
duplication of the analytics leaf's rendering responsibility.

**Important distinction — domain calc stays, only charts move.** The SLA compliance computation in
`src/utils/doorlooptijdHelpers.js` (`parseDurationToDays`, `getProcessingDays`, `getSlaTargetDays`,
`buildCaseTypeMap`, `computeSlaCompliance`) is **case-domain logic**: it derives SLA targets from the
case-type `processingDeadline`, applies exclusions, and produces per-case-type compliance
breakdowns. That is zaak-domain knowledge OpenRegister's analytics leaf does not own. Per ADR-022,
only the **chart/visualisation** layer is the shared abstraction; the **SLA calculation stays
in-app** (and ideally surfaces as a case/case-type derived field via OR schema extensions — see
design.md).

## What

This change moves the doorlooptijd dashboard's **chart rendering** to the analytics leaf while
keeping the SLA calculation in procest:

1. `DoorlooptijdDashboard.vue` stops embedding apexcharts directly; chart cards are rendered by the
   OR analytics leaf, fed the series produced by `computeSlaCompliance`.
2. `src/utils/doorlooptijdHelpers.js` SLA calculation functions are **kept** — they are case-domain
   logic. They produce the data series the analytics leaf renders.
3. The dashboard view is reduced to: invoke the domain calc, hand the resulting series to the
   analytics leaf widget(s).

## Capabilities

### New Capabilities

- `sla-charts-via-analytics-leaf`: The doorlooptijd dashboard renders its compliance charts through
  OR's analytics integration leaf; procest embeds no chart library of its own. The SLA compliance
  calculation remains an in-app case-domain function feeding the leaf.

### Modified Capabilities

- `doorlooptijd-dashboard` (spec: `procest/openspec/specs/doorlooptijd-dashboard/spec.md`) — the SLA
  compliance calculation requirements are unchanged (domain logic stays); the chart-rendering
  requirements now delegate to the analytics leaf instead of in-app apexcharts.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the analytics leaf is consumed, not modified

## Out of Scope

- The analytics leaf's own implementation in OR.
- The SLA compliance calculation logic in `doorlooptijdHelpers.js` — it STAYS (case-domain logic).
- Replacing apexcharts as the underlying chart engine (the leaf owns the engine choice).
- New SLA metrics beyond what `computeSlaCompliance` already produces.

## Success Criteria

- `openspec validate migrate-sla-dashboard-to-analytics-leaf --strict` exits 0.
- The doorlooptijd dashboard renders compliance charts through the analytics leaf.
- `doorlooptijdHelpers.js` SLA calculation functions remain in-app and feed the leaf.
- `DoorlooptijdDashboard.vue` no longer imports apexcharts directly.
