# Proposal: Bezwaar Decision (Beslissing op Bezwaar)

## Summary

Formalize the `bezwaar-decision` capability. The current canonical spec at `openspec/specs/bezwaar-decision/spec.md` was committed without a change record (alongside `bezwaar-beroep-workflow`, commit `6c45b8b`) and covers only 3 requirements. This change expands it to 10 requirements covering the full Awb Art. 7:11 / 7:12 beslissing op bezwaar lifecycle — sister spec to `bezwaar-lifecycle`, complementary to `bezwaar-hearing` and `bezwaar-advisory-committee`.

## Why

The existing spec omits: outcome-specific mandatory fields, a structured appeals notice, proceskostenvergoeding rules (Art. 7:15), the Art. 7:10 deadline machinery (6 + 6 + 4 weeks), publication + notification flow, and template-driven document generation. The current `dispositionType` enum collapses three gegrond variants — this change replaces it with the canonical Awb 7:11 five-value enum (`niet_ontvankelijk`, `ongegrond`, `gegrond_handhaven`, `gegrond_herroepen`, `gegrond_wijzigen`).

## What Changes

- Delta-format spec with ten REQ-BD-* requirements and 28 G/W/T scenarios — expands from 3 to 10
- Outcome matrix, entity property contract, deadline rules in `design.md`
- Verification-only tasks T01–T10 plus pre-commit gates V01–V04
- NO code changes — partial implementation already live; gaps become follow-up issues
- On archive, the delta replaces the partial spec at `openspec/specs/bezwaar-decision/spec.md`

## Affected Projects

- [ ] Project: `procest` — Formalize `bezwaar-decision` with proposal, design, tasks, and a delta spec REQ-BD-1..10. NO CODE.

## Scope

### In Scope (V1, verification only)

Decision entity + canonical Awb 7:11 enum (BD-1, BD-2); per-outcome mandatory fields (BD-3); heroverweging + motiveringsplicht + reformatio in peius (BD-4); advisory committee linkage + deviation rationale Art. 7:13 lid 7 (BD-5); structured appeal notice (BD-6); proceskostenvergoeding Art. 7:15 (BD-7); deadline Art. 7:10 (BD-8); template-driven document generation (BD-9); publication + notification flow incl. MijnOverheid Berichtenbox (BD-10).

### Out of Scope

Beroep escalation (separate `beroep-escalation`); voorlopige voorziening; dwangsom bij niet tijdig beslissen Art. 4:17; mediation track.

## Approach

GENERATE-style: capture the full contract; verification tasks file follow-up issues for any code gap rather than fix in-line.

## Cross-Project Dependencies

- **bezwaar-lifecycle**: `afhandelDeadline` calculation referenced by REQ-BD-8
- **bezwaar-hearing**: presence triggers +4 weeks extension
- **bezwaar-advisory-committee**: advisory report and deviation rationale
- **beroep-escalation**: consumes rechtsmiddelenclausule output
- **OpenRegister**: object storage, schema validation, audit trail
