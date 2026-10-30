# woo-case-type Delta: partly-public-documents-get-a-redacted-copy

**Status**: draft
**Scope**: how a Woo request's partly public documents are redacted.

## MODIFIED Requirements

### Requirement: Redaction via Docudesk integration

Documents assessed as "Deels openbaar" MUST be redacted through filinq, with a named officer's check of the passages before any copy is written, or by uploading a version redacted elsewhere. The redaction step is the generic one of the document-redaction capability (REQ-DRD-001 to REQ-DRD-005); the Woo request configures it.

@e2e exclude Filinq is not installed in the dossiq e2e environment. Covered by DocumentRedactionServiceTest.

#### Scenario: Send document for redaction

- **GIVEN** a document assessed as "Deels openbaar"
- **WHEN** the case worker starts its redaction
- **THEN** the document MUST be sent to filinq's entity detection
- **AND** filinq's entities, the rules floor and Hermiq's spans MUST be stored unchecked on the assessment

#### Scenario: Review AI-detected entities

- **GIVEN** a document assessed as "Deels openbaar"
- **WHEN** the case worker opens its redaction
- **THEN** each passage filinq and Hermiq found MUST be shown with its type and text
- **AND** the case worker MUST be able to accept, adjust or drop each one and add passages that were missed

#### Scenario: Store anonymized version

- **GIVEN** the case worker checked the passages and asked for the redacted copy
- **WHEN** filinq wrote it
- **THEN** the copy MUST be stored as a document of the case
- **AND** the original MUST be preserved
- **AND** the assessment's `redactedDocumentRef` MUST name the copy, which is the only version the publication delivers

#### Scenario: Upload a redacted version

- **GIVEN** a document assessed as "Deels openbaar"
- **WHEN** the case worker uploads a version redacted elsewhere
- **THEN** it MUST be stored as a document of the case and named by `redactedDocumentRef`

#### Scenario: Batch redaction

- **GIVEN** 8 documents assessed as "Deels openbaar"
- **WHEN** the case worker selects "Batch anonimiseren"
- **THEN** all 8 documents MUST be sent to filinq simultaneously
- **AND** the system MUST track progress per document and notify when each is ready for review

#### Scenario: Complete redaction stage gate

- **GIVEN** a WOO case in stage "Lakken / Anonimiseren"
- **WHEN** the case worker attempts to advance to "Besluit"
- **THEN** the system MUST verify that ALL documents assessed as "Deels openbaar" have finalized anonymized versions
- **AND** block advancement if any document is still pending redaction

### Requirement: A redaction refused for want of a detector says so

When filinq refuses to anonymise a document because no entity detector is live, dossiq MUST answer `detection-unavailable` with a sentence that says entity detection is switched off and that a redacted version can be uploaded instead, and MUST NOT set `redactedDocumentRef`. On a completed run dossiq MUST record the backend filinq names in its detection report, and MUST treat filinq's `nothing_found` as no redacted copy.

@e2e exclude The refusal is raised inside filinq's anonymisation service when OpenRegister's entity detection is off or unavailable; a dossiq e2e run cannot switch filinq's detector state. Covered by FilinqRedactionClientTest and DocumentRedactionServiceTest against a verbatim copy of filinq's exception.

#### Scenario: Detection is switched off

- **GIVEN** a Woo case with a checked, partly public document, and entity detection switched off
- **WHEN** the redacted copy is asked for
- **THEN** the answer MUST be `detection-unavailable`
- **AND** no redacted copy MUST be recorded

#### Scenario: Filinq names the detector that looked

- **GIVEN** filinq anonymised a document with the regex detector
- **WHEN** the redaction is recorded
- **THEN** its detection backend MUST be `regex`
