# Documents can be copied to another case

## Why

DqDocumentenBeheer draws "Kopiëren naar andere zaak" in the band over the
ticked documents, and the coordinator decided to build it (24 Oct): the
ticked documents go to a case the handler picks.

## What changes

- POST /api/informatieobjecten/bulk/copy `{ ids, targetCaseId }`: refused
  before anything is read when the caller may not add documents to the target
  case (the same check as an upload); each document is copied only when the
  caller may read it, as a new document in the target case's folder whose
  provenance says it is a copy, of which document, by whom and when
  (CaseFileCollector, DocumentCopier). One result per document.
- The Files tab's bulk band offers "Copy to another case" first; the bulk
  dialog's `copy-to-case` mode finds the target case by number or title and
  leaves the current case out.
