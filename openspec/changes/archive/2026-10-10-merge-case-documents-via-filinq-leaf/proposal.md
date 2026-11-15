# Proposal: merge-case-documents-via-filinq-leaf

## Why

Filinq ships a leaf that merges an object's documents into one PDF (filinq
change `merge-documents-to-pdf`, ConductionNL/filinq#1251). A handler who
sends a resident or an objection committee "the file" today downloads each
document and stitches them together by hand. Filinq asks dossiq to place the
leaf on the case page (dossiq#3185).

## What changes

- `CaseDetail` places filinq's `filinq-merge-to-pdf` leaf as a grid panel
  "Merge into one PDF", beside the Projects panel.

## Out of scope

- A bulk action on the Files tab: the shared integration registry has no
  bulk-action slot and the files browser carries no selection bar.
- Any merge code in dossiq. Filinq lists the documents the reader may see,
  lets the handler choose and order them, and writes the PDF beside the first
  one, so on a case it lands in the case folder.
