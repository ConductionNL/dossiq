# Tasks: vth-workflow-configuration-11-quality-docs

Dedup check, @spec tags, docs. Traces to giant Tasks 24, 25, 26.

## 1. Deduplication

- [ ] Grep for existing leges/fee calculation logic (confirm none; unique to VTH)
- [ ] Verify BeschikkingGenerationService does not duplicate existing document generation
- [ ] Verify LhsoLookupService is a thin wrapper over vth-module lhsoMatrixCell (expected reuse)
- [ ] Verify MobileInspectionService consumes vth-module InspectionChecklist (expected reuse)
- [ ] Document all reused components in design "Reuse Analysis"

## 2. @spec Tags & Architecture

- [ ] Add file-level @spec docblock to each new VTH class
- [ ] Add method-level @spec tags to all public methods (link to REQ-* sections)
- [ ] Verify no custom mappers are created for domain data (ObjectService + OpenRegister)
- [ ] Review for architectural compliance (Controller → Service → Mapper)

## 3. Documentation

- [ ] Document the VTH workflow template structure (JSON schema)
- [ ] Document the leges calculation algorithm (base + modifiers + verrekening)
- [ ] Document the DSO integration pattern (event-driven, status pushback) and mobile inspection workflow
- [ ] Add links to relevant specs (this change, vth-module, DSO spec)
