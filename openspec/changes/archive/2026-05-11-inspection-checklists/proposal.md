# Proposal: Inspection Checklists

## Summary

Introduce a full `inspection-checklists` capability in Procest: configurable templates plus per-inspection runtime instances with offline-capable completion. Replaces the thin 4-requirement placeholder at `openspec/specs/inspection-checklists/spec.md` with a REQ-IC-1..8 contract covering template entity, run entity, validation, evidence linking, offline sync, pass/fail aggregation, conditional follow-up, and versioning. Sister to `mobiel-inspectie` (PWA shell); this spec owns the data model and behaviour.

## Why

VTH tenders require versioned, auditable checklists (Archiefwet + Omgevingswet). The current main spec has 4 high-level requirements — no run schema, no offline sync, no conditional logic, no evidence-linking contract. Downstream changes (`mobiel-inspectie` V3, `enforcement-lhs`, `document-zaakdossier`) need a stable reference.

## What Changes

- Adds 8 REQ-IC-* requirements with Given/When/Then scenarios under `specs/inspection-checklists/spec.md`
- Adds `design.md` covering entities, lifecycle, offline-sync, evidence linking, aggregation
- Adds tasks T01–T07 plus V01–V04 verification gates
- On archive, the delta merges into `openspec/specs/inspection-checklists/spec.md`

## Affected Projects

- [x] Project: `procest` — template + run schemas, ChecklistService, Vue settings editor, mobile run UI, offline sync queue, evidence upload

## Scope

### In Scope (V2 / V3)

- REQ-IC-1: Template entity (sections, items, response types, photo gates, versioning)
- REQ-IC-2: Run entity (answers, evidence, timestamps, derived result)
- REQ-IC-3: Per-item validation rules
- REQ-IC-4: Evidence linking (photos + audio + notes per item, immutable post-submit)
- REQ-IC-5: Offline completion with IndexedDB queue + conflict resolution
- REQ-IC-6: Pass/fail aggregation (conform / niet_conform / deels_conform)
- REQ-IC-7: Conditional follow-up actions (herinspectie, handhavingstaak, documentVerzoek)
- REQ-IC-8: Template versioning + run append-only post-submit

### Out of Scope

- PWA shell, camera, GPS — `mobiel-inspectie`
- PDF rendering — Docudesk
- Signature capture — `mobiel-inspectie` REQ-MOB-09
- Handhaving lifecycle — `enforcement-lhs`

## Cross-Project Dependencies

- **mobiel-inspectie** — PWA shell renders runs
- **enforcement-lhs** — consumes REQ-IC-7 follow-ups
- **document-zaakdossier** — packages run evidence
- **OpenRegister** — storage, validation, audit
- **Nextcloud Files** — evidence storage
