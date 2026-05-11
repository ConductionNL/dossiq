# Proposal: enforcement-lhs

## Summary

Extend Procest with a fully configurable, decision-driven implementation of the **Landelijke Handhavingsstrategie Omgevingsrecht (LHS)** — the Dutch national matrix that maps offence severity (ernst) × offender intentionality (gedrag) → the proportional enforcement intervention. The existing `enforcement-lhs` capability spec describes a static 4×4 matrix and a wizard; this change generalises that into a **3-dimensional matrix** (ernst × gedrag × actor-type) with explicit override-with-justification, a sanction-recommendation service, and a first-class audit-trail integration so every recommendation, override and inspector decision becomes traceable evidence for the bezwaar/beroep phase.

## Why

VTH tenders consistently require the LHS as a hard "moeten-eis" (must-have). The current 4×4 form treats every offender the same regardless of whether they are a private citizen, a regulated company, or a repeat offender — but real omgevingsdiensten apply a softer scale for citizens and a sharper scale for industry. Inspectors today work around this in spreadsheets, losing the auditability that the LHS exists to provide. By adding the actor-type axis, formalising the recommendation service, and wiring overrides into the case timeline, Procest covers the LHS use-case end-to-end and produces the legal-quality evidence trail that bezwaar committees require.

## Problem

- The existing spec assumes a single 4×4 matrix; LHS in practice varies by actor type (burger / bedrijf / overheid / recidivist)
- There is no dedicated `SanctionRecommendationService` — recommendation logic is currently entangled with the wizard UI, making it untestable and unusable from other entry points (mobile inspection, complaint intake)
- Overrides ("inspector chose a milder/harsher sanction than the matrix proposed") are not first-class objects with mandatory justification, so the audit trail records *that* the case advanced but not *why* the matrix was overruled
- Matrix lookups are not surfaced on the case timeline alongside the inspection rapport, so reviewers cannot reconstruct the decision chain

## What Changes

- Adds 8 new requirements (REQ-LHS-1..8) extending the existing `enforcement-lhs` capability
- Introduces an `lhsMatrix` schema with a 3-D cell array (ernst × gedrag × actorType) and version/active flags
- Introduces a `sanctionRecommendation` schema capturing input axes, recommended intervention, applied intervention, override flag and justification
- Specifies a backend `SanctionRecommendationService` consumed by the wizard, the mobile inspection flow, and the complaint-intake flow
- Integrates recommendation + override events into the existing `vth-module` enforcement workflow audit trail
- NO code in this change — this is a spec-only generation step; implementation follows in a separate apply change

## Affected Projects

- [x] Project: `procest` — Spec-only addition under `openspec/changes/enforcement-lhs/`; live spec lives under `openspec/specs/enforcement-lhs/`

## Scope

### In Scope (V1)

- 3-D LHS matrix schema with actor-type axis
- Seed import of the national LHS reference matrix
- `SanctionRecommendationService` (deterministic lookup + override capture)
- Inspector decision UI: matrix display, axis selectors, override field with mandatory justification
- Audit-trail integration with the existing `vth-module` enforcement workflow

### Out of Scope

- AI-suggested ernst/gedrag classification — separate `ai-assisted-processing` change
- Predictive recidivism scoring — Enterprise tier, not in V1
- Cross-municipality matrix federation — future work
- Migration of existing `handhavingsactie` records to the new schema (separate data-migration change)

## Cross-Project Dependencies

- **vth-module** (existing spec): provides the enforcement workflow that consumes the recommendation
- **inspection-checklists** (existing spec): supplies the constatering that triggers the LHS lookup
- **mobiel-inspectie** (in flight): second entry-point for the LHS recommendation service
- **OpenRegister**: stores `lhsMatrix` and `sanctionRecommendation` objects and provides the audit trail
