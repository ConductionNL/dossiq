---
kind: code
depends_on: []
---

# Proposal: guards-refuse-when-unsure

## Summary

A ZGW guard that cannot run its check refuses the request. It no longer passes, and it no longer answers something it did not find.

- Decision: Ruben, 2026-10-12, decision 224. "Guards refuse when unsure: the case type delete check and document rules drc-004 and drc-007 refuse with a clear error when their check cannot run, like drc-003."

## Why

Three guards read the store to decide. When the read failed they let the request through:

- the case type delete check deleted a case type whose cases it could not read;
- drc-004 stored a link between a document and a case or decision without knowing the counterpart exists;
- drc-007 deleted a document without knowing whether relations still point at it.

A fourth, drc-003, did refuse, but said "this combination already exists" about a relation nobody had found. A client that reads that answer stops retrying a request that was never wrong.

"The lookup ran and found nothing" and "the lookup could not run" are two answers. Only the first is a pass.

## What changes

1. One refusal for a check that could not run: HTTP 503, code `check-not-completed`, a detail that names the rule, and no `invalidParams` (REQ-ZBR-020).
2. drc-003, drc-004, drc-007 and the controller's own relation check before a document delete (drc-008a) answer it when their search fails.
3. The case type delete check blocks the delete as unverified when it cannot read the cases or the final statuses. The ZGW delete answers 503 with code `destroy_check_not_completed` (REQ-ZBR-021).
4. Every such refusal is logged at error with the rule and the object.

## Why 503

The ZGW error model has two shapes. A `ValidatieFout` (400, with `invalidParams`) says the request is wrong and names the field to fix. A `Fout` covers everything else. A check that did not run says nothing about the request, so it is a `Fout`, and a 4xx would tell the client to change a body that was fine.

Of the 5xx statuses the ZGW specifications declare 500 on every operation. These endpoints already answer 503 with a `detail` when OpenRegister is absent (`ZgwService::unavailableResponse`). A store that cannot be read is the same situation, so the guards answer the same status. It also tells a client the one useful thing: the request can be sent again unchanged.

The status is one constant, `ZgwRuleResults::NOT_VERIFIED_STATUS`.

## Out of scope

- A setting that is not configured. A rule whose schema setting is empty still skips its lookup, as drc-003 does.
- The cross-register writes of the Besluiten API, which stay best effort, and the 404 a failed ZGW delete answers. Both are in QUESTIONS.md (Q-dossiq-L11-2).
- The other lookups in `ZgwRuleLookups` that answer "none" when a read fails (Q-dossiq-L11-3).

## Release notes

- A ZGW request is refused with 503 `check-not-completed` when dossiq could not check a business rule. Nothing is stored or deleted. Send the request again.
- Deleting a case type answers 503 `destroy_check_not_completed` when dossiq could not read the cases of that type.
