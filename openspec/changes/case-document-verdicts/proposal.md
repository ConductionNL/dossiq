# Proposal: a case answers its document verdicts and the grounds cited

## Why

DqZaakDocumenten draws, on the Bestanden tab, "4 van 7 beoordeeld" over the verdicts so far (Openbaar 2, Deels openbaar 1, Niet openbaar 1, Nog beoordelen 3) and a card "Weigeringsgronden tot nu toe" listing every ground cited with the documents that cite it. The verdicts are records of the configured assessment schema; no route answered them per case in the shape a card can draw.

## What changes

- `GET /api/cases/{id}/review/verdicts`: the case's documents under review, how many carry a verdict, the count per verdict value, and every cited ground with its label and the documents citing it. The case read check runs before any read.
- The verdict field, the grounds field and the verdict that marks a document as partly withheld are configuration (`document_verdict_field`, `document_grounds_field`, `document_partial_verdict`), defaulting to the assessment schema's fields.
- The simple case page shows both cards on the Files tab, above the folder.
