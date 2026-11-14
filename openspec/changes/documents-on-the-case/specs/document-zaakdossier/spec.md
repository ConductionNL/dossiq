## ADDED Requirements

### Requirement: REQ-ZAK-011 The case page MUST list the dossier in a Documents tab

You see the documents of the case without leaving it. The `case-panels`
tabs widget on `CaseDetail` SHALL carry a tab Documents that renders widget
`case-documents` over the `zaakinformatieobject` rows of the case
(`case = @objectId`), newest registration first, with the columns Title,
Type, Status, Direction, Date and Author read from the linked
`informatieobject`. The tab SHALL sit before Files. The tab SHALL take a
dropped file through the metadata dialog and write an `informatieobject` and
a `zaakinformatieobject` for the case. Each row SHALL offer a Versions action
that opens the version history panel. The tab SHALL show the empty state
"No documents yet" when the case has no rows. The Files tab SHALL stay for
loose files.

#### Scenario: Documents visible on the case
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** a case with two linked informatieobjecten of different types
- **WHEN** you open the case page and pick the Documents tab
- **THEN** the list SHALL show both rows with title, type, status, direction, date and author
- **AND** the tab SHALL be reachable by its id `case-documents`

#### Scenario: A case without documents says so
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** a case with no zaakinformatieobject rows
- **WHEN** you open the Documents tab
- **THEN** the list SHALL show the empty state and the upload button

#### Scenario: Drop a file onto the tab
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** an open case on the Documents tab
- **WHEN** you drop a PDF, pick a type and confirm the metadata dialog
- **THEN** a new row SHALL appear with the file's title and the chosen type
- **AND** the saved zaakinformatieobject SHALL reference the case you were on

#### Scenario: Versions on a row
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** a document with two file versions on the case
- **WHEN** you open Versions on its row
- **THEN** the panel SHALL list both versions with a download action
- **AND** restore SHALL be disabled when the document status is final

### Requirement: REQ-ZAK-012 An informatieobject MUST carry keywords you can filter on

You tag a document and find it again. `informatieobject` SHALL carry
`keywords`, an array of strings of at most 64 characters each, optional,
facetable, edited through a tags widget in the metadata dialog and the data
form. The English property name is fixed by decisions D13; the Dutch label
Trefwoorden lives in `nl.json` only. The Documents tab SHALL offer a keyword
filter that narrows the list to rows whose linked `informatieobject` carries
the chosen keyword.

#### Scenario: Tag a document on upload
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** an open case on the Documents tab
- **WHEN** you upload a file and add the keywords bezwaar and bouwtekening
- **THEN** the saved informatieobject SHALL carry both keywords
- **AND** the row SHALL show them as chips

#### Scenario: Filter the list on a keyword
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** a case with one document tagged bezwaar and one without keywords
- **WHEN** you pick the keyword filter bezwaar
- **THEN** the list SHALL show only the tagged document
- **AND** clearing the filter SHALL show both

#### Scenario: The keyword property is English
@e2e exclude Schema property naming is checked by a PHPUnit test over the register JSON, not by a browser.

- **GIVEN** the imported `informatieobject` schema
- **WHEN** you read its properties
- **THEN** it SHALL carry `keywords` and SHALL NOT carry `trefwoorden`

### Requirement: REQ-ZAK-013 An informatieobject MUST carry a direction

You see whether a document came in, went out or stayed internal.
`informatieobject` SHALL carry `direction`, an enum of `incoming`,
`outgoing` and `internal`, optional, default `internal`, facetable, offered
in the metadata dialog beside the type. The Documents tab SHALL render it as
a column.

#### Scenario: Direction chosen on upload
@e2e tests/e2e/case-documents.spec.ts

- **GIVEN** an open case on the Documents tab
- **WHEN** you upload a file and pick the direction Incoming
- **THEN** the row SHALL show Incoming in the Direction column

#### Scenario: Direction defaults to internal
@e2e exclude The default of a schema property is asserted by a PHPUnit test over the register JSON.

- **GIVEN** an informatieobject saved without a direction
- **WHEN** you read it back
- **THEN** its direction SHALL be `internal`
