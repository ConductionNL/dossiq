# document-assessment Specification

## Purpose
What dossiq does for document assessment. The requirements were first written by the change `woo-screens-follow-the-case` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-woo-screens-follow-the-case/`) and cover: A saved assessment leaves the outstanding list at once (REQ-DAS-SCR-01); A case answers its document verdicts and the grounds cited (REQ-CDV-001).

## Requirements

### Requirement: A saved assessment leaves the outstanding list at once (REQ-DAS-SCR-01)

When the server answers that a document's assessment is saved, the assessment dialog MUST stop listing that document as outstanding at once, also when the next read of the outstanding list does not see the save yet. A document the server did not save MUST stay listed. A label in the dialog MUST break between words, never inside one, unless a single word does not fit the line.

#### Scenario: The saved document leaves the list
@e2e exclude {asserted in tests/vitest/documentAssessDialog.spec.js with a list read that still answers the saved document}

- **GIVEN** the dialog lists two documents without an assessment
- **WHEN** the handler saves the first as Openbaar and the server answers it saved
- **THEN** the first MUST no longer be listed and the second MUST still be

### Requirement: A case answers its document verdicts and the grounds cited (REQ-CDV-001)

`GET /api/cases/{id}/review/verdicts` SHALL answer, for a reader of the case, the number of documents under review, the number that carry a verdict, what remains, the count per verdict value, and every ground cited on those documents with its label and the titles of the documents citing it, a partly withheld document marked as such. A reader without read access to the case SHALL get a refusal and nothing SHALL be read.

#### Scenario: four of seven documents assessed
- **GIVEN** a case with seven documents under review, two judged public, one partly public citing 5.1.2.e and one not public citing 5.1.2.e
- **WHEN** a reader of the case asks for its verdicts
- **THEN** the answer reads 7 documents, 4 assessed, 3 remaining, the counts per verdict, and ground 5.1.2.e with both documents, the partly public one marked

#### Scenario: a reader without access
- **GIVEN** a user who may not read the case
- **WHEN** they ask for its verdicts
- **THEN** the answer is a refusal and no assessment is read
