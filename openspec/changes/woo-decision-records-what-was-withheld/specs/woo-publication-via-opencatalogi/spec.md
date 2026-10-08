## ADDED Requirements

### Requirement: A published Woo decision records its withheld documents in opencatalogi (REQ-WRW-001)

After `WooPublicationService::publish()` has created or updated the publication, dossiq SHALL call
`OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record($publicationId, $entries, 'dossiq')` exactly
once, with one entry per `niet_openbaar` assessment of the case. Each entry SHALL be
`{position: int, grounds: list<string>}` and SHALL hold no other key: `position` is the document's
inventory number as `WooDecisionDrafts::wooDecision()` gives it, and `grounds` the assessment's
`weigeringsgronden`. dossiq SHALL NOT send a `title`, a file, a file id, a hash, a document
reference or any text. `publish()` SHALL answer the outcome under `withheldRecord` as
`{status: 'recorded', recorded, refused}`, and SHALL NOT roll back the publication when entries are
refused or the call throws. `withdraw()` SHALL call `record($publicationId, [], 'dossiq')`.

#### Scenario: Two withheld documents are recorded with their grounds
- **GIVEN** a decided Woo case with five documents, numbers 3 and 7 assessed `niet_openbaar` under 5.1.2.e
- **WHEN** the handler publishes the decision
- **THEN** `record()` SHALL be called once with the new publication id, source `dossiq` and exactly the entries `{position: 3, grounds: ["5.1.2.e"]}` and `{position: 7, grounds: ["5.1.2.e"]}`
- **AND** the publish answer SHALL carry `withheldRecord.status` `recorded`

#### Scenario: Nothing that could reveal content is sent
- **GIVEN** the same case, with file ids, hashes and document references on its documents and assessments
- **WHEN** the entries are built
- **THEN** no entry SHALL carry any key other than `position` and `grounds`

#### Scenario: A refusal is shown, the publication stands
- **GIVEN** opencatalogi refuses the entry at position 7 with `unknown-ground`
- **WHEN** the handler publishes
- **THEN** the publication SHALL exist, and `withheldRecord.refused` SHALL name position 7, its code and `unknown-ground`

### Requirement: Without opencatalogi's receiving side nothing is recorded (REQ-WRW-002)

When opencatalogi is not installed, `publish()` SHALL refuse as it does today and `record()` SHALL
NOT be called. When opencatalogi is installed but `WithheldDocuments` does not resolve, nothing
SHALL be recorded, the publication SHALL stand, and `withheldRecord` SHALL answer
`{status: 'not-recorded', reason: 'opencatalogi-too-old'}`.

#### Scenario: No opencatalogi, nothing recorded
- **GIVEN** opencatalogi is not installed
- **WHEN** the handler publishes a decided Woo case with withheld documents
- **THEN** the answer SHALL be `opencatalogi_not_installed` and no record call SHALL be made

#### Scenario: An older opencatalogi, nothing recorded
- **GIVEN** opencatalogi installed without `WithheldDocuments`
- **WHEN** the handler publishes
- **THEN** the publication SHALL be created and `withheldRecord` SHALL answer `not-recorded` with `opencatalogi-too-old`
