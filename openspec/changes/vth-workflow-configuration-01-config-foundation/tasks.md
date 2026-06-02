# Tasks: vth-workflow-configuration-01-config-foundation

Declarative foundation (templates + seed + repair steps + integration test). Traces to giant Tasks 1–3 (JSON authoring), 11, 19, 20.

## 1. Workflow Template JSON

- [ ] Author `lib/Settings/templates/vth-omgevingsvergunning-workflow.json` with statuses, roles, document-type requirements, transitions, guards
- [ ] Author `lib/Settings/templates/vth-toezichtzaak-workflow.json` with statuses, roles, properties (inspectionType, location, checklist ref), transitions
- [ ] Author `lib/Settings/templates/vth-handhavingszaak-workflow.json` with statuses, roles, LHSO properties, override-validation transitions
- [ ] Validate all three templates (OpenAPI 3.0 + `x-openregister`)

## 2. LHSO Matrix Seed

- [ ] Create `lib/Settings/lhso_matrix_seed.json` with 16 entries (Gedrag A–D × Gevolgen 1–4)
- [ ] Each entry includes gedrag, gevolgen, interventieStep, description
- [ ] Create `lib/RepairStep/LhsoMatrixRepairStep.php` to load the seed
- [ ] Make LHSO repair step idempotent (no duplicate cells on re-run)

## 3. Seed Cases

- [ ] Create `lib/Settings/vth-seed-cases.json` with 9 cases (3 Omgevingsvergunning, 3 Toezichtzaak, 3 Handhavingszaak) with realistic Dutch data and location references
- [ ] Create `lib/RepairStep/VthSeedDataRepairStep.php` loading cases via ObjectService
- [ ] Make seed-case repair step idempotent

## 4. Master Config Repair Step

- [ ] Create `lib/RepairStep/VthWorkflowConfigRepairStep.php` loading templates → leges rule sets → beschikking templates → inspection checklists → LHSO matrix → seed cases in order
- [ ] Add existence guards before each create for idempotency
- [ ] Register repair steps in `appinfo/info.xml`

## 5. Integration Test

- [ ] Add integration test asserting 3 templates registered, 16 LHSO cells present, 9 seed cases queryable
- [ ] Assert re-running the master repair step produces no duplicates
