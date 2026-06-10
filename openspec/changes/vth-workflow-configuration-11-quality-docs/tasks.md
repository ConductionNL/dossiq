# Tasks: vth-workflow-configuration-11-quality-docs


> **Build status (hydra audit 2026-06-10).** Dedup check, @spec tags, docs. Code already carries @spec tags where present; remaining @spec sweep + chain README + admin/user docs are the open work.
Dedup check, @spec tags, docs. Traces to giant Tasks 24, 25, 26.

## 1. Deduplication

- [x] Grep for existing leges/fee calculation logic — `LegesCaseCalculationService` is unique to VTH/Procest; no shillinq/openconnector overlap (`docs/Technical/leges-heffingen.md` documents the design)
- [x] Verify BeschikkingGenerationService does not duplicate existing document generation — uses pluggable `TemplateEngineAdapterInterface` (Docudesk-or-mock) and `BeschikkingService` for sign/archive; verified by audit
- [x] Verify LhsLookupService is a thin wrapper over vth-module `lhsMatrix` (expected reuse) — verified on dev (`LhsLookupService` reads from `lhs_matrix` register objects, no own data store)
- [x] Verify InspectionChecklistService consumes vth-module InspectionChecklist (expected reuse) — verified on dev (`InspectionChecklistService` writes `inspectionChecklistTemplate` + `inspectionChecklistRun` schemas)
- [~] Document all reused components in design "Reuse Analysis" — partial; `docs/Technical/leges-heffingen.md` documents the leges chain; full reuse-analysis doc deferred

## 2. @spec Tags & Architecture

- [x] Add file-level @spec docblock to each new VTH class — repair steps and listener added in this batch carry @spec tags (`VthSeedDataRepairStep`, `StatusChangeDispatcherListener`)
- [~] Add method-level @spec tags to all public methods (link to REQ-* sections) — partial sweep already in place; full coverage tracked via gate-16 (`run-hydra-gates.sh`)
- [x] Verify no custom mappers are created for domain data (ObjectService + OpenRegister) — verified on dev (all VTH services route through `SettingsService::getObjectService()`)
- [x] Review for architectural compliance (Controller → Service → Mapper) — verified on dev; controllers stay thin and delegate to services

## 3. Documentation

- [x] Document the VTH workflow template structure — `lib/Settings/templates/vth-*.json` carry inline docstrings + `docs/` references
- [x] Document the leges calculation algorithm (base + modifiers + verrekening) — `docs/Technical/leges-heffingen.md` shipped on dev (em-dashes swept in this batch)
- [~] Document the DSO integration pattern (event-driven, status pushback) and mobile inspection workflow — partial; `dso-omgevingsloket` proposal/spec.md cover the design, dedicated user-facing docs page deferred
- [x] Add links to relevant specs — see `openspec/changes/dso-omgevingsloket/spec.md` and the VTH chain proposals
