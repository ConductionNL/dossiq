---
kind: code
depends_on: [one-term-engine, woo-publish-decision-from-the-case]
---

# Proposal: woo-dossier-shared-with-the-requester

## Summary

The requester works on their Woo request in Mijn zaken: they answer the organisation's question, add documents, and read along until the decision is public.

- Decision: Ruben, 2026-10-09. "OpenCatalogi now has screens for handling Woo requests, but that functionality belongs in dossiq as a case type; we can work together with citizens on their Woo dossier." This extends D1 (2026-10-05, dossiq owns the Woo request, its intake and its term).
- Counterparts:
  - `opencatalogi/woo-request-screens-move-to-dossiq` (new, this round): OpenCatalogi retires its request screens and keeps publishing.
  - `portaliq/woo-dossier-in-my-cases` (new, this round): renders what this change declares, on the case page in Mijn zaken.
  - `dossiq/one-term-engine` (open, PR #3524, another lane): the case deadline follows the statutory term. This change reads it and computes nothing.
  - `dossiq/woo-publish-decision-from-the-case` (open): writes `wooPublicationStatus` and `wooPublicationUrl` on the case. This change shows that link to the requester.
  - `dossiq/woo-request-takes-over-from-opencatalogi` and `opencatalogi/woo-request-intake-hands-over-to-dossiq` (open): the intake and the import of OpenCatalogi's stored requests.
- Boards: `portaliq/ZaakWooVerzoek` (new, design-system PR of this round) draws the requester's side. `dossiq/DqWooVerzoeken`, `dossiq/DqZaak`, `dossiq/DqTermijnen` and `dossiq/DqPubliceren` draw the officer's side, and take over what `opencatalogi/OcWooVerzoek(en)` showed.

## Why

What dossiq already gives a Woo requester, read on `development` at d471a5d07:

- The Woo case type (`lib/Settings/register.d/81-woo-verzoek.json`) declares public labels and descriptions per status, a document window open in the first four statuses, an amendment window and a withdrawal window in the first two.
- `PortalCaseDocuments` shows the decision first and the documents the organisation sent; never a draft, never an internal document.
- An `aanvullingsverzoek` (a request for more information) pauses the term. The requester sees it in the collection `vragenAanU`, "Wat wij nog van u nodig hebben".
- `AanvullingsverzoekResolutionService::recordAnswer()` records which items arrived. Only the handler's word closes the request, and only then does the term resume.

What is missing for working together on a Woo dossier:

1. **An answer from the portal does not reach the request.** The requester reads the question but can only answer it as a loose document or message. `recordAnswer()` is reached from the desk only (`CaseTermsController`). The handler has to match the answer by hand.
2. **The requester cannot see that the term stands still, or why it was extended.** Woo art. 4.4 lid 2 requires the extension and its reason to be told to the requester. portaliq shows one decision date, and nothing says it moved or why.
3. **The requester is not shown where the decision became public.** The case carries `wooPublicationUrl` after publishing, but no portal field carries it.
4. **The clarification of a too broad request has no shape of its own.** Woo art. 4.1 lid 5 says the organisation asks the requester to make the request more precise, and helps them do it. In dossiq that is an `aanvullingsverzoek` like any other, with items meant for documents.

## What changes

1. A portal action `beantwoordVraag` on a row of `vragenAanU`. The requester writes an answer and may attach files. dossiq records it on the open request through `recordAnswer()` with `complete` false, files it on the case, and tells the handler through `ApplicantPortalActs`. The term does not resume until the handler says the request is complete, as today (REQ-WDS-001).
2. dossiq writes two fields on the portal case: `legalDecisionDate`, from the case deadline that `one-term-engine` keeps, and `termNote`, one sentence in the requester's words on the term's state: running, extended with the reason, or standing still since a date until the requester answers (REQ-WDS-002).
3. dossiq writes `resultLink` on the portal case once the decision is published: the words "Bekijk wat openbaar is gemaakt" and `wooPublicationUrl`. Withdrawing the publication removes it (REQ-WDS-003).
4. An `aanvullingsverzoek` on a Woo case may have the kind `verduidelijking`. It asks one question in plain words, with no document items, and the answer is text (REQ-WDS-004).
5. The requester never reads the officer's work on a Woo case: no document assessment, no search plan, no redaction proposal, no reviewer (REQ-WDS-005).

## Out of scope

- The term arithmetic, the pause and the resume. They are `one-term-engine`'s and `termijn-pause-extension`'s. This change reads the deadline and the pause state, and writes words.
- Publishing. `woo-publish-decision-from-the-case` writes the link this change shows.
- The case page itself. portaliq renders it (`portaliq/woo-dossier-in-my-cases`).
- Objection against the Woo decision. `woo-case-screens-and-objections` and portaliq's `case-page-objection-and-complaint`.

## Release notes

- A Woo requester answers your question in Mijn zaken, and the answer lands on the open request.
- The requester sees when the term stands still, why it was extended, and where the decision is public.
