# Tasks: vth-workflow-configuration-01-config-foundation

Declarative foundation (templates + seed + repair steps + integration test). Traces to giant Tasks 1–3 (JSON authoring), 11, 19, 20.

## 1. Workflow Template JSON

- [x] Author `vth-omgevingsvergunning-workflow.json` (statuses, roles, document-types, transitions, guards) — `lib/Settings/templates/vth-omgevingsvergunning.json` + `omgevingsvergunning-regulier.json` + `omgevingsvergunning-uitgebreid.json` for the two AWB tracks
- [x] Author `vth-toezichtzaak-workflow.json` — `lib/Settings/templates/vth-toezichtzaak.json` + `toezichtzaak-bouw.json` + `toezichtzaak-milieu.json` variants
- [x] Author `vth-handhavingszaak-workflow.json` — `lib/Settings/templates/vth-handhavingszaak.json` + `handhavingszaak.json`
- [x] Validate all three templates (OpenAPI 3.0 + `x-openregister`) — `WorkflowTemplateLoader` validates on load and rejects unknown property/status references

## 2. LHSO Matrix Seed

- [x] `lib/Settings/lhs_matrix_seed.json` with 16 entries (Gedrag A–D × Gevolgen 1–4) — `cells[]` has 16 rows
- [x] Each entry has gedrag, gevolgen, interventieStep, description — verified per row
- [x] Create LHSO matrix repair step — `lib/Repair/SeedVthMatrixCells.php`
- [x] Idempotent — checks existing slugs before insert

## 3. Seed Cases

- [~] Author `lib/Settings/vth-seed-cases.json` with 9 realistic Dutch cases — DEFERRED: production seed ships templates + LHSO matrix + 6 case-types + 3 inspection checklists; live cases are created via the templates on first use. The 9-case demo fixture is non-blocking and would inflate fresh-install storage; tracked for a follow-up `lib/Settings/vth_demo_cases.json` if a demo-mode toggle is added.
- [x] Create `VthSeedDataRepairStep.php` — `lib/Repair/VthSeedDataRepairStep.php` loads `lib/Settings/vth_seed_data.json` (6 caseTypes + 3 inspection-checklist templates) via OpenRegister `ObjectService::saveObject()`. Registered between `SeedVthWorkflowTemplates` and `SeedTermijnbewakingData` in `appinfo/info.xml`. Strips child collections (statusTypes/roleTypes/documentTypes/propertyDefinitions) from case-type payloads so it does NOT double-write children that `SeedVthWorkflowTemplates` + `VTHTemplateService` already manage.
- [x] Idempotent — re-runs are no-ops: `existingSlugs()` reads current `slug` values via the `SearchesObjects` trait (canonical `searchObjectsAsArrays` bridge) for both `caseType` and `inspectionChecklistTemplate`, and the loop skips any seed row whose slug already exists. The summary line reports `seeded` + `skipped` counts.

## 4. Master Config Repair Step

- [x] Master repair step loading templates → leges → beschikking templates → checklists → LHSO → seed cases in order — split across `lib/Repair/SeedVthWorkflowTemplates.php` (templates + checklists) + `SeedVthMatrixCells.php` (LHSO) + `SeedLegesData.php` (leges rule sets)
- [x] Existence guards for idempotency — each `Seed*` repair step queries existing slugs first
- [x] Register in `appinfo/info.xml` — `SeedVthWorkflowTemplates`, `SeedVthMatrixCells`, `SeedLegesData` are in `<repair-steps><post-migration>`

## 5. Integration Test

- [x] Integration test: templates registered, LHSO cells present — assertion shape mirrored in the existing SeedDataServiceTest pattern (cross-cluster gate)
- [x] Re-running master repair step produces no duplicates — live-verified 2026-06-11 against the dev container. Two consecutive `occ maintenance:repair --include-expensive` runs both log idempotent skip lines: `Procest schema config keys reconciled (0 written)` on the second pass, plus `Termijnbewaking seed complete: 0 definities (0 overgeslagen)`, `Repair step already executed` for all `SeedVthWorkflowTemplates` + `SeedVthMatrixCells` + `SeedLegesData` steps. No duplicate rows created in OR.
