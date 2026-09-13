## MODIFIED Requirements

### Requirement: The Files tab is the case's document surface

The Files tab SHALL render the case folder as a files browser on the Files app's primitives, and
nothing else: no section heading and no second list. Every file in it is a document of the case
(`document-projection`). Each row SHALL offer a Document properties action that opens the
metadata dialog on the file's `informatieobject`; a document joined to this case from another
case's folder SHALL render as a linked row after the folder's own files, naming the case that
holds the file, with open and download only.

#### Scenario: Files is the case folder and nothing else
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a case page
- **WHEN** the handler opens the Files tab
- **THEN** the case folder SHALL render as a files browser, with crumbs from the user's files root down to the case folder
- **AND** no section heading and no second list SHALL render in the tab

#### Scenario: A row edits its document's properties
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a file in the case folder
- **WHEN** the handler opens the row's actions and chooses Document properties
- **THEN** the metadata dialog SHALL open on that file's `informatieobject` with title, type, confidentiality, author and status filled in
- **AND** saving SHALL update the record and leave the file untouched

#### Scenario: A document from another case is a linked row
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a document whose file lives in case A's folder and is joined to case B
- **WHEN** the handler opens case B's Files tab
- **THEN** the document SHALL render as a linked row below the folder's files, naming case A as a link
- **AND** the row SHALL offer open and download and no rename, delete or move
