---
kind: config
depends_on: []
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

# VTH Workflow Configuration — 01 Config Foundation

> Member 1 of 11 in the `vth-workflow-configuration` ADR-032 chain. This is the `kind: config` declaration spec that all `kind: code` members depend on. It declares the VTH workflow template JSON files, the LHSO matrix seed, the VTH seed cases, and the repair steps that materialise them, plus the integration test that proves the materialised values are correct. It merges first; the subsequent code members consume these declarative artefacts.

## Summary

Declare the full declarative foundation for VTH (Vergunningverlening, Toezicht en Handhaving) workflow configuration in Procest. This member ships only declarative JSON (workflow templates, leges/LHSO seed, seed cases) plus the idempotent repair steps that load them and one integration test verifying the materialised data — no consumer code. Per ADR-031, business configuration is declared as schema/template data over imperative service code wherever possible; the code members (02+) read these artefacts.

This member is the expand half of an expand-then-contract migration: the templates and seed data land first, existing consumers ignore them, and the code members opt in.

## Scope

### In Scope (declarative only)

- **VTH workflow template JSON files** for Omgevingsvergunning, Toezichtzaak, Handhavingszaak (status definitions, role definitions, document-type requirements, transitions with guards) — the JSON authoring portion of giant Tasks 1, 2, 3.
- **LHSO matrix seed** (16 cells: Gedrag A–D × Gevolgen 1–4) and its repair step — giant Task 11.
- **VTH seed cases** (9 cases: 3 Omgevingsvergunning, 3 Toezichtzaak, 3 Handhavingszaak) and its repair step — giant Task 19.
- **Master VTH config repair step** that loads templates + leges rule sets + beschikking templates + inspection checklists + LHSO matrix + seed cases in dependency order, idempotently — giant Task 20.
- **Integration test** verifying the repair step materialises all templates, the 16 LHSO cells, and the 9 seed cases without duplicates on re-run.

### Out of Scope (deferred to chain members)

- `VTHWorkflowService` and template activation logic → member 02.
- Leges calculation engine → member 03; leges UI → member 04.
- Beschikking generation → member 05; mobile inspection → member 06.
- LHSO lookup service/UI → member 07; DSO integration → member 08.
- Admin settings UI → member 09; tests for services → member 10; docs/@spec/dedup → member 11.

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): VTH configures the generic workflow engine.
- **OpenRegister**: VTH schemas + objects stored via ObjectService (ADR-001/ADR-022).

## Acceptance Criteria

1. GIVEN a fresh install, WHEN the VTH config repair step runs, THEN all three workflow template JSON files are registered and the 16 LHSO matrix cells and 9 seed cases exist.
2. GIVEN the repair step has already run, WHEN it runs again, THEN no templates, LHSO cells, or seed cases are duplicated.
3. GIVEN the seed cases, WHEN queried by zaaktype and status, THEN each case is returnable as structured OpenRegister data.
