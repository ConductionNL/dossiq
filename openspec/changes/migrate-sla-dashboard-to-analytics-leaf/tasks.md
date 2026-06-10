# Tasks: migrate-sla-dashboard-to-analytics-leaf

> **Build status (hydra audit 2026-06-10).** Spec is leaf-adoption-only; gated on the OR analytics leaf shipping. The existing DoorlooptijdDashboard.vue uses inline computation today (per dashboard change). Tasks deferred until the leaf is live.

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm analytics leaf contract (S)

- [~] P0.1 Confirm the OR analytics leaf `id`, its series-input contract, whether it renders
  page-level (not just object-sidebar) widgets, and the pinned `@conduction/nextcloud-vue` version.
  Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 Open a GH issue tracking the optional ADR-031 unification (SLA target/compliance as a
  schema-declarative derived field) as a future follow-up.

## [procest] Wire the leaf

### P1. Analytics leaf charts (M)

- [~] P1.1 Replace the apexcharts chart cards in `DoorlooptijdDashboard.vue` with the analytics leaf
  widget(s), fed the series from `computeSlaCompliance`.
- [~] P1.2 Verify empty-data graceful degradation through the leaf.

## [procest] Keep domain calc

### P2. Retain SLA logic (S)

- [~] P2.1 Confirm `doorlooptijdHelpers.js` SLA functions are unchanged and still produce the
  `byType` breakdown consumed by the leaf.
- [~] P2.2 Remove direct apexcharts imports from the dashboard view.

## [procest] Quality gates

### P3. Verify (S)

- [~] P3.1 `openspec validate migrate-sla-dashboard-to-analytics-leaf --strict` exits 0.
- [~] P3.2 `composer check:strict` and `npm run lint` pass; doorlooptijd unit tests for the SLA calc
  still pass.
