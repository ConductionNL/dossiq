## MODIFIED Requirements

### Requirement: REQ-ZAK-001 Zaak objects MUST support linked documents via ZGW informatieobject and zaakinformatieobject

Every document MUST be represented as both a Nextcloud file stored in the case's own folder at
`Open Registers/{Register Title} Register/{caseUuid}/` AND an `informatieobject` register object
that references it through `fileId` and carries the following ZGW DRC-compliant fields:
`titel`, `bestandsnaam`, `bestandsomvang`, `formaat`, `vertrouwelijkheidaanduiding`,
`auteur`, `status`, `informatieobjecttype`, `creatiedatum`, `bronorganisatie`, `taal`,
`beschrijving`, `link`, `integriteit.algoritme`, `integriteit.waarde`, `vergrendeldOp`, `fileId`.
The file is on the case; the record is its projection (see `document-projection`). The link
between the zaak and the informatieobject MUST be a separate `zaakinformatieobject` join object
with fields: `zaak`, `informatieobject`, `aardRelatieWeergave`, `registratiedatum`, so a single
document can be linked to multiple cases while its file lives in one case's folder.

#### Scenario: REQ-ZAK-001a Upload creates informatieobject and zaakinformatieobject
- **GIVEN** zaak `vergunning-2026-0042` exists in register `Vergunningen`
- **WHEN** a user uploads `aanvraagformulier.pdf` into the case through the Files tab or through `ZaakdossierService.uploadDocument()`
- **THEN** the file MUST be stored at `Open Registers/Vergunningen Register/{caseUuid}/aanvraagformulier.pdf`, attached to the case object
- **AND** an `informatieobject` register object MUST exist with `titel`, `bestandsnaam`,
  `formaat`, `auteur`, `creatiedatum`, `bronorganisatie`, `taal` (`nld`) and `fileId` populated
- **AND** a `zaakinformatieobject` join object MUST exist with `zaak` → the case and `informatieobject` → the record

#### Scenario: REQ-ZAK-001b Same informatieobject linked to two cases without duplication
- **GIVEN** an `informatieobject` whose file lives in case A's folder
- **WHEN** a `zaakinformatieobject` joins it to case B
- **THEN** there MUST be exactly one file and one `informatieobject`, and two join objects
- **AND** case B's Files tab MUST show the document as a linked row naming case A

#### Scenario: REQ-ZAK-001c Unlink preserves informatieobject
- **GIVEN** an `informatieobject` joined to cases A and B, its file in A's folder
- **WHEN** the join to B is deleted
- **THEN** the `informatieobject`, its file and the join to A MUST be unchanged

### Requirement: REQ-ZAK-004 The zaakdossier view MUST render documents grouped by informatieobjecttype

> Retired from the case page on 2026-09-13 (Ruben): the Files tab holds the case folder as a files browser on the Files app's primitives, and the dossier list, its upload dialog and their e2e suite left the page with it. The informatieobject API, the register and `ZaakdossierController` stay. Since `documents-live-on-the-case` the documents ARE the files in that browser: each row is a document, its Document properties action edits the record, and a document joined from another case renders as a linked row. The grouped dossier view described below is no longer rendered anywhere; the scenarios are kept as the record of it and the Files tab answers to `case-dashboard-view` and `document-projection` instead.

`DossierTab.vue` MUST render the complete dossier for a zaak, grouping documents in collapsible
sections per `informatieobjecttype` via `DossierGroup.vue`. `DocumentRow.vue` MUST display
for each document: thumbnail (Nextcloud preview API at `/index.php/core/preview?fileId={id}&x=64&y=64`),
titel, status badge (orange=concept, green=definitief, grey=gearchiveerd),
`vertrouwelijkheidaanduiding` badge, `creatiedatum`, `auteur`, `bestandsomvang`, and an action
menu (open, share, publish, version history, delete-if-concept). The tab header MUST show a
count badge (e.g., "Dossier (8)").

#### Scenario: REQ-ZAK-004a Dossier groups documents by type with count badge
@e2e exclude The grouped dossier view left the page on 2026-09-13; the Files tab is the document surface and is asserted under `case-dashboard-view` and `document-projection`.

- **GIVEN** a zaak with 8 documents of 3 types
- **WHEN** the dossier tab renders
- **THEN** documents are grouped in 3 collapsible sections and the header reads "Dossier (8)"

#### Scenario: REQ-ZAK-004b Empty dossier shows upload CTA with drag-and-drop zone
@e2e exclude Retired with the grouped dossier view on 2026-09-13; the empty case folder's own empty state in the Files tab is asserted under `case-dashboard-view`.

- **GIVEN** a zaak with no documents
- **WHEN** the dossier tab renders
- **THEN** an upload call to action with a drop zone renders

#### Scenario: REQ-ZAK-004c Sort and filter controls work per column
@e2e exclude Retired with the grouped dossier view on 2026-09-13; the files browser sorts by name, size and modified.

- **GIVEN** a dossier with documents
- **WHEN** the handler sorts or filters a column
- **THEN** the list follows

### Requirement: REQ-ZAK-005 Upload MUST present a metadata dialog and require informatieobjecttype and vertrouwelijkheidaanduiding

Since `documents-live-on-the-case` a drop in the Files tab stores the file at once with the
derived defaults of `document-projection` REQ-DPR-001; the metadata dialog is the Document
properties action on the row, where `informatieobjecttype` and `vertrouwelijkheidaanduiding` MUST
be present before the record can leave `concept`. The dialog MUST still be the surface that sets
both, and `ZaakdossierService.uploadDocument()` MUST still require both when called with a
metadata payload.

#### Scenario: REQ-ZAK-005a Drag-drop triggers metadata dialog before upload
@e2e tests/e2e/case-documents-on-the-case.spec.ts

- **GIVEN** a case's Files tab
- **WHEN** the handler drops a file
- **THEN** the file MUST be stored and listed before any dialog opens
- **AND** the row's Document properties action MUST open the metadata dialog on the file's record with the derived defaults filled in

#### Scenario: REQ-ZAK-005b Per-file upload progress with shared metadata
@e2e exclude The upload runs through the files browser, which shows a progress row per file; shared metadata is the case type's document defaults, asserted under `document-projection`.

- **GIVEN** three files dropped together
- **WHEN** they upload
- **THEN** each shows its own progress and each record carries the same defaults

#### Scenario: REQ-ZAK-005c File validation blocks executable uploads
- **GIVEN** a handler drops `setup.exe` into the case folder
- **WHEN** the projection runs
- **THEN** no `informatieobject` MUST be created for it and the file MUST be removed with a notice naming the reason

### Requirement: REQ-ZAK-009 ZGW DRC-compatible download MUST support HTTP Range requests for resumable streaming

The DRC download endpoint MUST stream the file the record's `fileId` names, wherever that file
lives: the case's folder for a document on the case, the record's own folder for an API-created
document not yet joined to a case. Range handling and clearance gating are unchanged.

#### Scenario: REQ-ZAK-009a ZGW DRC endpoint streams large file with Range support
- **GIVEN** a document whose file is in its case's folder
- **WHEN** a client requests `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` with a Range header
- **THEN** the response MUST be 206 with the requested bytes of that file

#### Scenario: REQ-ZAK-009b Download blocked when user lacks clearance
- **GIVEN** a document with `vertrouwelijkheidaanduiding` geheim
- **WHEN** a user below that clearance requests the download
- **THEN** the response MUST be 403, whichever folder the file is in
