## ADDED Requirements

### Requirement: The case files list shows the document columns of the board (REQ-ZAK-COL-01)

The Files tab on a case SHALL show, next to name, size and modified, the columns board DqZaakDocumenten draws: document type, direction (incoming or outgoing), sender or recipient, and the review verdict. Values that are not on the file node SHALL come from the case's document projection, fetched once per folder, and an empty value SHALL show the table's empty label.

#### Scenario: A received letter shows its sender and type

- **GIVEN** a case with a scanned letter whose projection says type "Aanvraag", direction incoming, sender "J. de Vries"
- **WHEN** the handler opens the Files tab
- **THEN** the row SHALL show "Aanvraag", "Inkomend" and "J. de Vries" in their columns
- @e2e tests/e2e/case-files-columns.spec.ts

#### Scenario: One projection request per folder

- **GIVEN** a case folder with 40 files
- **WHEN** the Files tab renders
- **THEN** dossiq SHALL fetch the projection for those files in one request
- @e2e exclude request counting is asserted on the rowData provider in vitest
