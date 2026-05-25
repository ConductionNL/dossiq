---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Inspection Checklists — execution surface (retrofit)

## Requirements

### REQ-001: InspectionController SHALL expose the in-field inspection lifecycle endpoints

`OCA\Procest\Controller\InspectionController` SHALL provide endpoints for the field inspector: `index()` (list assigned inspections), `captureLocation($id)` (record GPS), `completeChecklistItem($id, $itemId)` (mark one item with conformity + comment + mandatory photos), `addPhoto($id)` (attach photo with EXIF metadata), and `complete($id)` (finalise the inspection with overall conclusion). Each endpoint SHALL delegate to `InspectionService` or `ChecklistService` and SHALL enforce that the calling user is the assigned inspector for the record.

#### Scenario: Inspector completes an item with mandatory photo
- **GIVEN** a checklist item flagged `photoRequired: true`
- **WHEN** the inspector calls `completeChecklistItem` without attaching at least one photo
- **THEN** the controller SHALL respond `400 Bad Request` and the item SHALL remain incomplete

### REQ-002: InspectionService SHALL implement field-side state mutations

`OCA\Procest\Service\InspectionService` SHALL implement `getInspections()` (filter by user/case/status), `captureLocation()` (persist GPS + accuracy + timestamp), `addPhoto()` (attach a photo with EXIF metadata + GPS extracted from the file), and `completeInspection()` (mark all items final, persist conclusion, transition the parent case if configured). Location and photo timestamps SHALL be persisted as `DateTime` and tagged with the capturing user — never overwritten by a later edit.

#### Scenario: Complete an inspection with non-conformities
- **GIVEN** an inspection with at least one item flagged non-conforming
- **WHEN** `completeInspection($inspection, 'Niet conform; correctie vereist')` is called
- **THEN** the inspection SHALL be marked completed and a corrective-action workflow SHALL be triggered on the parent case if one is configured for non-conform outcomes

### REQ-003: ChecklistService SHALL compute item completion + progress + conformity summary

`OCA\Procest\Service\ChecklistService` (top-level, separate from `lib/Service/Inspection/ChecklistService.php` which handles template lifecycle) SHALL provide `completeItem()`, `getProgress()` (returns `{completed, total, percent}`), `validateCompletion()` (enforces mandatory items + photo-required rules), and `getConformitySummary()` (returns `{conforming, nonConforming, na, pending}` counts). All four methods SHALL be pure with respect to the checklist payload — no I/O — so they are reusable for both server-side validation and dry-run preview.

#### Scenario: Pure progress calculation
- **WHEN** `ChecklistService::getProgress($checklist)` is called
- **THEN** the result SHALL be derivable from the checklist payload alone (no database lookups) so the same calculation can run in unit tests

Notes
- The duplicate-file callout (Service/ChecklistService.php vs Service/Inspection/ChecklistService.php) is preserved: the top-level service handles per-run progress, the namespaced one handles templates. Consolidation deferred to a future refactor change.
