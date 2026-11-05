# Proposal: case-page-answers-in-card-notices

## Why

The case page boards (DqZaakTaken, DqZaakGerelateerd, DqZaakBetrokkenen) show the result of an act inside the card
that ran it, as an inline notice, instead of a toast. The nc-vue cards read a `notice` from the answer (the A1 card
notice envelope: `{ variant, role, title, text }`). The task, link and share endpoints did not answer one, there was
no cancel verb on the case's task route, the RGBZ rule that a case hangs as the subject under one case only was not
checked, and no endpoint listed the shares of a case.

## What changes

- `POST /api/case-tasks/{taskId}/complete` answers a success notice naming the task.
- New `POST /api/case-tasks/{taskId}/cancel` cancels through the task engine (never an object write), behind the
  same write guard on the task's own case; a refusal is 409 with the engine's reason in an error notice.
- `POST /api/cases/{caseId}/relations` refuses a `subject` link when the target already hangs as the subject under
  another case, or when the subject chain would loop, with 409 and an error notice naming both cases.
- New `GET /api/cases/{caseId}/shares` lists the case's shares (`{ id, kind, title, meta, status, url, link,
  revocable }`) for a reader who may read the case, and `DELETE /api/cases/{caseId}/shares/{shareId}` revokes one
  through OpenRegister when the share is the case's; OpenRegister's refusal is 409 with an error notice.

## Scope

Generic, named by what it does. One notice builder (`CaseActNotices`) serves every endpoint that answers one.
