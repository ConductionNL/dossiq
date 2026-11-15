## ADDED Requirements

### Requirement: Files are added with a button or by dropping them on the list (REQ-CDV-22)

The case Files tab SHALL offer adding files as a primary button labelled "Add files" ("Bestanden toevoegen") beside the New menu, as the board `DqZaakDocumenten` draws it. Dragging files over the list SHALL show the drop state on the list itself, as `DqZaakDocumentenSlepen` draws it: a dashed frame with "Drop to add" ("Laat los om toe te voegen") centred over it. Dropping SHALL upload the files through the same path as the button. A line "Or drag files onto this list." ("Of sleep bestanden op deze lijst.") SHALL stand under the list, and no separate drop zone SHALL render. The drop target SHALL NOT be the only way in: the button stays, and the drop state SHALL be announced to screen readers through a polite live region, which after a drop says how many files were added.

#### Scenario: Add files is a button
@e2e tests/e2e/case-documents-on-the-case.spec.ts
- **GIVEN** a case with a folder
- **WHEN** the handler opens the Files tab
- **THEN** a primary button "Add files" SHALL be shown beside the New menu
- **AND** the line "Or drag files onto this list." SHALL stand under the list

#### Scenario: Dragging files over the list shows the drop state
@e2e tests/e2e/case-documents-on-the-case.spec.ts
- **GIVEN** the Files tab is open
- **WHEN** files are dragged over the list
- **THEN** a dashed frame with "Drop to add" SHALL cover the list
- **AND** a polite live region SHALL say "Drop to add"
- **WHEN** the drag leaves the list
- **THEN** the frame SHALL go

#### Scenario: A drop uploads like the button
@e2e exclude Asserted in nextcloud-vue's CnFilesBrowser tests (the drop and the picker share `uploadFiles`, one DAV PUT per file); the e2e above leaves the case folder untouched by not dropping.
- **GIVEN** the Files tab is open
- **WHEN** two files are dropped on the list
- **THEN** both SHALL be uploaded into the case folder with the same request the button makes
- **AND** the live region SHALL say "2 files added"
