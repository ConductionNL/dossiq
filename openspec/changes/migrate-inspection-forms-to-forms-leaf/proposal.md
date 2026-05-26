# Proposal: migrate-inspection-forms-to-forms-leaf

## Why

Procest ships bespoke form-style data collection: advice/consultation requests
(`advice-management`, `consultation-management` specs) and inspection checklists
(`inspection-checklists` spec — `ChecklistService`, `InspectionService`, `InspectionController`,
`InspectionChecklistPanel.vue`, `DocumentChecklist.vue`). Checklist items support free-form
question types and a "photo required" affordance with inline photo payloads.

OpenRegister provides a **forms** integration leaf and a **photos** integration leaf (ADR-019).
The forms leaf renders structured form definitions and captures responses against an OR object;
the photos leaf attaches and displays images on an OR object. Procest's hand-rolled checklist
question rendering, advice-request input forms, and inline photo handling duplicate these leaves —
an **ADR-022** violation:

- **Duplicate form-rendering** of question/answer collection that the forms leaf provides + tests.
- **Duplicate photo handling**: inline `photos[]` payloads in checklist items re-implement what
  the photos leaf (file-attached-to-object) provides.
- **No cross-app form/photo reuse**: in-app checklist UI can't be reused by other fleet apps.

**What stays — checklist domain rules.** The append-only/immutability enforcement
(`ChecklistRunImmutabilityListener`, REQ-IC-8), the photo-gate business rules (`fotoRequired:
altijd | bij_nee | nooit`), and the checklist-run lifecycle are **zaak-domain logic** that the
forms/photos leaves do not own. Per ADR-022, only the **form rendering + photo storage** are the
shared abstractions; the **inspection gating + immutability rules stay in-app**.

## What

This change migrates the form **rendering** and photo **storage** to the leaves while keeping the
inspection domain rules:

1. Advice/consultation request **input forms** and inspection **checklist item rendering** are
   rendered by the OR forms leaf on the case detail page, fed the form/checklist definition.
2. Inspection **photos** move from inline `photos[]` payloads to the OR photos leaf (files attached
   to the inspection-run / case object).
3. The checklist-run lifecycle, the photo-gate rules, and the append-only immutability listener are
   **kept** in `ChecklistService` / `ChecklistRunImmutabilityListener` — they validate the leaf's
   captured responses + attached photos rather than rendering them.
4. `InspectionChecklistPanel.vue` / `DocumentChecklist.vue` are reduced to: invoke the forms leaf,
   reference the photos leaf, and run the domain gates against the results.

## Capabilities

### New Capabilities

- `inspection-forms-via-forms-leaf`: Advice/consultation requests and inspection checklists are
  rendered and captured through OR's forms integration leaf; inspection photos are stored through
  OR's photos integration leaf. Procest retains the checklist domain gates and immutability rules.

### Modified Capabilities

- `inspection-checklists` (spec: `procest/openspec/specs/inspection-checklists/spec.md`) — checklist
  rendering + photo capture delegate to the forms/photos leaves; the immutability + photo-gate
  requirements are unchanged (domain logic stays).
- `advice-management` (spec: `procest/openspec/specs/advice-management/spec.md`) — the advice-request
  input form delegates to the forms leaf; the advice lifecycle/deadline tracking stays in-app.
- `consultation-management` (spec: `procest/openspec/specs/consultation-management/spec.md`) — the
  consultation input form delegates to the forms leaf.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the forms + photos leaves are consumed, not modified

## Out of Scope

- The forms/photos leaves' own implementation in OR.
- The checklist photo-gate rules (`fotoRequired`) and append-only immutability — they STAY in-app.
- The advice/consultation lifecycle + deadline tracking — domain logic, stays in-app.
- Backfill of existing inline checklist `photos[]` into the photos leaf store (sunset window;
  confirm in design.md).

## Success Criteria

- `openspec validate migrate-inspection-forms-to-forms-leaf --strict` exits 0.
- Advice/consultation/checklist forms render through the forms leaf; photos via the photos leaf.
- `ChecklistService` photo-gate + `ChecklistRunImmutabilityListener` immutability rules remain.
