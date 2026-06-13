# Design: vth-workflow-configuration-07-lhso-classification

## Architecture

`kind: code` member (ADR-032). `LhsoLookupService` is a thin read-only wrapper over the declarative LHSO matrix seeded by member 01 (ADR-031). Controller → Service (ADR-003); matrix cells read via ObjectService (ADR-001/ADR-022). Vue panel per ADR-004.

## Service Layout

- `LhsoLookupService.lookup(gedrag, gevolgen)` → matrix cell (validated gedrag A–D, gevolgen 1–4).
- `getMatrix()` → all 16 cells as a 4×4 array.
- `LhsoController`: GET /api/vth/lhso/matrix, GET /api/vth/lhso/lookup.

## UI

`LhsoClassificationPanel.vue` renders the 4×4 grid in the Handhavingszaak detail; selecting a cell shows the suggested intervention; an intervention selector with an override-reason textarea that is required only when intervention ≠ suggestion.

## Security (ADR-005)

Lookup endpoints are authenticated reads of reference data. Recording a classification onto a case goes through the case service with a per-object guard; override reason is validated server-side when required.
