# The ticked documents merged into one PDF

## Why

DqDocumentenBeheer draws "Samenvoegen tot pdf" in the band over the ticked
documents. The coordinator decided (24 Oct) to build it when filinq already
merges: it does (filinq merge-documents-to-pdf, DocumentMergeService), so
dossiq hands the files to filinq the way it already hands a document to
filinq's redaction (FleetAppId over the container).

## What changes

- FilinqMergeClient: filinq's DocumentMergeService::merge() with the files in
  order, the case as host object, an optional name.
- POST /api/cases/{caseId}/dossier/merge `{ fileIds, name? }`: refused before
  filinq is asked when the caller may not add to the case (the PDF lands in
  its folder) or fewer than two files are given; without filinq it answers
  503 with a sentence; filinq reads every input as the caller.
- The bulk band offers "Merge into PDF" first; the bulk dialog's `merge-pdf`
  mode posts the ticked files and refreshes the list.
