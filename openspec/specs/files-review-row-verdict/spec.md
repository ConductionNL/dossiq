# files-review-row-verdict Specification

## Purpose
What dossiq does for files review row verdict. The requirements were first written by the change `files-review-row-verdict` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-files-review-row-verdict/`) and cover: each document row carries its verdict; a document is assessed from its row.

## Requirements

### Requirement: each document row carries its verdict

The dossier listing MUST put each document's review verdict on its row as `reviewVerdict`, read from the assessments the caller may read, `open` for a document under review without one, and nothing for a document not under review.

#### Scenario: Lindelaan
- GIVEN 7 documents under review, 4 assessed
- THEN 4 rows carry their verdict and 3 carry `open`

### Requirement: a document is assessed from its row

On a case type that reviews its documents the Files tab MUST show the verdict per row and a "Beoordelen" button, primary while the verdict is `open`, that opens the assessment dialog with that document chosen.

#### Scenario: Plattegrond lichtmasten
- GIVEN the open document "Plattegrond lichtmasten"
- WHEN the handler presses its "Beoordelen"
- THEN the assessment dialog opens with that document ticked
