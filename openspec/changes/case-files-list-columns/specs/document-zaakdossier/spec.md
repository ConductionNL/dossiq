## ADDED Requirements

### Requirement: The case files list shows the document columns of the board (REQ-ZAK-COL-01)

The Files tab on a case SHALL offer, next to the name, the columns board DqZaakDocumenten names: document type, direction (incoming or outgoing), sender or recipient, and the review verdict. The board draws the document and its assessment by default, so document type, direction and sender or recipient SHALL sit in the Columns chooser, off until the handler turns them on, and the review verdict SHALL show only on a case type that reviews its documents. Values that are not on the file node SHALL come from the case's dossier listing, fetched once per folder, and an empty value SHALL show an empty cell.

#### Scenario: A received letter shows its sender and type

- **GIVEN** a case with a scanned letter whose listing says type "Aanvraag", direction incoming, sender "J. de Vries"
- **WHEN** the handler opens the Files tab and turns on Document type, Direction and Sender or recipient in the Columns chooser
- **THEN** the row SHALL show "Aanvraag", "Inkomend" and "J. de Vries" in their columns
- @e2e tests/e2e/case-files-columns.spec.ts

#### Scenario: One listing request per folder

- **GIVEN** a case folder with 40 files
- **WHEN** the Files tab renders
- **THEN** dossiq SHALL fetch the dossier listing for those files in one request
- @e2e exclude one request per folder is the `rowDataUrl` contract of nextcloud-vue CnFilesTab, tested there; dossiq's half (the declared url and the type catalogue read once) is asserted in vitest and PHPUnit
