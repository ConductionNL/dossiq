# document-provenance

Generic capability (decision 182: procedures are configuration, code is generic). Any document
collected onto a case records where it was found, and every reader (a collection report, a review
batch, a triage rule) reads it through one reader, whatever the case type. The Woo search plan's
custodians are one way to name a custodian; the provenance fields themselves belong to no
procedure.

## ADDED Requirements

### Requirement: A collected document carries its provenance for any case type (REQ-DPV-001)

A document collected onto a case SHALL carry `provenance` with `source`, `sourceSystem`,
`custodian`, `location`, `terms`, `searchedAt` and `searchedBy`: on its own `informatieobject`
when the file was copied, on this case's `zaakinformatieobject` join when the document was linked
from another case. `CaseDocumentProvenance::forDocument()` SHALL answer every field, empty where unknown,
prefer the record over the join, read only this case's join, and answer `source` as the
`sourceSystem` of a document collected before the system was recorded separately. A document
added by hand SHALL answer all fields empty.

#### Scenario: A linked document reads this case's join
- **GIVEN** a document linked onto case X with custodian "Wethouder Ruimte" on X's join, and onto case Y with custodian "Elsewhere" on Y's join
- **WHEN** its provenance is read for case X
- **THEN** the custodian SHALL be "Wethouder Ruimte"

#### Scenario: A document added by hand
- **GIVEN** a document uploaded onto a case without being collected
- **WHEN** its provenance is read
- **THEN** every field SHALL be present and empty

### Requirement: Documents are selected by their provenance (REQ-DPV-002)

`CaseDocumentProvenance::select()` SHALL answer the documents of a case whose provenance matches
every field of a filter over `custodian`, `sourceSystem` and `source`; an empty filter SHALL answer
every document. A filter on any other field SHALL be refused, never answered with an empty
selection. `valuesOf()` SHALL answer the distinct non-empty values of one filter field, sorted, for
a filter's options.

#### Scenario: A batch taken by custodian and system
- **GIVEN** documents a (custodian A, files), b (custodian A, sharepoint) and c (custodian B, files)
- **WHEN** the documents are selected with custodian A and source system sharepoint
- **THEN** the selection SHALL be b alone

#### Scenario: An unknown filter field
- **WHEN** the documents are selected with a filter on `department`
- **THEN** the selection SHALL be refused
