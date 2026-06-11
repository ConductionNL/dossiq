# Tasks: migrate-sla-dashboard-to-analytics-leaf

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

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.
