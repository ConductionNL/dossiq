---
kind: code
depends_on: []
---

# Proposal: a-verdict-marks-its-document-in-scope

## Why

Rehearsal on a fresh instance, 15 October (Woo request 2026-0013): a handler assessed the one document of the case as Public through "Assess documents". The save answered "1 document assessed." and `/review/summary` counted the verdict, but the outstanding list kept the document and the transition guard said "1 van 1 documenten wachten nog op een beoordeling". The case could not move on.

The case type switches document review on, and document review counts a document nobody marked as outstanding whatever verdict it carries (REQ-WRT-001). The assess dialog records the verdict only, so the document stayed `unmarked` and nothing a handler could do in that dialog cleared it. The summary showed both facts side by side: `relevance.unmarked: 1` and `verdicts.openbaar: 1`.

## What changes

A verdict says the document is about the request. Saving a verdict on a document that is still unmarked marks it in scope, recorded as the assessor's marking (`relevanceSource: reviewer`, `markedBy`, `markedAt`). A document a reviewer or a rule already marked keeps that marking, so an out-of-scope document stays out of scope. A refused verdict marks nothing.

Generic: `DocumentRelevance::markInScopeWhereUnmarked()` knows nothing of a procedure; the assessment save calls it for the documents it saved.

## Not changed

- An unmarked document without a verdict is still outstanding.
- A case type without document review records no review, as before.

## Rollback

Revert the commit. A document assessed without being marked is outstanding again until someone marks it.
