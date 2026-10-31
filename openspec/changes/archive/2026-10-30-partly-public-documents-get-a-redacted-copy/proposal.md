---
kind: code
---

# Proposal: partly-public-documents-get-a-redacted-copy

## Why

A document assessed as partly public (`deels_openbaar`) is published only as its redacted copy: `PublicationSources::collectDelivery()` reads `redactedDocumentRef` on the assessment and skips the document when it is empty. Nothing wrote that field, so a Woo request with a partly public document could never publish it, and a request whose only disclosable documents were partly public refused with `no_publishable_documents`.

The redaction hand-off that existed could not work either:

- `FilinqRedactionClient::redact()` called filinq's detection and then its anonymisation with no review mark in between. Filinq refuses to write a copy for a detection run nobody checked (`RedactionOutputGuard::assertMayWrite`), so every run ended in `filinq_redaction_failed`.
- Its only caller, `WOOAnonymisationAssistService::reviewProposal()`, passed `['id' => documentRef]` with no file, so the document never resolved (`file_unresolved`), and the result was discarded.

## What changes

Generic code, named by what it does (decision 182). Any case type that assesses documents as partly public uses it; the Woo request is configuration.

1. **A redaction step per partly public document**, following board DqLakken (identity.conduction.nl/screens/board?id=dossiq/DqLakken):
   - detect: filinq's entities merged with the rules floor and Hermiq's spans, stored unchecked on the assessment (`redaction`);
   - check: the officer confirms the list they reviewed; only then is filinq's review mark written, in the officer's name, for exactly that list;
   - redact: filinq writes the copy of the checked list; the copy is recorded as an informatieobject of the case and `redactedDocumentRef` points at it.
2. **Upload instead**: the officer uploads a version redacted elsewhere; it is stored on the case, recorded the same way, and `redactedDocumentRef` points at it.
3. **The redacted copy is not a document under review.** It sits in the case folder and the folder's listener records it as a case document, so the documents under review and the outstanding list leave out every assessment's `redactedDocumentRef`.
4. **The broken hand-off goes.** `WOORedactionService` and `FilinqRedactionClient::redact()` are removed; the client offers `detect`, `markChecked` and `anonymize`. Approving a Hermiq proposal records the reviewed spans and writes no copy.

## Board and spec

The board is the canon (decision 130). It shows one action, "Lakken vastleggen", after every passage has a verdict; it has no separate "gecontroleerd" control and no upload. The spec follows the board: deciding every passage and pressing "Lakken vastleggen" is the officer's check, and that one press first writes the check and then asks for the copy. The upload ("Gelakte versie uploaden") is a second header action the board does not draw yet; the board should get it.

## Out of scope

- The guard that holds the redaction stage until every partly public document has its copy: change `the-redaction-stage-waits-for-every-redacted-copy`.
- Filinq's own review screen (lane L2 changes filinq's UI to write the review mark there).
