# documents-merge-pdf Specification

## Purpose
What dossiq does for documents merge pdf. The requirements were first written by the change `documents-merge-pdf` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-documents-merge-pdf/`) and cover: a case's documents are merged into one PDF by filinq.

## Requirements

### Requirement: a case's documents are merged into one PDF by filinq

The merge endpoint MUST refuse a caller who may not add to the case before asking filinq, MUST refuse fewer than two files, MUST hand the files to filinq in the order given with the case as host object, and MUST answer 503 with a sentence when filinq is absent. The PDF lands beside the first file, in the case folder.

#### Scenario: two documents
- GIVEN Verzoek om informatie.pdf and Advies Mobiliteit en verkeer.docx ticked on the case
- WHEN the handler chooses "Samenvoegen tot pdf"
- THEN one PDF with both lands in the case's documents
