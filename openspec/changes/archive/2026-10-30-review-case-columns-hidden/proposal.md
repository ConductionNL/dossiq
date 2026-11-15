# A review case starts its files table without Scan and Visible to

## Why

On a case that reviews its documents the Files tab carries the verdict per
row and the Beoordelen button; the DqZaakDocumenten board draws that table
with its head, checkboxes and Columns menu, and without the Scan and Visible
to columns (coordinator decision, 25 Oct). Users keep both columns: they are
one tick away in Columns.

## What changes

- The `case-files` columns Scan result and Visible to declare
  `hidden: "@context.caseType.documentReview.enabled"`: hidden by default on
  a case whose case type reviews its documents, shown on every other case.
- Until the library resolves `@context` in widget props the token stays a
  string, which is not `true`, so both columns show as before.
