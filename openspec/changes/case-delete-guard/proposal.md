---
kind: code
depends_on: []
---

# Proposal: case-delete-guard

Competitor gap register, row 2.17 "Delete case with guard"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

A case can be deleted while a statutory term runs on it. Two guards exist
and both are narrow: `lib/Listener/BezwaarLegalHoldListener.php` holds a
case during bezwaar or beroep, and `StateMachineService::assertDeletable()`
refuses a signed beschikking. `src/modals/DeelzaakDeleteWarningModal.vue`
warns about sub-cases and then lets you continue. Nothing refuses the
delete of a case with an open `deadlineInstance`, and nothing tells you
which rule blocked it.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_case_management_http/routes/routes.py` (case/can_delete)
(`_round2/compare/M1-functionality.md`).

## What changes

- `lib/Listener/CaseDeleteGuardListener.php` on OpenRegister's
  `ObjectDeletingEvent` for schema `case`, in the shape of
  `BezwaarLegalHoldListener`. It refuses when: a `deadlineInstance` of the
  case is `lopend`, `verlengd` or `paused`; a case names it as `parentCase`;
  a legal hold is active; the case is closed and inside its retention period.
- The refusal names every rule that blocks, in one message, with a status
  the caller can read (ADR-105 maps it to 409).
- The Deelzaak warning modal goes: the guard answers instead.

## Ownership

dossiq builds the listener and its rules; the reasons are statutory (Awb
terms, Archiefwet retention). It consumes OpenRegister's pre-persist event
and its legal hold, both shipped.

## ADRs

- Company ADR-078: a `*ing` pre-event is synchronous and may veto.
- Company ADR-105 and ADR-050: the refusal is a typed exception the
  controller translates to `{message, error}` with a 4xx status.
- Company ADR-031: retention itself stays OpenRegister's; the guard only
  reads it.

## Capabilities

- Modified: `case-management`: a case is deleted only when nothing holds it.

## Impact

New `lib/Listener/CaseDeleteGuardListener.php`, registered in
`ObjectListenerRegistrar` for schema `case` only; `src/modals/
DeelzaakDeleteWarningModal.vue` removed; unit tests per rule; one e2e spec.
