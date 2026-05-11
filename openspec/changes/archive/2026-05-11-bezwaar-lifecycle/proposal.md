# Proposal: Bezwaar Lifecycle

## Summary

Formalize the `bezwaar-lifecycle` capability as a first-class OpenSpec change so the existing canonical spec at `openspec/specs/bezwaar-lifecycle/spec.md` (5 requirements, no traceable change history) is replaced by a fully delta-formatted change with ten REQ-BL-* requirements, an explicit state machine, statutory-deadline rules, and integration hooks into the three sister capabilities — `bezwaar-advisory-committee`, `bezwaar-hearing`, and `bezwaar-decision`. Bezwaar (formal objection under the Algemene wet bestuursrecht, Awb) is the most legally formal case type in Procest: timelines are statutory (Awb 7:10 — 6 weeks initial, 6 weeks verdaging), transitions are gated by ontvankelijkheidstoets and hoorrecht, and the failure mode is automatic dwangsom liability under the Wet dwangsom (Awb 4:17). The capability deserves a single spec that owns the state machine, deadline scheduler, and audit posture, with the three sister capabilities composing onto it.

## Why

The existing canonical spec was committed without a change record, so traceability between spec and implementation is broken; new dependent work (bezwaar-beroep-workflow, document-zaakdossier, archiving) needs a referenceable contract. The three sister specs (advisory-committee, hearing, decision) already exist but they all reference "the bezwaar case status machine" without a single source of truth. Formalizing it now — with the legal deadline rules, ingebrekestelling/dwangsom hooks, and Awb article cross-references in one place — keeps OpenSpec coverage honest, lets reviewers validate against an explicit state diagram, and gives tender responses a traceable spec ID for "AWB-compliant bezwaarafhandeling".

## What Changes

- Adds `bezwaar-lifecycle` change folder under `openspec/changes/` with `proposal.md`, `design.md`, `tasks.md`, and `specs/bezwaar-lifecycle/spec.md`
- Authors ten REQ-BL-* requirements (delta-format `## ADDED Requirements`) covering case type seeding, status state machine, role types, deadline calculation, ontvankelijkheidstoets, verdaging/opschorting, ingebrekestelling/dwangsom, sister-capability integration, audit trail, and Vue lifecycle view
- Adds a one-page state-machine diagram + deadline rules + escalation table to `design.md`
- Tasks (T01–T10) cover schema verification, state-machine guards, deadline scheduler, controller endpoints, Vue lifecycle view, audit-trail integration, and strict validation
- Does NOT introduce any code changes — implementation lives across `lib/Settings/procest_register.json`, the existing register seeds, and frontend components. Any gap surfaced during verification is filed as a follow-up issue
- On archive, the spec under `specs/bezwaar-lifecycle/` replaces the unowned spec currently at `openspec/specs/bezwaar-lifecycle/spec.md`

## Affected Projects

- [ ] Project: `procest` — Formalize the `bezwaar-lifecycle` capability with proposal, design, tasks, and a delta-format spec. NO CODE: implementation already exists in the procest register schemas and seed data.

## Scope

### In Scope (V1, verification only)

- **Bezwaar case type seeding** (REQ-BL-1): pre-seeded Awb-compliant zaaktype with 6-week processingDeadline + 6-week extensionPeriod
- **Status state machine** (REQ-BL-2): 8 ordered statuses + 2 terminal statuses + permitted transitions
- **Role types** (REQ-BL-3): 7 pre-seeded role types covering all Awb participants
- **Legal deadline calculation** (REQ-BL-4): ontvangstbevestiging, afhandelDeadline, verdaging, opschorting
- **Ontvankelijkheidstoets** (REQ-BL-5): timeliness, belanghebbendheid, vormvereisten checks
- **Verdaging and opschorting** (REQ-BL-6): extension and suspension rules per Awb art. 7:10
- **Ingebrekestelling and dwangsom** (REQ-BL-7): Wet dwangsom (Awb 4:17) deadline + automatic liability tracking
- **Sister-capability integration** (REQ-BL-8): hooks into hearing, advisory-committee, and decision capabilities
- **Audit trail and immutability** (REQ-BL-9): every status transition logged with actor, reason, Awb reference
- **Vue lifecycle view** (REQ-BL-10): status badges, deadline countdown, transition guard messages

### Out of Scope

- Hearing session management — separate `bezwaar-hearing` capability
- Advisory committee reports — separate `bezwaar-advisory-committee` capability
- Decision recording — separate `bezwaar-decision` capability
- Beroep (appeal) escalation — separate `beroep-escalation` capability

## Approach

This is a **GENERATE-style** change: the schemas and seed data already exist. The change captures the contract in OpenSpec form so the capability becomes traceable, testable, and referenceable by sister capabilities. Verification tasks confirm the schemas, transitions, and deadline rules match the spec.

## Cross-Project Dependencies

- **procest-case-management**: provides the underlying case object and status machine primitives
- **bezwaar-advisory-committee**: composes onto the lifecycle for advisory-track cases
- **bezwaar-hearing**: composes onto the lifecycle for hearing scheduling and waiver
- **bezwaar-decision**: composes onto the lifecycle for beslissing op bezwaar
- **OpenRegister**: object storage, computed fields (`x-openregister-calculations` per ADR-022), and audit trail
