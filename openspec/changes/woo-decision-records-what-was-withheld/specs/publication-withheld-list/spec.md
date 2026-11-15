## ADDED Requirements

### Requirement: A publication records what it withheld (REQ-PWL-001)

When dossiq publishes a decision through opencatalogi, it SHALL record the publication's withheld list through opencatalogi's record door (`OCA\OpenCatalogi\Service\Woo\WithheldDocuments::record($publicationId, $entries, 'dossiq')`), once, after the publication stands. The capability is generic (decision 182): `Service\Publication\WithheldEntries` builds the entries and `Service\Publication\WithheldListRecorder` hands them over, and a case type configures which assessment classification means withheld in full and which field holds the ground codes. The Woo case type is the first user (`niet_openbaar`, `weigeringsgronden`).

Each entry SHALL be exactly `{position, grounds}`. `position` is the document's number in the case's inventory: every assessed document of the case in `documentRef` order, numbered from 1. dossiq keeps no other inventory list. A refused entry, an opencatalogi without the record door, or a door that throws SHALL be answered (`{status: 'recorded', recorded, refused}` or `{status: 'not-recorded', reason}`) and SHALL NOT undo the publication. The case SHALL carry the outcome as one sentence in `wooWithheldNote`, empty when every entry went in, shown in its publication section. Withdrawing SHALL record an empty list.

#### Scenario: Entries carry nothing but a position and grounds
- **GIVEN** seven assessed documents with file ids, hashes, titles and references, numbers 3 and 7 withheld in full
- **WHEN** the entries are built
- **THEN** there SHALL be two entries, `{position: 3, grounds: [...]}` and `{position: 7, grounds: [...]}`, with no other key

#### Scenario: A failure is reported, the publication stands
- **GIVEN** the record door throws
- **WHEN** the decision is published
- **THEN** the publication SHALL exist, the answer SHALL carry `withheldRecord` `{status: 'not-recorded', reason: 'record-failed'}`, and the case SHALL show one sentence saying the withheld documents were not recorded
