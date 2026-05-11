# Proposal: Voorstel Management

## Summary

Formalize the `voorstel-management` capability as a first-class OpenSpec change so that the existing implementation (delivered via PRs #331 / #332 — `parafeerroute-engine` and `parafering-actions`) has explicit, validatable specification coverage. Voorstellen (council/B&W proposals) are the central unit of work in Procest's parafering domain — every collegeadvies, raadsvoorstel and DT-advies flows through a voorstel lifecycle — yet the capability currently lives only implicitly across two adjacent change specs. This change introduces an explicit `voorstel-management` capability spec that documents the voorstel entity, its lifecycle, and the operations that act on it, with the live code as the reference implementation.

## Why

Voorstellen are central to Procest — every parafering route, every council decision, every B&W signing flow is anchored to a voorstel record. The implementation already exists and is exercised in production via PRs #331 and #332, but it has never been described in a single canonical capability spec. New work (besluitvorming-workflow, document-zaakdossier, archive integration) needs a stable spec to reference; reviewers need an explicit lifecycle diagram and a property contract to validate against; tender responses need a traceable spec ID. Formalizing the capability now — before more changes layer on top — keeps the OpenSpec coverage honest and makes follow-up changes easy to scope.

## Problem

The `voorstel` schema, status machine, and CRUD/lifecycle operations are present in production code (`procest_register.json`, `ParafeerRouteService`, `ParafeerActieService`, `appinfo/routes.php`) and partially described in adjacent change specs (`parafeerroute-engine`, `parafering-actions`, `bw-parafering`). However:

- No single capability spec describes the voorstel entity, its required properties, or its 8-state lifecycle as the canonical contract
- The `openspec/specs/voorstel-management/spec.md` file already exists but was committed without a corresponding change record, so traceability between spec and implementation is broken
- Newer changes that touch voorstellen (besluitvorming-workflow, bw-parafering, document-zaakdossier) cannot cleanly reference the capability because it has no formal change history

## What Changes

- Adds the `voorstel-management` capability spec under this change's `specs/` directory (delta-format `## ADDED Requirements`, eight REQ-VM-* requirements with Given/When/Then scenarios)
- Adds a one-page lifecycle diagram + property contract to `design.md` for review and future onboarding
- Adds verification-only tasks (T01–T09) covering schema, lifecycle, route binding, multi-voorstel-per-case, and `openspec validate --strict` gate
- Does NOT introduce any code changes — implementation is already live via PRs #331 (parafeerroute-engine) and #332 (parafering-actions)
- On archive, the spec under `specs/voorstel-management/` replaces the unowned spec currently at `openspec/specs/voorstel-management/spec.md`

## Affected Projects

- [ ] Project: `procest` — Formalize the existing `voorstel-management` capability as an OpenSpec change with proposal, design, tasks, and a delta-format spec. NO CODE changes: implementation already exists.

## Scope

### In Scope (V1, verification only)

- **Voorstel entity formalization** (REQ-VM-1): canonical property list, schema reference, required fields
- **Voorstel lifecycle** (REQ-VM-2): the eight statuses (`concept`, `in_parafering`, `ter_accordering`, `geaccordeerd`, `aangeboden`, `besloten`, `gearchiveerd`, `teruggestuurd`) and allowed transitions
- **Create voorstel from case** (REQ-VM-3): voorstel inherits case context (onderwerp, afdeling, portefeuillehouder)
- **Voorstel-parafeerroute binding** (REQ-VM-4): selection of route by `voorstelType`, snapshot at submission
- **Voorstel detail view** (REQ-VM-5): metadata header, document preview, parafering progress, action history
- **Multiple voorstellen per case** (REQ-VM-6): independent status per voorstel on the same case
- **Voorstel audit & immutability** (REQ-VM-7): OpenRegister audit trail, no destructive edits after submission
- **Voorstel security & authorization** (REQ-VM-8): steller / actor / case-participant access boundaries

### Out of Scope

- Bestuurlijk (College B&W) treatment — handled by external RIS (iBabs/NotuBiz) per `bw-parafering` spec
- Besluit creation — separate `besluitvorming-workflow` change
- Parallel parafering steps — V2, tracked in `parafeerroute-engine` V2 backlog
- Voorstel templates / boilerplate — separate `template-library` change

## Approach

This is a **GENERATE-style** change: the implementation already exists and is the reference. The change captures it in OpenSpec form so the capability becomes traceable, testable, and referenceable.

1. **Proposal + design + tasks** — author this change directory describing what is being formalized and how to verify it
2. **Delta spec** — replace the unowned `openspec/specs/voorstel-management/spec.md` with a delta-format `## ADDED Requirements` spec under this change's `specs/voorstel-management/spec.md`; on archive it will merge back as the canonical capability spec
3. **Verification tasks** — confirm schema, lifecycle, routes, and UI components match the spec; no source-code modifications are introduced by this change

## Cross-Project Dependencies

- **parafeerroute-engine** (already merged): provides the parafeerroute schema and routing engine that voorstellen depend on
- **parafering-actions** (already merged): provides the actor-facing action recording on top of voorstellen
- **OpenRegister**: object storage and audit trail for voorstel entities
- **bw-parafering** (archived): historical context for the broader 10-step ambtelijk + bestuurlijk flow
