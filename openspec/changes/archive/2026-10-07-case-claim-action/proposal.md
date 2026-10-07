---
kind: code
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

- Header action Claim on `#CaseDetail`, offered on an open case: writes
  `assignee` = the signed-in user.
- Header action Release on `#CaseDetail`, offered on an open case: clears
  `assignee`.
- Row action Claim on `#Queue` and on `#Cases`.
- One endpoint behind all three, because only the server can refuse a claim on
  a case somebody else took a second earlier (design D-1). It records nothing
  of its own: the audit trail already carries the field change.

## Ownership

dossiq builds the rule, one endpoint and the three placements. It consumes
OpenRegister's object write and audit trail, and nextcloud-vue's `api-call`
action type, all shipped. The `handler` header action the design named does
not exist as a function seam on this surface (design D-4), and a declared
`patch-object` type would not help either: the rule this change is about is a
refusal, not a write.

## ADRs

- Company ADR-023: a claim is an action over data RBAC; whoever may write
  the case may claim it, and no dossiq check duplicates that.
- Company ADR-049: the placement is declared; the handler is the one
  `src/customComponents.js` pattern the bulk actions already use.

## Capabilities

- Modified: `case-management`: Claim and Release on the case, Claim on the
  queue.

## Impact

`src/manifest.json` pages `CaseDetail`, `Queue`, `Cases`; one row handler in
`src/utils/caseClaim.js`, registered in `src/customComponents.js`; two new
icons in `src/icons.js`; Dutch in `l10n/nl.{json,js}`. In PHP:
`CaseAssignmentService`, `CaseAssignmentController` and three routes. Tests:
`tests/Unit/Controller/CaseAssignmentControllerTest.php`,
`tests/vitest/caseClaimAction.spec.js`, `tests/e2e/case-claim.spec.ts`.
