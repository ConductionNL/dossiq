## ADDED Requirements

### Requirement: REQ-ZAK-020 The version history of a case file opens from the file

The Files tab of a case SHALL offer Versions on every file row, and that
action SHALL open the version history of the file that was clicked. The
history SHALL list each version with its moment, its author and its size,
and SHALL offer view, download and restore on each. dossiq SHALL store no
version chain of its own: the versions are the platform's, read and
restored through the Nextcloud Files versions API.

#### Scenario: Versions opens on the file the row named

- **GIVEN** a case whose folder holds `besluit.pdf` with three versions
- **WHEN** a handler opens the row menu on `besluit.pdf` and picks Versions
- **THEN** the panel SHALL list three versions of `besluit.pdf`
- **AND** restoring the oldest SHALL make it the current file in the folder

#### Scenario: The panel reads the file the browser clicked, not a row it was never given
@e2e exclude a prop-precedence branch with one gesture behind it; covered by the VersionHistoryPanel unit test

- **GIVEN** the panel is opened with a file id and with a row naming another file
- **WHEN** it reads the version history
- **THEN** it SHALL read the versions of the file id
- **AND** the row SHALL be used only when no file id arrived

#### Scenario: A panel handed no file refuses instead of showing an empty list
@e2e exclude a defensive branch with no reachable gesture; covered by the VersionHistoryPanel unit test, which mounts it with neither prop

- **GIVEN** the panel is opened with neither a file id nor a document row
- **WHEN** it renders
- **THEN** it SHALL say which file it could not find
- **AND** it SHALL NOT render an empty version list, because no versions and
  no file look identical to a reader

### Requirement: REQ-ZAK-021 A case file can be marked final or reclassified from its row

The Files tab SHALL offer mark final and change confidentiality on a file
row, and each act SHALL report what it changed. A file the act could not be
applied to SHALL be named rather than silently skipped, and an act that
found no document to work on SHALL refuse rather than report that it
changed nothing.

Acting on SEVERAL files at once is deliberately not required here. The
files browser carries no selection bar: the Files app's own list, its
selection bar and its inline rename are bound to the Files app's router and
cannot be mounted on a case page, which the component says of itself. A
bulk-actions declaration on that widget would therefore be read by nothing,
and a declared capability nobody can reach is the exact failure this change
exists to end. The whole case file as one download is REQ-ZAK-022.

#### Scenario: A file is marked final from its row

- **GIVEN** a case whose folder holds a file with a document record
- **WHEN** the handler picks Mark as final on that row and applies it
- **THEN** that document SHALL read final
- **AND** the other files in the folder SHALL be unchanged

#### Scenario: A file with no document record refuses rather than reporting nothing
@e2e exclude a timing branch that needs the projection listener held back; covered by the BulkDocumentActionDialog unit test

- **GIVEN** a file whose informatieobject record has not been written yet
- **WHEN** the handler marks it final
- **THEN** the act SHALL say no document record was found
- **AND** it SHALL NOT report a count of zero as a success

### Requirement: REQ-ZAK-022 The case file can be exported as one download

A case detail page SHALL offer Export dossier, and that action SHALL return
the case file as a zip carrying its manifest. A reader who may not read the
case SHALL be refused with a status and no bytes.

#### Scenario: A handler downloads the case file

- **GIVEN** a case with two documents and a reader who may read it
- **WHEN** that reader presses Export dossier
- **THEN** a zip SHALL download
- **AND** it SHALL contain both documents and a manifest naming them

#### Scenario: A reader who may not read the case gets nothing
@e2e exclude an authorization branch driven from the API; covered by the DossierExportController guard test

- **GIVEN** a reader with no access to the case
- **WHEN** that reader calls the export endpoint for it
- **THEN** the response SHALL be 403
- **AND** no part of the zip SHALL be written to the response

### Requirement: REQ-ZAK-023 A registered dialog that no page opens fails the suite

Every modal registered in the app registry SHALL be named by at least one
manifest action, or SHALL carry a written reason for being registered
without one. The check SHALL assert how many entries it examined, so a run
that matched nothing cannot report success.

#### Scenario: Retiring a tab that owned a dialog turns the suite red
@e2e exclude a build-time check over two source files; covered by the registry orphan unit test

- **GIVEN** a registered modal opened only by one tab's row actions
- **WHEN** that tab is removed from the manifest and the entry is left behind
- **THEN** the registry orphan test SHALL fail
- **AND** it SHALL name the modal that no page opens
