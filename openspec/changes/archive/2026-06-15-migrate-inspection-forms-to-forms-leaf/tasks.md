# Tasks: migrate-inspection-forms-to-forms-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> IMPLEMENTED 2026-06-15. The "leaf not released" blocker in the deferral
> block below is stale: OR's `FormsProvider` + `PhotosProvider` are present +
> DI-registered on openregister development, and `@conduction/nextcloud-vue`
> (beta.108) ships `CnFormsTab` + `CnPhotosTab`. Form rendering + photo storage
> were migrated to the leaves; the checklist photo-gate + append-only
> immutability stay in-app and validate the leaf-captured data.

## [procest] Pre-migration Verification

### P0. Confirm forms + photos leaf contracts (S)

- [x] P0.1 Confirmed: OR forms leaf id `forms` (`FormsProvider`, link-table backed) and photos
  leaf id `photos` (`PhotosProvider`, link-table backed); `@conduction/nextcloud-vue`
  `^1.0.0-beta.108` ships the bespoke `CnFormsTab` + `CnPhotosTab`. The forms leaf renders the
  NC Forms definition (yes/no/foto/free-text map to NC Forms question types).
- [x] P0.2 DECIDED: sunset-window for existing inline `photos[]` — `photoCount()` still counts a
  legacy inline blob as a backwards-compat fallback, but `stripInlinePhotoBlobs()` ensures new
  submissions never persist one. Backfill of legacy blobs into the photos leaf is a follow-up
  GH issue, not a blocker.

## [procest] Wire the leaves

### P1. Forms + photos rendering (L)

- [x] P1.1 Whitelisted `forms` + `photos` on the `case` schema and on `inspectionChecklistRun`
  `configuration.linkedTypes` in `lib/Settings/procest_register.json`.
- [x] P1.2 Surfaced the forms leaf (`FormsLeafTab` → `CnFormsTab`) as the `forms` sidebar tab on
  `CaseDetail`, resolved from the lib `builtinIntegrations` registry via
  `src/integrations/leafTabs.js`; reduced bespoke `InspectionChecklistPanel.vue` to a thin
  domain-status panel (no hand-rendered question inputs).
- [x] P1.3 Surfaced the photos leaf (`PhotosLeafTab` → `CnPhotosTab`) as the `photos` sidebar tab;
  removed the inline `photos[]` write path — `submitRun()` strips inline photo blobs and persists
  only the leaf references (`photoRefs`).

## [procest] Keep domain rules

### P2. Retain gates + immutability (M)

- [x] P2.1 Re-pointed the `ChecklistService` photo-gate (`fotoRequired: altijd | bij_nee | nooit`)
  to count photos-leaf attachment references via the new `photoCount()` helper (prefers
  `photoRefs` from the leaf, falls back to legacy inline count for old runs).
- [x] P2.2 `ChecklistRunImmutabilityListener` append-only enforcement (REQ-IC-8) unchanged.
- [x] P2.3 Advice/consultation lifecycle + deadline tracking unchanged (domain logic stays in-app).
- [x] P2.4 `InspectionChecklistPanel.vue` reduced to leaf-invocation guidance + gate-rule feedback.
  `DocumentChecklist.vue` is a *document-presence* widget (Files domain, not form-question
  rendering) and is left unchanged — it is not the ADR-022 forms duplication this change targets.

## [procest] Quality gates

### P3. Verify (S)

- [x] P3.1 `openspec validate migrate-inspection-forms-to-forms-leaf --strict` exits 0.
- [x] P3.2 PHPUnit 1342 pass (2 skipped), vitest 230 pass, `npm run build` clean, hydra gates 24/24;
  photo-gate + immutability behaviour preserved (`InspectionChecklistServiceTest` green).

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
