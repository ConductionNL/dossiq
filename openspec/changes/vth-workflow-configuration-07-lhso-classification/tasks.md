# Tasks: vth-workflow-configuration-07-lhso-classification

LHSO lookup service + classification UI. Traces to giant Tasks 12, 13.

## 1. LhsoLookupService

- [~] Implement `lookup(gedrag, gevolgen)` → matrix cell — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getMatrix()` → all 16 cells as a 4×4 array — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `LhsoController` (GET /api/vth/lhso/matrix, GET /api/vth/lhso/lookup) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate inputs (gedrag A–D, gevolgen 1–4) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test lookup across all 16 combinations and invalid inputs — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Classification Panel

- [~] Create `LhsoClassificationPanel.vue` rendering the 4×4 grid — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On cell click, show the suggested intervention + description — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add intervention selector; show required override-reason textarea when intervention ≠ suggestion — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Save records classification (and override reason) to the case via the case service — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test all 16 matrix selections and override-reason visibility/validation — deferred to downstream cycle / fleet-wide adoption (handoff)
