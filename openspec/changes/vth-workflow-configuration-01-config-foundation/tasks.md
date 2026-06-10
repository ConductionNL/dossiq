# Tasks: vth-workflow-configuration-01-config-foundation

Declarative foundation (templates + seed + repair steps + integration test). Traces to giant Tasks 1–3 (JSON authoring), 11, 19, 20.

> **Build status (hydra audit 2026-06-10).** Dev already has:
> `lib/Settings/templates/vth-omgevingsvergunning.json`,
> `vth-toezichtzaak.json`, `vth-handhavingszaak.json`,
> `lib/Settings/lhs_matrix_seed.json`, and the three repair-step
> classes (`SeedLhsMatrix`, `SeedVthMatrixCells`,
> `SeedVthWorkflowTemplates`). This change additionally **wires those
> repair steps into `appinfo/info.xml`** (they were authored but never
> registered). Seed-cases + dedicated integration test remain open.

## 1. Workflow Template JSON

- [x] Author `lib/Settings/templates/vth-omgevingsvergunning.json` with statuses, roles, document-type requirements, transitions, guards (already on dev as `vth-omgevingsvergunning.json`)
- [x] Author `lib/Settings/templates/vth-toezichtzaak.json` with statuses, roles, properties (inspectionType, location, checklist ref), transitions (already on dev)
- [x] Author `lib/Settings/templates/vth-handhavingszaak.json` with statuses, roles, LHSO properties, override-validation transitions (already on dev)
- [~] Validate all three templates (OpenAPI 3.0 + `x-openregister`) — deferred to opsx-verify against a live register loader

## 2. LHSO Matrix Seed

- [x] Create `lib/Settings/lhs_matrix_seed.json` (named `lhs_matrix_seed.json`, not `lhso_matrix_seed.json`) with 16 entries (Gedrag A–D × Gevolgen 1–4)
- [x] Each entry includes gedrag, gevolgen, interventieStep, description
- [x] Create `lib/Repair/SeedLhsMatrix.php` to load the seed (path `lib/Repair/`, not `lib/RepairStep/`)
- [x] Make LHSO repair step idempotent (no duplicate cells on re-run)

## 3. Seed Cases

- [ ] Create `lib/Settings/vth-seed-cases.json` with 9 cases (3 Omgevingsvergunning, 3 Toezichtzaak, 3 Handhavingszaak) with realistic Dutch data and location references
- [ ] Create `lib/Repair/VthSeedDataRepairStep.php` loading cases via ObjectService
- [ ] Make seed-case repair step idempotent

## 4. Master Config Repair Step

- [x] `lib/Repair/SeedVthMatrixCells.php` + `lib/Repair/SeedVthWorkflowTemplates.php` load templates and matrix cells (the spec's monolithic VthWorkflowConfigRepairStep is realised as two focused repair steps — a cleaner split)
- [x] Add existence guards before each create for idempotency
- [x] Register repair steps in `appinfo/info.xml` (this change: SeedLhsMatrix + SeedVthMatrixCells + SeedVthWorkflowTemplates added to post-migration block; version bumped to 0.2.11 for cache-bust)

## 5. Integration Test

- [~] Add integration test asserting 3 templates registered, 16 LHSO cells present, 9 seed cases queryable — deferred (needs live OR instance; ConductionNL/.github testing harness)
- [~] Assert re-running the master repair step produces no duplicates — deferred (live OR)
