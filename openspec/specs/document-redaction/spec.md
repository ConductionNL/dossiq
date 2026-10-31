# document-redaction Specification

## Purpose
Redacting a partly public document: the panel that shows what filinq and Hermiq detected, the officer's check before a redacted copy is written, recording that copy as a document of the case, and uploading a redacted version instead. Created by change partly-public-documents-get-a-redacted-copy (2026-10-30).

## Requirements

### Requirement: The redaction panel shows what filinq and Hermiq detected (REQ-DRD-001)

For a document assessed as partly public, the system SHALL detect the passages to redact by running filinq's entity detection over the document's file and SHALL merge in the deterministic rules floor and Hermiq's spans over the extracted text, as entities of the form `{type, value, source}`, each type and value once. The list SHALL be stored on the assessment (`redaction.entities`) without a check, and a new detection SHALL drop an earlier check. A document that is not partly public SHALL be refused.

@e2e exclude Detection runs inside filinq and OpenRegister's entity recognition, which the dossiq e2e environment does not install. Covered by DocumentRedactionServiceTest through filinq's own review gate.

#### Scenario: Filinq and Hermiq both find a name

- **GIVEN** a partly public document whose file filinq detects "Sanne de Vries" (PERSON) in, and whose text Hermiq marks "P. Jansen" as a name
- **WHEN** the officer opens the redaction of that document
- **THEN** the list holds both, the first with source `filinq` and the second with type `PERSON` and source `hermiq`
- **AND** the assessment's `redaction` carries the list and no `checkedBy`

#### Scenario: A public document is not redacted

- **GIVEN** a document assessed as public
- **WHEN** a detection is asked for it
- **THEN** the request is refused with `not-partly-public` and nothing is written

### Requirement: A redacted copy is written only after a named officer checked the entities (REQ-DRD-002)

The system SHALL write filinq's review mark only when the officer confirms a non-empty list of passages, in the officer's name and for exactly that list, and SHALL store the list with `checkedBy` and `checkedAt` on the assessment. The redaction SHALL ask filinq for the copy of the stored, checked list, and SHALL be refused when nothing was checked or the file changed since. When filinq writes the copy, the system SHALL record it and set `redactedDocumentRef`. When filinq writes no copy, the system SHALL say why and SHALL NOT set `redactedDocumentRef`: no live detector (`detection-unavailable`, with the advice to switch entity detection on or upload a version), no check for this list (`not-checked`), or no output (`no-redacted-copy`).

@e2e exclude Filinq's review gate and anonymisation are not installed in the dossiq e2e environment. Covered by DocumentRedactionServiceTest against verbatim copies of filinq's gate classes.

#### Scenario: No check, no copy

- **GIVEN** a detected, unchecked list
- **WHEN** the officer asks for the redacted copy
- **THEN** the request is refused with `not-checked`
- **AND** filinq is not asked and `redactedDocumentRef` stays empty

#### Scenario: Checked, then redacted

- **GIVEN** the officer confirmed the list
- **WHEN** they ask for the redacted copy
- **THEN** filinq's mark names the officer and covers exactly that list
- **AND** filinq writes the copy, it is recorded as an informatieobject of the case, and `redactedDocumentRef` names that informatieobject

#### Scenario: The check covered another list

- **GIVEN** filinq holds a mark for a different list of the same file
- **WHEN** the copy is asked for
- **THEN** filinq refuses and the system answers `not-checked`, with `redactedDocumentRef` still empty

#### Scenario: Entity detection is off

- **GIVEN** OpenRegister's entity recognition is switched off
- **WHEN** the copy is asked for
- **THEN** the system answers `detection-unavailable` with the advice to switch it on or upload a redacted version

### Requirement: The redacted copy is recorded as a document of the case (REQ-DRD-003)

The redacted copy SHALL be an informatieobject joined to the case, carrying its file id and the original's document type and confidentiality. When the case folder's listener already recorded the file, that record SHALL be used. The documents under review and the documents waiting for an assessment SHALL leave out every assessment's `redactedDocumentRef`, because the copy is part of its original's assessment.

@e2e exclude The copy only exists after a filinq run or an upload through the redaction route; the document lists are covered by OutstandingDocumentsTest.

#### Scenario: The copy does not wait for an assessment

- **GIVEN** a case with one assessed, partly public document and its recorded redacted copy
- **WHEN** the outstanding documents are read
- **THEN** none is outstanding and only the original is under review

### Requirement: An officer can upload a redacted version instead (REQ-DRD-004)

For a partly public document the officer SHALL be able to upload a version redacted elsewhere. The system SHALL store it on the case under the original's name with `_gelakt` and the moment of upload, record it as in REQ-DRD-003, set `redactedDocumentRef`, and record `method: upload` with who uploaded it. An empty upload and an executable file SHALL be refused.

@e2e exclude Covered by DocumentRedactionServiceTest and DocumentRedactionControllerTest; the e2e environment exercises uploads through the case dossier.

#### Scenario: Upload instead of filinq

- **GIVEN** a partly public document and no filinq run
- **WHEN** the officer uploads "offerte-gelakt.pdf"
- **THEN** the file is stored on the case, recorded as an informatieobject, and `redactedDocumentRef` names it

### Requirement: The redaction panel follows board OcLakken (REQ-DRD-005)

The case header SHALL offer the redaction step for a case type that configures it. The panel SHALL show, per partly public document, the document text with the passages marked, and beside it the proposal: the number of passages found and, per passage, its type and text with a verdict (Akkoord, Aanpassen, Niet lakken) and "Passage toevoegen". "Lakken vastleggen" SHALL be enabled only when every passage has a verdict; pressing it SHALL record the check of the passages to redact and then ask for the copy. "Vorig document" and "Volgende document" SHALL move between the partly public documents. "Gelakte versie uploaden" SHALL upload a version instead. A refusal SHALL be shown as the server's sentence.

@e2e exclude The panel's detection and redaction need filinq, which the e2e environment does not install. The component is covered by its vitest spec.

#### Scenario: Every passage needs a verdict

- **GIVEN** four passages, three with a verdict
- **THEN** "Lakken vastleggen" is disabled
- **WHEN** the fourth gets "Niet lakken"
- **THEN** "Lakken vastleggen" is enabled and the check covers the three passages to redact
