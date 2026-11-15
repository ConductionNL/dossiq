## ADDED Requirements

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
