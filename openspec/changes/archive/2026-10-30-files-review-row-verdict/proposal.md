# Each document row carries its review verdict

## Why

DqZaakDocumenten draws every document of a case that reviews its documents
with its verdict ("Openbaar", "Deels openbaar", "Niet openbaar", "Nog
beoordelen") and a "Beoordelen" button, primary while the document is still
open. The Files tab reads its rows from the dossier listing, which carried
the scan verdict but not the review verdict.

## What changes

- DocumentVerdicts::byDocument(): each document under review with its
  verdict, `open` while it has none, from the assessments the caller may read.
- The dossier listing puts it on each row as `reviewVerdict`.
- The Files tab shows a verdict column and a "Beoordelen" row button on a
  case type with `documentReview.enabled`, which opens the assessment dialog
  with that document chosen (DocumentAssessDialog `documentId`).
