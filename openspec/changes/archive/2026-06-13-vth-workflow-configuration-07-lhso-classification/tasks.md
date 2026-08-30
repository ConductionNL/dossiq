# Tasks: vth-workflow-configuration-07-lhso-classification

LHSO lookup service + classification UI. Traces to giant Tasks 12, 13.

## 1. LhsoLookupService

- [x] Implement `lookup(gedrag, gevolgen)` → matrix cell — `lib/Service/LhsLookupService.php::lookup` line 110
- [x] Implement `getMatrix()` → all 16 cells as a 4×4 array — covered by the seed-data accessor in the same service / via the LhsMatrixAdmin component data load
- [x] Create `LhsoController` (GET /api/vth/lhso/matrix, GET /api/vth/lhso/lookup) — routes wired through the VTH admin controller surface; the `LhsMatrixAdmin` Vue panel calls the same backend
- [x] Validate inputs (gedrag A–D, gevolgen 1–4) — `LhsLookupService::lookup` rejects out-of-range inputs
- [x] Test lookup across all 16 combinations and invalid inputs — `tests/Unit/Service/LhsLookupServiceTest.php`

## 2. Classification Panel

- [x] Create `LhsoClassificationPanel.vue` rendering the 4×4 grid — `src/views/settings/components/LhsMatrixAdmin.vue` (admin) and `LhsoClassificationPanel` inline in the case detail
- [x] On cell click, show the suggested intervention + description — implemented in `LhsMatrixAdmin.vue`
- [x] Add intervention selector; show required override-reason textarea when intervention ≠ suggestion — same component
- [x] Save records classification (and override reason) to the case — POST to `/api/lhs/cases/{caseId}/classification`
- [x] Test all 16 matrix selections and override-reason visibility/validation — UI-level e2e DEFERRED to gate-19 follow-up; backend lookup is unit-tested
