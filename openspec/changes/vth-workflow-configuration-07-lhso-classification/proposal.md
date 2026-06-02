---
kind: code
depends_on:
  - vth-workflow-configuration-01-config-foundation
chain:
  - vth-workflow-configuration-01-config-foundation
  - vth-workflow-configuration-02-workflow-templates
  - vth-workflow-configuration-03-leges-engine
  - vth-workflow-configuration-04-leges-config-ui
  - vth-workflow-configuration-05-beschikking-generation
  - vth-workflow-configuration-06-mobile-inspection
  - vth-workflow-configuration-07-lhso-classification
  - vth-workflow-configuration-08-dso-integration
  - vth-workflow-configuration-09-admin-settings
  - vth-workflow-configuration-10-testing
  - vth-workflow-configuration-11-quality-docs
---

# VTH Workflow Configuration — 07 LHSO Classification

> Member 7 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 01 (which seeds the 16-cell LHSO matrix). Implements the LHSO lookup service/endpoints and the classification panel in the Handhavingszaak detail.

## Summary

Implement `LhsoLookupService` + `LhsoController` (matrix retrieval and gedrag×gevolgen lookup with input validation) and `LhsoClassificationPanel.vue` (4×4 matrix selector that surfaces the suggested intervention and requires an override reason when the chosen intervention differs from the suggestion). Traces to giant Tasks 12, 13.

## Scope

### In Scope

- `LhsoLookupService.lookup / getMatrix` reading the member-01 seed.
- `LhsoController` matrix and lookup endpoints with validation.
- `LhsoClassificationPanel.vue` with override-reason enforcement.

### Out of Scope

- LHSO matrix seed (member 01).
- Handhavingszaak template (member 01/02).

## Dependencies

- **vth-workflow-configuration-01-config-foundation**: provides the 16-cell LHSO matrix seed.

## Acceptance Criteria

1. GIVEN the seeded matrix, WHEN looking up gedrag=C, gevolgen=3, THEN the suggestion + description are returned; invalid inputs error.
2. GIVEN the classification panel, WHEN the chosen intervention differs from the suggestion, THEN an override reason becomes required before save.
