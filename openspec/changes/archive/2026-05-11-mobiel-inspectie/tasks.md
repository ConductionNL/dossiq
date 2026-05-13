# Tasks: mobiel-inspectie

## 1. Backend Services

### Task 1: Create InspectionService
- **spec_ref**: `openspec/specs/mobiel-inspectie/spec.md#req-mob-02-inspection-task-list`
- **files**: `lib/Service/InspectionService.php`
- **acceptance_criteria**:
  - GIVEN inspector with 4 inspections WHEN listing THEN all 4 returned ordered by time
  - GIVEN GPS >500m from planned WHEN location captured THEN warning generated
- [x] Create InspectionService with getInspections(), captureLocation(), completeInspection()

### Task 2: Create ChecklistService
- **spec_ref**: `openspec/specs/mobiel-inspectie/spec.md#req-mob-03-checklist-completion`
- **files**: `lib/Service/ChecklistService.php`
- **acceptance_criteria**:
  - GIVEN 8-item checklist WHEN item completed THEN progress updated (3/8)
  - GIVEN niet-conform with mandatory photo WHEN no photo THEN save blocked
- [x] Create ChecklistService with completeItem(), getProgress(), validateCompletion()

### Task 3: Create InspectionController and PWA manifest
- **spec_ref**: `openspec/specs/mobiel-inspectie/spec.md`
- **files**: `lib/Controller/InspectionController.php`, `appinfo/routes.php`, `img/manifest.json`
- **acceptance_criteria**:
  - GET /api/inspections returns assigned inspections
  - POST /api/inspections/{id}/checklist/{itemId} completes item
  - PWA manifest enables Add to Home Screen
- [x] Create InspectionController
- [x] Create PWA manifest
- [x] Register routes
