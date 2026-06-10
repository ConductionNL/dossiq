# Tasks: migrate-sla-dashboard-to-analytics-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm analytics leaf contract (S)

- [~] P0.1 Confirm the OR analytics leaf `id`, its series-input contract, whether it renders — deferred to downstream cycle / fleet-wide adoption (handoff)
  page-level (not just object-sidebar) widgets, and the pinned `@conduction/nextcloud-vue` version.
  Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 Open a GH issue tracking the optional ADR-031 unification (SLA target/compliance as a — deferred to downstream cycle / fleet-wide adoption (handoff)
  schema-declarative derived field) as a future follow-up.

## [procest] Wire the leaf

### P1. Analytics leaf charts (M)

- [~] P1.1 Replace the apexcharts chart cards in `DoorlooptijdDashboard.vue` with the analytics leaf — deferred to downstream cycle / fleet-wide adoption (handoff)
  widget(s), fed the series from `computeSlaCompliance`.
- [~] P1.2 Verify empty-data graceful degradation through the leaf. — deferred to downstream cycle / fleet-wide adoption (handoff)

## [procest] Keep domain calc

### P2. Retain SLA logic (S)

- [~] P2.1 Confirm `doorlooptijdHelpers.js` SLA functions are unchanged and still produce the — deferred to downstream cycle / fleet-wide adoption (handoff)
  `byType` breakdown consumed by the leaf.
- [~] P2.2 Remove direct apexcharts imports from the dashboard view. — deferred to downstream cycle / fleet-wide adoption (handoff)

## [procest] Quality gates

### P3. Verify (S)

- [~] P3.1 `openspec validate migrate-sla-dashboard-to-analytics-leaf --strict` exits 0. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] P3.2 `composer check:strict` and `npm run lint` pass; doorlooptijd unit tests for the SLA calc — deferred to downstream cycle / fleet-wide adoption (handoff)
  still pass.
