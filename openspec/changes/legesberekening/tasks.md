# Tasks: legesberekening

## 1. Calculation Engine

### Task 1: Create LegesCalculationService
- **spec_ref**: `openspec/specs/legesberekening/spec.md#req-leges-01-fee-calculation-on-case-attributes`
- **files**: `lib/Service/LegesCalculationService.php`
- **acceptance_criteria**:
  - GIVEN bouwkosten EUR 180,000 and staffel verordening WHEN calculation triggered THEN result is EUR 4,750
  - GIVEN a sloopmelding WHEN calculation triggered THEN fixed amount EUR 250 returned
  - GIVEN corrected bouwkosten WHEN recalculated THEN uses corrected amount and preserves history
- [x] Create LegesCalculationService with calculate(), recalculate() methods

### Task 2: Create LegesExportService
- **spec_ref**: `openspec/specs/legesberekening/spec.md#req-leges-05-export-to-financial-system`
- **files**: `lib/Service/LegesExportService.php`
- **acceptance_criteria**:
  - GIVEN 5 definitieve berekeningen WHEN export triggered THEN CSV/XML export generated
  - Export includes NAW, BSN/KvK, zaaknummer, artikel, bedrag, datum
- [x] Create LegesExportService with exportToCSV(), exportToXML() methods

### Task 3: Create LegesController
- **spec_ref**: `openspec/specs/legesberekening/spec.md`
- **files**: `lib/Controller/LegesController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - POST /api/leges/calculate triggers calculation
  - GET /api/leges/calculations/{caseId} returns history
  - POST /api/leges/export generates export file
- [x] Create LegesController with calculate, history, export endpoints
- [x] Register routes
