## ADDED Requirements

### Requirement: Every delivery writes a set with its own identity and manifest (REQ-WDS-001)

`WooPublicationService::publish()` SHALL write a `wooDeliveredSet` object in dossiq's register
before it creates the publication, with: `case`, `decision`, `status` (`pending`, then `frozen`),
`deliveredAt`, `deliveredBy`, `supersedes` (the previous set of the case, or empty), `publication`
(set when frozen), `items` and `setHash`. Each item SHALL carry `assessment` (the
`wooDocumentAssessment` uuid), `classification`, `deliveredRef` (the original for `openbaar`, the
`redactedDocumentRef` for `deels_openbaar`), `originalRef`, `fileName`, `size` and `sha256` of the
delivered bytes. `setHash` SHALL be the SHA-256 of the lines `<sha256>  <deliveredRef>` sorted by
`deliveredRef` and joined with a newline. When the publication is created, the set SHALL become
`frozen` with the publication id. When publishing fails, the pending set SHALL be deleted and
publish SHALL answer its existing failure. The set SHALL NOT be published or added to the
publication payload, and SHALL be readable only with read access to the case.

#### Scenario: A publish writes a frozen set
- **GIVEN** a decided Woo case with two `openbaar` documents and one `deels_openbaar` document with a finished redaction
- **WHEN** the handler publishes
- **THEN** one `wooDeliveredSet` SHALL exist with status `frozen`, the publication id, and three items
- **AND** the `deels_openbaar` item's `deliveredRef` SHALL be the redacted file and its `sha256` SHALL be the hash of the redacted bytes
- **AND** `setHash` SHALL equal the hash computed by the rule above over the three items

#### Scenario: The original never travels
- **GIVEN** the same publish
- **WHEN** the publication payload sent to opencatalogi is inspected
- **THEN** it SHALL contain no `originalRef` and no reference to the original of the `deels_openbaar` document

#### Scenario: A failed publish leaves no set
- **GIVEN** opencatalogi answers an error when the publication is created
- **WHEN** the handler publishes
- **THEN** no `wooDeliveredSet` for the case SHALL remain and publish SHALL answer the error

### Requirement: A frozen set and its assessments refuse change (REQ-WDS-002)

While a case has a `frozen` set, every update and delete of that set and of the case's
`wooDocumentAssessment` objects that the set names SHALL be refused with a sentence saying the set
was delivered on that date. A later delivery on the same case SHALL write a new set whose
`supersedes` names the frozen one. Withdrawing a publication SHALL NOT unfreeze its set; the set
SHALL record `withdrawnAt`.

The set's own folder SHALL hold a copy of every delivered file as it went out (the redaction for
`deels_openbaar`, never the original). At freeze the set SHALL carry OpenRegister's freeze marker with
state `geleverd`, so OpenRegister refuses every write to those copies through its files API and
through Files or WebDAV (openregister object-archive-state REQ-OAS-007). The withdraw stamp is the
one write that lifts that marker, for that write only, and sets it again. A copy that cannot be
written, or an OpenRegister without the archive handler, SHALL NOT stop the delivery: the hashes
still record what went out, and re-verification catches a changed file on the case.

#### Scenario: A delivered file cannot be overwritten
- **GIVEN** a frozen set whose folder holds `002-nota.pdf`, the redaction that went out
- **WHEN** someone writes new bytes to that file through OpenRegister's files API
- **THEN** the write SHALL be refused with 409 naming the freeze, and the file SHALL keep the bytes that went out

#### Scenario: A delivered verdict cannot be changed
- **GIVEN** a frozen set naming an assessment `openbaar`
- **WHEN** a handler changes that assessment to `niet_openbaar` through the OpenRegister API
- **THEN** the write SHALL be refused with the delivered-on sentence and the assessment SHALL be unchanged

#### Scenario: A second delivery is a new set
- **GIVEN** a frozen set on a case whose publication was withdrawn
- **WHEN** the handler publishes again
- **THEN** a second set SHALL exist with `supersedes` naming the first, and the first SHALL still be frozen with `withdrawnAt` set

### Requirement: A set is re-verifiable (REQ-WDS-003)

GET `/api/cases/{id}/woo/delivered-sets/{setId}/verify` and `occ dossiq:woo:verify-delivered-set <setId>`
SHALL recompute the SHA-256 of every delivered file and the set hash, and SHALL answer
`{verified: bool, setHash: {expected, actual}, items: list<{deliveredRef, expected, actual, status: 'match'|'changed'|'missing'}>}`.
`verified` SHALL be true only when every item matches and the set hash matches. A file that cannot
be read SHALL be `missing`, never `match`. The route SHALL require read access to the case.

#### Scenario: An untouched set verifies
- **GIVEN** a frozen set whose files are unchanged
- **WHEN** the verify route is called
- **THEN** `verified` SHALL be true and every item SHALL be `match`

#### Scenario: A replaced file is caught
- **GIVEN** a frozen set whose redacted file was overwritten afterwards
- **WHEN** the verify route is called
- **THEN** `verified` SHALL be false and that item SHALL be `changed` with both hashes

### Requirement: The delivered rendition is compared with its original (REQ-WDS-004)

For each item of a set whose `deliveredRef` differs from its `originalRef`, the officer SHALL be able
to open the original and the delivered rendition side by side, page by page, in filinq's review
workbench viewer. The set's page SHALL list every item with both files, its classification and its
hash, so the record shows what came in beside what went out. When filinq or its viewer is absent,
the compare action SHALL say that the compare view needs filinq and SHALL offer both files to open
separately. It SHALL NOT render a view that looks like a comparison.

#### Scenario: Side by side with filinq
- **GIVEN** filinq installed with the review workbench, and a frozen set with one redacted item
- **WHEN** the officer opens Compare on that item
- **THEN** the original SHALL be on one side and the redacted rendition on the other, on the same page number

#### Scenario: Without filinq the action says so
- **GIVEN** filinq is not installed
- **WHEN** the officer opens Compare
- **THEN** the dialog SHALL say the compare view needs filinq and SHALL offer the two files as separate links
