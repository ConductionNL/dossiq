# Tasks: migrate-inspection-forms-to-forms-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm forms + photos leaf contracts (S)

- [~] P0.1 Confirm the OR forms leaf `id`, that it supports the checklist question types
  (yes/no/`foto`/free-text), and the photos leaf `id`. Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 DECIDE backfill vs sunset-window for existing inline `photos[]`; open a GH issue.

## [procest] Wire the leaves

### P1. Forms + photos rendering (L)

- [~] P1.1 Whitelist the forms + photos leaves on the relevant schema(s)
  (`inspectionChecklistRun` / `case`) `configuration.linkedTypes`.
- [~] P1.2 Render checklist items + advice/consultation forms through the forms leaf.
- [~] P1.3 Store inspection photos through the photos leaf; remove inline `photos[]` write path.

## [procest] Keep domain rules

### P2. Retain gates + immutability (M)

- [~] P2.1 Re-point `ChecklistService` photo-gate (`fotoRequired`) to count photos-leaf attachments.
- [~] P2.2 Confirm `ChecklistRunImmutabilityListener` append-only enforcement is unchanged.
- [~] P2.3 Confirm advice/consultation lifecycle + deadline tracking stays in-app.
- [~] P2.4 Reduce `InspectionChecklistPanel.vue` / `DocumentChecklist.vue` to leaf invocation + gate
  feedback.

## [procest] Quality gates

### P3. Verify (S)

- [~] P3.1 `openspec validate migrate-inspection-forms-to-forms-leaf --strict` exits 0.
- [~] P3.2 `composer check:strict` and `npm run lint` pass; inspection gate + immutability tests pass.

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
