---
kind: config
depends_on: []
---

# Proposal: case-claim-action

Competitor gap register, row 2.4 "Assign case to a user, claim, unassign"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S. Re-read on 2026-09-13: the
gap stays; `#CaseDetail/headerActions` holds eleven actions and none is a
claim, and `grep -n -i claim src/manifest.json` hits only notes.

## Why

You take a case from the queue by opening Edit and picking yourself. The
Queue page lists unclaimed cases and Reassign is a bulk action on `#Cases`,
but a one-click claim, the thing a handler does forty times a week, does not
exist. Tasks have Pick up; cases do not.

The best competitor in the register: xxllnc Zaken,
`frontend-mono/packages/common/src/components/dialogs/CaseActionDialog/CaseActionDialog.locale.ts`
(`_round2/compare/M1-functionality.md`).

## What changes

- Header action Claim on `#CaseDetail`, visible when `assignee` is empty:
  writes `assignee` = the signed-in user.
- Header action Release on `#CaseDetail`, visible when you are the assignee:
  clears `assignee`.
- Row action Claim on `#Queue` and on the Unclaimed lens of `#Cases`.
- Both write through the object store and record nothing of their own: the
  audit trail already carries the field change.

## Ownership

dossiq builds the two handlers and the three placements. It consumes
OpenRegister's object write and audit trail, and nextcloud-vue's `handler`
action type, all shipped. A declared `patch-object` action type would make
the handler unnecessary; that is a nextcloud-vue nicety, not a blocker.

## ADRs

- Company ADR-023: a claim is an action over data RBAC; whoever may write
  the case may claim it, and no dossiq check duplicates that.
- Company ADR-049: the placement is declared; the handler is the one
  `src/customComponents.js` pattern the bulk actions already use.

## Capabilities

- Modified: `case-management`: Claim and Release on the case, Claim on the
  queue.

## Impact

`src/manifest.json` pages `CaseDetail`, `Queue`, `Cases`; two handlers in
`src/customComponents.js`; `tests/vitest/caseActionsMenu.spec.js`; one e2e
spec. No PHP.
