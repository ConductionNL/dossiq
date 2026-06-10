# Tasks: vth-workflow-configuration-07-lhso-classification


> **Build status (hydra audit 2026-06-10).** `lib/Service/LhsLookupService::lookup()` + `lib/Service/Vth/LhsRecommendationService::recommend()/override()` + `LhsController` ship on dev. The 16-cell LHSO matrix seed (`lhs_matrix_seed.json` + `SeedLhsMatrix` + `SeedVthMatrixCells` repair steps) is wired by member 01. Vue classification-UI remains the open work.
LHSO lookup service + classification UI. Traces to giant Tasks 12, 13.

## 1. LhsoLookupService

- [ ] Implement `lookup(gedrag, gevolgen)` → matrix cell
- [ ] Implement `getMatrix()` → all 16 cells as a 4×4 array
- [ ] Create `LhsoController` (GET /api/vth/lhso/matrix, GET /api/vth/lhso/lookup)
- [ ] Validate inputs (gedrag A–D, gevolgen 1–4)
- [ ] Test lookup across all 16 combinations and invalid inputs

## 2. Classification Panel

- [ ] Create `LhsoClassificationPanel.vue` rendering the 4×4 grid
- [ ] On cell click, show the suggested intervention + description
- [ ] Add intervention selector; show required override-reason textarea when intervention ≠ suggestion
- [ ] Save records classification (and override reason) to the case via the case service
- [ ] Test all 16 matrix selections and override-reason visibility/validation
