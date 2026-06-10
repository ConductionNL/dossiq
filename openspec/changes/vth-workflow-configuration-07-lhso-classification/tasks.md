# Tasks: vth-workflow-configuration-07-lhso-classification


> **Build status (hydra audit 2026-06-10).** `lib/Service/LhsLookupService::lookup()` + `lib/Service/Vth/LhsRecommendationService::recommend()/override()` + `LhsController` ship on dev. The 16-cell LHSO matrix seed (`lhs_matrix_seed.json` + `SeedLhsMatrix` + `SeedVthMatrixCells` repair steps) is wired by member 01. Vue classification-UI remains the open work.
LHSO lookup service + classification UI. Traces to giant Tasks 12, 13.

## 1. LhsoLookupService

- [x] Implement `lookup(gedrag, gevolgen)` → matrix cell (verified on dev: `lib/Service/LhsLookupService.php::lookup()`)
- [x] Implement `getMatrix()` → all 16 cells as a 4×4 array (verified on dev: `LhsLookupService::getMatrix()`)
- [x] Create `LhsoController` (verified on dev: `lib/Controller/LhsController.php` with `/api/vth/lhs/*` routes)
- [x] Validate inputs (gedrag A–D, gevolgen 1–4) (verified on dev: input validation in LhsLookupService + LhsRecommendationService)
- [~] Test lookup across all 16 combinations and invalid inputs (deferred to vth-workflow-configuration-10-testing — existing `tests/Unit/Service/LhsLookupServiceTest` covers a partial slice)

## 2. Classification Panel

- [~] Create `LhsoClassificationPanel.vue` rendering the 4×4 grid (greenfield Vue work; deferred to dedicated VTH UI sprint)
- [~] On cell click, show the suggested intervention + description (greenfield)
- [~] Add intervention selector; show required override-reason textarea when intervention ≠ suggestion (greenfield; backend already supports via `LhsRecommendationService::override()`)
- [~] Save records classification (and override reason) to the case via the case service (greenfield; backend hook ready)
- [~] Test all 16 matrix selections and override-reason visibility/validation (deferred to vth-workflow-configuration-10-testing)
