# Design: migrate-inspection-forms-to-forms-leaf

## Context

OR's forms leaf (ADR-019) renders a form definition and captures responses against an OR object.
OR's photos leaf attaches/displays images as files-attached-to-object. Procest's checklist +
advice/consultation features couple form *rendering* and photo *storage* (the shared abstractions)
with inspection *domain rules* (immutability, photo gates, lifecycle) that must stay in-app.

## File-by-File Mapping

| Existing procest artifact | Disposition |
|---|---|
| `src/views/cases/components/InspectionChecklistPanel.vue` | Reduced — forms leaf renders items, photos leaf holds photos; panel runs domain gates against results |
| `src/views/cases/components/DocumentChecklist.vue` | Reduced — forms leaf renders the checklist |
| `lib/Service/Inspection/ChecklistService.php` | **Kept** — photo-gate rules (`fotoRequired: altijd / bij_nee / nooit`) + run lifecycle validate leaf-captured data |
| `lib/Listener/ChecklistRunImmutabilityListener.php` | **Kept** — append-only enforcement (REQ-IC-8) |
| `lib/Service/InspectionService.php` | Reduced to orchestration over leaf-captured results |
| inline `photos[]` payloads in checklist items | Replaced by photos-leaf file attachments |
| advice/consultation request input forms | Rendered by the forms leaf |
| advice/consultation lifecycle + deadline tracking | **Kept** — zaak-domain logic |

## ADR-022 boundary (what stays vs moves)

- **Moves to leaves**: form question rendering + response capture (forms leaf); photo storage +
  display (photos leaf).
- **Stays in procest (domain logic)**: checklist append-only immutability, the `fotoRequired`
  photo-gate rules, the inspection-run lifecycle, and the advice/consultation lifecycle/deadlines.

The leaves render and store; procest *validates* the captured data against its domain rules. The
photo gate becomes "the run is invalid unless the photos leaf has ≥1 attachment when `fotoRequired`
demands it" — the gate logic is unchanged, only the photo *source* moves to the leaf.

## DEFERRED_QUESTIONS

- Confirm the OR forms leaf `id` + whether it supports the checklist question types procest uses
  (yes/no/`foto`/free-text) and the pinned `@conduction/nextcloud-vue` version.
- Confirm the OR photos leaf `id` and how `ChecklistService` queries attached photos to run the
  `fotoRequired` gate (file-count on the run/case object).
- DECISION: backfill existing inline `photos[]` into the photos leaf, or sunset-window read of the
  old shape?
- Confirm whether the forms leaf can render a definition stored as a procest
  `inspectionChecklistTemplate` object, or whether a thin mapping is needed.
