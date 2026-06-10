# Tasks: vth-workflow-configuration-11-quality-docs

Dedup check, @spec tags, docs. Traces to giant Tasks 24, 25, 26.

## 1. Deduplication

- [~] Grep for existing leges/fee calculation logic (confirm none; unique to VTH) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify BeschikkingGenerationService does not duplicate existing document generation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify LhsoLookupService is a thin wrapper over vth-module lhsoMatrixCell (expected reuse) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify MobileInspectionService consumes vth-module InspectionChecklist (expected reuse) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Document all reused components in design "Reuse Analysis" — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. @spec Tags & Architecture

- [~] Add file-level @spec docblock to each new VTH class — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add method-level @spec tags to all public methods (link to REQ-* sections) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify no custom mappers are created for domain data (ObjectService + OpenRegister) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Review for architectural compliance (Controller → Service → Mapper) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Documentation

- [~] Document the VTH workflow template structure (JSON schema) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Document the leges calculation algorithm (base + modifiers + verrekening) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Document the DSO integration pattern (event-driven, status pushback) and mobile inspection workflow — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add links to relevant specs (this change, vth-module, DSO spec) — deferred to downstream cycle / fleet-wide adoption (handoff)
