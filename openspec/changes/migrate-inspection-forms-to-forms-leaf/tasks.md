# Tasks: migrate-inspection-forms-to-forms-leaf

> **Build status (hydra audit 2026-06-10).** Spec is leaf-adoption-only; gated on the OR forms + photos leaf shipping. Current InspectionChecklistService ships its own form layer. Tasks deferred until the leaf landing.

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
