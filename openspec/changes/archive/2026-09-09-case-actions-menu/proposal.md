---
kind: code
depends_on: []
---

# Proposal: case-actions-menu

Round 2 competitor analysis, rows A24, A25 and A26 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 2 on the
placement ladder: actions on an existing page. One noun: what you can do to a
case.

## Why

The Actions menu on `CaseDetail` holds one entry, Log hours. You cannot copy a
case, you cannot start a sub-process for it, and you cannot plan a follow-up.
`CaseTypeCopyService` copies case types, not cases. `DossiqTxCreateSubCaseNode`
and the Run button on `FlowDetail` exist, but nothing on the case page lists
the flows a handler may start. Nothing in the app plans a case for a later
date.

Every competitor puts these three under the case's Actions menu:

- OpenCase: `opencase/round2/case-detail-anatomy.md` (Actions: Copy case).
- GZAC: `valtimo/round2/case-detail-anatomy.md` (a Start split button listing
  the processes marked Door gebruiker te starten).
- Zaaksysteem: `xxllnc-zaken/round2/pages/Case-Zaakacties.md` (Zaak kopiëren)
  and `xxllnc-zaken/round2/pages/Case-Relaties.md` (Zaak relateren; Geplande
  zaken: Voeg toe).
- Dossiq baseline: `_round2/dossiq-baseline/case-detail-anatomy.md` (one
  header action) and `_round2/dossiq-baseline/journeys.md` J5.

## What Changes

- A Copy case entry in the Actions menu of `CaseDetail`. It opens
  `CnCopyDialog`, and a new `CaseCopyService` copies the type, requester,
  properties and the open documents by link into a new case in the type's
  initial status.
- A Start entry listing the flows the case type marks as startable by a
  handler. Run posts the case as the flow's subject through OpenRegister's
  run endpoint.
- A Plan follow-up entry on the Related cases tab. It writes a scheduled flow
  that creates a case of the chosen type on the chosen date, related to this
  one. The case appears when it is due.
- No entry is removed. Log hours stays where it is.

## Interim and durable route

Copy is dossiq code because `CnCopyDialog` needs a caller that knows which
fields to carry over. A `copy` header action type in nextcloud-vue over
`CnCopyDialog` with a field list would make the entry a config declaration;
that request is a blocked task. Start and Plan follow-up are config over
OpenRegister flows once `caseType.startableFlows` exists; only the picker
dialog is code.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `case-management`: a case can be copied from its page.
- `workflow-definition-engine`: a handler starts a startable flow from the
  case, and plans a follow-up case that is created when due.

## Impact

- `lib/Service/CaseCopyService.php` (new), `lib/Controller/CaseActionsController.php`
  (new, `copy`, `startableFlows`, `plan`), three routes in `appinfo/routes.php`.
- `lib/Settings/dossiq_register.json`: property `startableFlows` on `caseType`.
- `src/manifest.json`: three `headerActions` on page `CaseDetail`.
- `src/components/case/CaseStartFlowDialog.vue` and
  `src/components/case/CasePlanFollowUpDialog.vue` (new).
- E2E: `tests/e2e/case-actions-menu.spec.ts` (new).
- No effect on Pipelinq's request-to-case bridge.
