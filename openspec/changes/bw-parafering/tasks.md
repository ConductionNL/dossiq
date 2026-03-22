# Tasks: bw-parafering

## 1. Backend Services

### Task 1: Create ParaferingService
- **spec_ref**: `openspec/specs/bw-parafering/spec.md#req-bw-02-configurable-parafeerroute`
- **files**: `lib/Service/ParaferingService.php`
- **acceptance_criteria**:
  - GIVEN a voorstel submitted for parafering WHEN route has 5 sequential steps THEN step 1 is activated first
  - GIVEN step 3 is parallel WHEN reached THEN all parallel actors receive the voorstel
  - GIVEN actor parafes WHEN recorded THEN audit trail entry is immutable
- [x] Create ParaferingService with startParafering(), executeAction(), getAuditTrail()

### Task 2: Create ParaferingController
- **spec_ref**: `openspec/specs/bw-parafering/spec.md`
- **files**: `lib/Controller/ParaferingController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - POST /api/parafering/voorstellen creates voorstel
  - POST /api/parafering/voorstellen/{id}/paraferen records parafering
  - GET /api/parafering/voorstellen/{id}/audit-trail returns full trail
- [x] Create ParaferingController with CRUD and action endpoints
- [x] Register routes
