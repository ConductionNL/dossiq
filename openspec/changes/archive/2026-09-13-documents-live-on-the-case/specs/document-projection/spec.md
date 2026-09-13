## Purpose

Keeps a case's ZGW `informatieobject` records in step with the files in the case's own folder, so a document is a normal file on the case first and a DRC record second: created from the file, refreshed from it, retired with it, and moved into the case when the API created it before any case was known.

## ADDED Requirements

### Requirement: A file in the case folder is a document (REQ-DPR-001)

A file that lands in a case's folder, by any route (the Files tab, the Files app, WebDAV, the request-to-case bridge), SHALL have an `informatieobject` record within the same request that stores it, and a `zaakinformatieobject` join to that case. The record SHALL carry derived defaults: `titel` from the file name without its extension, `bestandsnaam`, `formaat` from the node's MIME type, `bestandsomvang` from its size, `auteur` the display name of the writing user, `creatiedatum` the day of the write, `taal` `nld`, `status` `concept`, `informatieobjecttype` and `vertrouwelijkheidaanduiding` from the case type's document defaults when it declares them, and `fileId` the node's file id. One file SHALL have at most one record: a second write, a chunked upload's final assembly and a version restore SHALL refresh `bestandsomvang`, `formaat` and `integriteit` on the existing record and create nothing.

#### Scenario: A drop in the Files tab creates the record
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a case whose case type declares `informatieobjecttype` Aanvraag and `vertrouwelijkheidaanduiding` zaakvertrouwelijk as its document defaults
- **WHEN** a handler drops `aanvraagformulier.pdf` into the case's folder through the Files tab
- **THEN** an `informatieobject` SHALL exist with `titel` aanvraagformulier, `bestandsnaam` aanvraagformulier.pdf, `formaat` application/pdf, `auteur` the handler's display name, `status` concept, `informatieobjecttype` Aanvraag, `vertrouwelijkheidaanduiding` zaakvertrouwelijk and `fileId` the node's id
- **AND** a `zaakinformatieobject` SHALL join it to the case with `registratiedatum` the moment of the drop

#### Scenario: A second write refreshes, never duplicates
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a file in the case folder that already has a record
- **WHEN** the file is written again with different content
- **THEN** the record's `bestandsomvang`, `formaat` and `integriteit` SHALL be refreshed
- **AND** the count of `informatieobject` records with that `fileId` SHALL still be one

#### Scenario: A folder is not a document
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **WHEN** a handler creates a sub-folder in the case folder
- **THEN** no `informatieobject` SHALL be created for it
- **AND** a file dropped into that sub-folder SHALL be a document of the case all the same

#### Scenario: A file outside any case folder is left alone
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **WHEN** a file is written under a register folder that is not a case's folder
- **THEN** no record SHALL be created and nothing SHALL be logged above debug level

### Requirement: The record follows the file (REQ-DPR-002)

A rename of the file SHALL update `bestandsnaam` and, when `titel` still equals the previous name without extension, `titel`. A delete of the file SHALL retire the record and every join to it: the record's `status` becomes gearchiveerd when it was definitief, and the record is deleted when it was concept. A move of the file out of the case folder SHALL be treated as a delete on that case and, when the destination is another case's folder, as a drop on the destination case for the same record.

#### Scenario: Rename follows
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **GIVEN** a document with `titel` scan and `bestandsnaam` scan.pdf
- **WHEN** the handler renames the file to bouwtekening.pdf
- **THEN** `bestandsnaam` SHALL read bouwtekening.pdf and `titel` SHALL read bouwtekening

#### Scenario: A hand-set title survives a rename
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **GIVEN** a document whose `titel` was edited to Bouwtekening begane grond
- **WHEN** the file is renamed
- **THEN** `titel` SHALL be unchanged

#### Scenario: Deleting a concept document deletes the record
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **GIVEN** a concept document joined to one case
- **WHEN** the file is deleted
- **THEN** the `informatieobject` and its `zaakinformatieobject` SHALL be gone

#### Scenario: Deleting a definitief document archives the record
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **GIVEN** a definitief document
- **WHEN** the file is deleted
- **THEN** the record SHALL read `status` gearchiveerd and its joins SHALL be gone
- **AND** the DRC API SHALL still list the record

