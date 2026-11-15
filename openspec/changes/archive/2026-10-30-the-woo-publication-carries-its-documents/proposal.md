---
kind: code
depends_on: []
---

# Proposal: the-woo-publication-carries-its-documents

## Why

Rehearsal R3 (16 Oct, dossiq 0.4.54-unstable.20261016050320). A Woo request had two documents: one assessed Openbaar, one Deels openbaar without a redacted copy yet. "Publiceren (Woo)" answered 200, the publication was in the public search, and its page said "Deze publicatie heeft geen documenten". Even the public document was missing. The log also said "WooDossierReturn: the source dossier is gone", although the resident's dossier existed.

Two causes:

1. **The file bytes were read under the handler's session.** A case file lives in OpenRegister's managed tree, in the openregister account's home. The handler has no mount on that home, so `IRootFolder::getById()` answered nothing, every disclosable document went out with empty `content`, and the transport skips a document without content without a word. The publication was created with no files and the publish reported success.
2. **The dossier was read under the handler's scope.** The resident's collection lives in OpenCatalogi's register and the resident's tenant. `runAsSystem` bypasses RBAC and tenancy only for a caller with no user, so a signed-in handler's scoped read found nothing.

## What Changes

- A case file is read through OpenRegister's `FileService::getFileById()` first, which looks in the managed tree for any caller, and through the root folder only after that (`FindsFilesById`, used by the Woo case documents and by the redacted copy recorder).
- A publish where a disclosable document has no readable file is refused with 409 `documents_unreadable`, naming the documents. Nothing goes out half.
- The dossier return reads and writes the one collection the case names unscoped (`_rbac: false`, `_multitenancy: false`), and only while that collection's owner is the case's requester (`portalSubject`), the check the intake made.

## Impact

- `lib/Service/Support/FindsFilesById.php` (new), `lib/Woo/WooCaseDocuments.php`, `lib/Service/Redaction/RedactedCopyRecorder.php`, `lib/Service/WooPublicationService.php`, `lib/Controller/WOOAssessmentController.php`, `lib/Woo/WooDossierReturn.php`, l10n.
- Unscoped access: the dossier return's read and write of one collection, scoped by the case's `wooRequest.collectionId` and checked against the case's `portalSubject`. No other `_rbac` flag is added.
