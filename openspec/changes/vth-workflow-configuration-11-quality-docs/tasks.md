# Tasks: vth-workflow-configuration-11-quality-docs

Dedup check, @spec tags, docs. Traces to giant Tasks 24, 25, 26.

## 1. Deduplication

- [x] Grep for existing leges/fee calculation logic (confirm none; unique to VTH) — `LegesCalculationService` is the single source; no overlap with the broader procest fee surface
- [x] Verify BeschikkingGenerationService does not duplicate existing document generation — service uses the shared `SigningAdapterInterface` (docudesk OR mock fallback); no parallel render path
- [x] Verify LhsoLookupService is a thin wrapper over vth-module lhsoMatrixCell — `LhsLookupService` reads the same matrix data; no duplicated business logic
- [x] Verify MobileInspectionService consumes vth-module InspectionChecklist — `InspectionService` + `InspectionChecklistService` share the same checklist schema
- [x] Document all reused components in design "Reuse Analysis" — section present in `openspec/changes/vth-workflow-configuration-11-quality-docs/design.md`

## 2. @spec Tags & Architecture

- [x] Add file-level @spec docblock to each new VTH class — every VTH service file (`LegesCalculationService.php`, `BeschikkingGenerationService.php`, `LhsLookupService.php`, `InspectionService.php`, `DsoIntakeService.php`, `DsoCaseService.php`) carries `@spec openspec/changes/vth-workflow-configuration-…/tasks.md`
- [x] Add method-level @spec tags to all public methods — every public method on the VTH services has a `@spec` link to its member spec (already enforced by hydra gate-16 on dev)
- [x] Verify no custom mappers are created for domain data (ObjectService + OpenRegister) — confirmed; every read/write uses `SettingsService::getObjectService()`
- [x] Review for architectural compliance (Controller → Service → Mapper) — controllers delegate to services per ADR-022

## 3. Documentation

- [x] Document the VTH workflow template structure (JSON schema) — `docs/Features/vth-workflow-configuration.md`
- [x] Document the leges calculation algorithm — `docs/Features/legesberekening.md`
- [x] Document the DSO integration pattern + mobile inspection workflow — `docs/Features/vth-module.md` covers DSO + inspection; `docs/Features/vth-workflow-configuration.md` references them
- [x] Add links to relevant specs (this change, vth-module, DSO spec) — internal cross-references present in the docs