#### Scenario: A move between cases re-homes the document
@e2e exclude A projection rule with no page of its own; pinned in tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php.

- **GIVEN** a document in case A's folder
- **WHEN** the handler moves the file into case B's folder
- **THEN** the join to A SHALL be gone, a join to B SHALL exist and the record SHALL still carry the same `fileId`

### Requirement: The API-first document moves into its case on join (REQ-DPR-003)

An `informatieobject` created through the DRC API with `inhoud` before any `zaakinformatieobject` names a case SHALL keep its file in the record's own folder. When the first `zaakinformatieobject` for it is created, the file SHALL move into that case's folder and the record SHALL keep its `fileId`. A later join to another case SHALL not move the file.

#### Scenario: The first join moves the file
@e2e exclude The ZGW APIs take a JWT the e2e suite has no helper for; pinned in tests/Unit/Service/Zaakdossier/DocumentJoinHomingTest.php and DocumentProjectionServiceTest.php (homeDocument).

- **GIVEN** an `informatieobject` created through the DRC API with `inhoud`, its file in the record's own folder
- **WHEN** a `zaakinformatieobject` joins it to case A
- **THEN** the file SHALL be in case A's folder and `fileId` SHALL be unchanged
- **AND** the record's own folder SHALL no longer hold it

#### Scenario: A second join leaves the file where it is
@e2e exclude The ZGW APIs take a JWT the e2e suite has no helper for; pinned in tests/Unit/Service/Zaakdossier/DocumentJoinHomingTest.php and DocumentProjectionServiceTest.php (homeDocument).

- **GIVEN** that document joined to case A
- **WHEN** a `zaakinformatieobject` joins it to case B
- **THEN** the file SHALL still be in case A's folder

#### Scenario: A join to a case without a folder is refused
@e2e exclude The ZGW APIs take a JWT the e2e suite has no helper for; pinned in tests/Unit/Service/Zaakdossier/DocumentJoinHomingTest.php and DocumentProjectionServiceTest.php (homeDocument).

- **GIVEN** a case object whose folder cannot be resolved
- **WHEN** a `zaakinformatieobject` joins a document to it
- **THEN** the join SHALL be refused with a 422 naming the case
- **AND** the document's file SHALL not have moved

### Requirement: A linked document shows as linked (REQ-DPR-004)

A case whose join points at a document whose file lives in another case's folder SHALL show that document in its Files tab as a linked row: the file name, its type, the case that holds the file, and open and download actions. The row SHALL not offer rename, delete or move, and the linked case's name SHALL be a link to that case.

#### Scenario: Case B sees A's document as a linked row
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a document whose file is in case A's folder, joined to case B as well
- **WHEN** a handler opens case B's Files tab
- **THEN** the document SHALL render as a linked row naming case A
- **AND** the row SHALL offer open and download and nothing that changes the file

#### Scenario: Unlinking B leaves A's file alone
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **WHEN** the handler removes the document from case B
- **THEN** only the join to B SHALL be gone and the file in A's folder SHALL be untouched

### Requirement: Existing documents are moved into their case (REQ-DPR-005)

A repair step SHALL move every existing document's file into the folder of the case that owns it, the owning case being the case of its earliest `zaakinformatieobject` by `registratiedatum`, and SHALL refresh `fileId` when the move changed it. The step SHALL run once per version behind a persisted version key, SHALL be idempotent, and SHALL leave a document whose file cannot be found untouched, naming it in the log.

#### Scenario: The migration moves a document once
@e2e exclude An upgrade step, not a page; pinned in tests/Unit/Repair/MoveDocumentsIntoCaseFoldersTest.php.

- **GIVEN** three documents whose files sit in their records' own folders, each joined to one case
- **WHEN** the repair step runs
- **THEN** each file SHALL be in its case's folder and each record's `fileId` SHALL resolve to it
- **AND** running the step again SHALL move nothing

#### Scenario: A missing file is reported, not invented
@e2e exclude An upgrade step, not a page; pinned in tests/Unit/Repair/MoveDocumentsIntoCaseFoldersTest.php.

- **GIVEN** a record whose `fileId` resolves to no node
- **WHEN** the repair step runs
- **THEN** the record SHALL be unchanged and the log SHALL name it
