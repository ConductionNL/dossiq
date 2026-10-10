## MODIFIED Requirements

### Requirement: Per-document disclosure assessment
Each document in a WOO case MUST be individually assessed for disclosure with mandatory legal basis for withheld documents.

#### Scenario: Three-way assessment per document
@e2e exclude Unchanged by woo-refusal-grounds-list; carried whole because the requirement is modified. No assessment screen renders the dropdown yet.
- **GIVEN** a WOO case in stage "Beoordelen documenten" with 15 collected documents
- **WHEN** the case worker opens the document assessment view
- **THEN** each document MUST have a dropdown with options:
  - **Openbaar** -- Full disclosure
  - **Deels openbaar** -- Partial disclosure (requires redaction)
  - **Niet openbaar** -- Withheld (requires weigeringsgrond)
- **AND** unassessed documents MUST be visually distinct (grey/pending state)

#### Scenario: Mandatory weigeringsgrond for withheld documents
@e2e exclude No assessment screen posts to the bulk route yet. Covered by `WOODocumentAssessmentServiceTest::testValidateRequiresWeigeringsgrondForNietOpenbaar`, `testAnOldCodeThatNoLongerExistsIsRefused` and `testAGroupNodeCannotBeCited`.
- **GIVEN** a document assessed as "Niet openbaar"
- **WHEN** the case worker saves the assessment
- **THEN** they MUST select one or more grounds from dossiq's list of Woo refusal grounds (`wooRefusalGround`, settled in woo-refusal-grounds-list design D-2)
- **AND** every selected ground MUST be an active, citable entry of that list
- **AND** the weigeringsgrond MUST be stored as metadata on the document assessment

#### Scenario: Weigeringsgrond for partially disclosed documents
@e2e exclude Unchanged by woo-refusal-grounds-list; carried whole because the requirement is modified. Covered by `WOODocumentAssessmentServiceTest::testValidateRequiresWeigeringsgrondForDeelsOpenbaar`.
- **GIVEN** a document assessed as "Deels openbaar"
- **THEN** the case worker MUST also select the applicable weigeringsgrond(en) for the redacted portions
- **AND** these grounds MUST appear in the inventarislijst and decision document

#### Scenario: Assessment progress indicator
@e2e exclude Unchanged by woo-refusal-grounds-list; carried whole because the requirement is modified.
- **GIVEN** 15 documents in a WOO dossier with 10 assessed and 5 pending
- **THEN** the case detail MUST show a progress indicator: "10/15 documenten beoordeeld"
- **AND** the progress MUST be visible in both the case detail and the case list overview

#### Scenario: Bulk assessment for similar documents
@e2e exclude Unchanged by woo-refusal-grounds-list; carried whole because the requirement is modified.
- **GIVEN** multiple documents that share the same assessment (e.g., all internal meeting notes are "Niet openbaar" under 5.2.1)
- **WHEN** the case worker selects multiple documents and applies a bulk assessment
- **THEN** all selected documents MUST receive the same assessment and weigeringsgrond
- **AND** each individual document MUST still be editable afterwards
