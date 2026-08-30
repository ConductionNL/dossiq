# sla-charts-via-analytics-leaf Specification

## ADDED Requirements

### Requirement: Doorlooptijd Charts Render Through The OR Analytics Leaf

Procest SHALL render the doorlooptijd (SLA) dashboard charts through OpenRegister's `analytics`
integration leaf (ADR-019). Procest SHALL NOT embed a chart library (apexcharts) directly in the
dashboard view after this migration.

@e2e exclude The chart render is driven by OpenRegister's page-level analytics-series surface (cross-app, ADR-019) — series are registered via `POST /api/integrations/analytics/series` and drawn by `@conduction/nextcloud-vue`'s declarative `CnChartWidget` (which owns the chart engine). The procest-side change is the removal of the bespoke `vue-apexcharts` import/option-shaping (a static no-direct-chart-lib check, verified by code review + the absence of the dep in `package.json`) plus the series-registration wiring; none is a procest-only browser UI surface drivable without the OR analytics-series surface installed. Mirrors the case-map-via-maps-leaf / inspection-forms-via-forms-leaf precedent.

#### Scenario: Compliance charts come from the analytics leaf

- **GIVEN** completed cases and the OR analytics leaf available
- **WHEN** a team lead opens the doorlooptijd dashboard
- **THEN** the compliance charts SHALL be rendered by the analytics leaf
- **AND** `DoorlooptijdDashboard.vue` SHALL NOT import apexcharts directly

#### Scenario: Empty data degrades gracefully

- **GIVEN** no completed cases match the current filter
- **WHEN** the dashboard renders
- **THEN** the analytics leaf SHALL render its empty state
- **AND** the dashboard SHALL NOT error

---

### Requirement: The SLA Compliance Calculation Stays In-App As Case-Domain Logic

Procest SHALL retain the SLA compliance calculation (`doorlooptijdHelpers.js`:
`parseDurationToDays`, `getProcessingDays`, `getSlaTargetDays`, `buildCaseTypeMap`,
`computeSlaCompliance`) in-app, because deriving SLA targets from case-type `processingDeadline`
and applying case-type exclusions is zaak-domain logic the analytics leaf does not own.

@e2e exclude This is a pure-function arithmetic guarantee (the SLA maths stays in `doorlooptijdHelpers.js`, the series shaping in `chartShaping.js`) locked by Vitest (`doorlooptijdChartShaping.spec.js`) — not a browser UI surface. That `getSlaTargetDays` derives the target from the case-type `processingDeadline` and that the resulting series is handed to the analytics leaf are unit-test + code-review assertions, not procest-only e2e flows.

#### Scenario: Domain calc feeds the leaf

- **GIVEN** the dashboard after this migration
- **WHEN** the compliance series is produced
- **THEN** `computeSlaCompliance` SHALL still compute `overallRate`, `withinSla`, `total`,
  `excluded`, and the per-case-type `byType` breakdown
- **AND** the resulting series SHALL be handed to the analytics leaf for rendering

#### Scenario: SLA target still derives from case type

- **GIVEN** a case whose case type defines `processingDeadline`
- **WHEN** SLA compliance is computed
- **THEN** `getSlaTargetDays` SHALL derive the target from the case-type `processingDeadline`
- **AND** the analytics leaf SHALL NOT be responsible for this derivation
