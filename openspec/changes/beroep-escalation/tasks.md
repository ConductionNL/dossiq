# Tasks: beroep-escalation

This is a **GENERATE-style** change: the existing `beroep-escalation` spec (5 requirements) is upgraded to a formal 8-requirement contract anchored in a change record. Tasks are spec-authoring and verification only. Any code gap discovered against the new contract becomes a follow-up issue rather than being fixed inside this change.

## Spec authoring

- [ ] **T01**: Author `specs/beroep-escalation/spec.md` as a `## ADDED Requirements` delta with eight requirements REQ-BE-1..8 covering: beroep entity & schema, filing deadline + dwingende marker on source bezwaar, court reference + chamber, file inspection request fulfillment (Awb 8:42), judgment outcome registration, cascade back into procest workflow, audit & immutability, authorization.

- [ ] **T02**: For each REQ-BE-* requirement, include at least one `#### Scenario:` block using Given/When/Then bullets. Verify with `grep -c "^#### Scenario:"` ≥ 8 against `specs/beroep-escalation/spec.md`.

## Cross-spec reconciliation

- [ ] **T03**: Cross-check the new REQ-BE-1..8 against the legacy 5 requirements in `openspec/specs/beroep-escalation/spec.md`: confirm the legacy "Beroep Case Type Pre-Seeded Configuration", "Beroep Status Types", "Escalation from Bezwaar to Beroep", "Court Proceedings Document Management" and "Hoger Beroep Awareness" concepts are either preserved or explicitly superseded in the new contract. Note in this task block which legacy requirements collapse into which new REQ-BE-* IDs.

- [ ] **T04**: Cross-check against `bezwaar-decision` REQ on `appealInformation` / `rechtsmiddelenclausule`: confirm REQ-BE-1 in the new spec consumes the same `contestedDecision` reference shape and that the 6-week filing window in REQ-BE-2 is consistent with `bezwaar-decision`'s `effectiveDate` semantics.

- [ ] **T05**: Cross-check against `bezwaar-lifecycle` deadline calculation: confirm the source-bezwaar "dwingende" marker described in REQ-BE-2 does NOT collide with the existing terminal statuses (`Afgehandeld`, `Niet-ontvankelijk`, `Ingetrokken`). If collision is found, file a follow-up issue rather than mutating `bezwaar-lifecycle`.

## Doc polish

- [ ] **T06**: Add a "Beroep cascade" subsection to `docs/ARCHITECTURE.md` (or the nearest existing architecture doc) summarizing the three cascade actions (`reopen_bezwaar`, `new_primary_decision`, `none`) from `design.md`. Cross-link to this change and to `bezwaar-decision`. If the architecture doc does not yet exist, file an issue rather than create it in this change.

- [ ] **T07**: Cross-reference check — open `openspec/changes/bezwaar-beroep-workflow/proposal.md` and add a "See also: `beroep-escalation`" line under its `Dependencies` section (skip if already present). Do not modify any archived changes.

## Strict validation

- [ ] **T08**: Run `openspec validate beroep-escalation --type change --strict` from the procest repo root and confirm exit code 0. Re-run `openspec list` and confirm the change appears with task progress.

- [ ] **T09**: Confirm `openspec change show beroep-escalation --json --deltas-only | jq '.deltaCount'` returns ≥ 8 (one delta per REQ-BE-1..8).

## Pre-commit verification

- [ ] **V01**: `openspec validate beroep-escalation --type change --strict` → exit code 0
- [ ] **V02**: `git diff --name-only origin/development...HEAD` lists ONLY files under `openspec/changes/beroep-escalation/` (and optionally the architecture-doc update from T06). No `lib/`, `src/`, `appinfo/`, `tests/`, or schema file changes.
- [ ] **V03**: Every REQ-BE-* in `specs/beroep-escalation/spec.md` contains at least one `#### Scenario:` subsection.
