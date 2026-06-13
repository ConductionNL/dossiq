# Design: vth-workflow-configuration-11-quality-docs

## Architecture

`kind: code` member (ADR-032), close-out only — annotation, analysis, and documentation. No production behaviour changes. Enforces ADR-003 (3-layer), ADR-012 (deduplication), ADR-022 (no custom mappers; ObjectService), and spec-traceability via `@spec` tags.

## Work

- **Deduplication (ADR-012)**: grep for pre-existing leges/fee, document-generation, LHSO, and inspection-checklist logic; confirm `LhsoLookupService` is a thin wrapper over vth-module data and `MobileInspectionService` consumes the vth-module checklist; document all reuse in design "Reuse Analysis".
- **@spec tags**: file-level docblock + method-level `@spec` tags on every new public method linking to the relevant requirement (e.g. `@spec openspec/changes/vth-workflow-configuration/specs.md#req-vth-001-a`).
- **Docs**: VTH workflow template structure, leges algorithm (base + modifiers + verrekening), DSO event-driven pattern, mobile inspection flow.

## Security (ADR-005)

Audit-only; confirms no custom mappers bypass OpenRegister RBAC/audit and that no debug helpers ship (forbidden-patterns) before close-out.
