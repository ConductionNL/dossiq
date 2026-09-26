---
status: done
---

# document-zaakdossier Specification

## Purpose
Manages the document dossier of a zaak using ZGW-compliant informatieobject and zaakinformatieobject records, so a document can be linked to multiple cases without duplication and follows a forward-only concept → definitief → gearchiveerd lifecycle. Access to every document is gated by its vertrouwelijkheidaanduiding at the service layer, and the dossier view groups documents by type with upload (metadata dialog), version history, full-text search, bulk ZIP export with manifest, and a range-capable ZGW DRC download endpoint. A repair step back-fills ZGW metadata for pre-existing linked files.

## Requirements

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

### Requirement: REQ-ZAK-002 Informatieobjecten MUST follow the ZGW status lifecycle concept → definitief → gearchiveerd

The `status` field MUST enforce a one-way, forward-only lifecycle. `ZaakdossierService.transitionStatus()`
MUST validate each transition and:
- Set `vergrendeldOp` to the current timestamp when status transitions to `definitief`
- Block `CreateFileHandler.saveFile()` upsert (new version upload) when status = `definitief`
- Return HTTP 409 from `DeleteFileHandler` for `definitief` documents
- Allow reverse transitions from `definitief` to any earlier status: NEVER (HTTP 400)

#### Scenario: REQ-ZAK-002a Transition concept → definitief locks the file

- **GIVEN** informatieobject `besluit.pdf` with `status` = `concept`
- **WHEN** `ZaakdossierService.transitionStatus(id, 'definitief')` is called
- **THEN** `status` MUST change to `definitief`
- **AND** `vergrendeldOp` MUST be set to the current datetime
- **AND** a subsequent upload of a new version MUST return HTTP 409 with message
  "Definitieve documenten kunnen niet worden gewijzigd"

@e2e exclude Status-lifecycle enforcement and the HTTP 409 on re-upload are server contracts asserted in tests/Unit/Service/ZaakdossierServiceTest and in the Newman collection; the UI has no control that can attempt a blocked version upload.

#### Scenario: REQ-ZAK-002b Reverse transition definitief → concept is rejected

- **GIVEN** informatieobject `besluit.pdf` with `status` = `definitief`
- **WHEN** any actor calls `transitionStatus(id, 'concept')` via `PATCH /api/informatieobjecten/{id}/status`
- **THEN** the API MUST return HTTP 400 Bad Request
- **AND** the response body MUST indicate the invalid transition
- **AND** the `status` and `vergrendeldOp` MUST remain unchanged

@e2e exclude Reverse-transition rejection is an API contract (HTTP 400 from `PATCH /api/informatieobjecten/{id}/status`) asserted in the Newman collection; the UI never offers the backwards transition, so no UI flow can exercise it.

#### Scenario: REQ-ZAK-002c Deletion of definitief document is rejected

- **GIVEN** informatieobject `besluit.pdf` with `status` = `definitief`
- **WHEN** a user attempts to delete the document via `DeleteFileHandler` or the dossier UI
- **THEN** the deletion MUST be rejected with HTTP 409 Conflict
- **AND** the informatieobject record MUST remain intact

@e2e exclude HTTP 409 from `DeleteFileHandler` for definitief documents is a backend guard asserted in tests/Unit/Service/ZaakdossierServiceTest + Newman; the guard must hold regardless of whether the UI renders a delete affordance.

#### Scenario: REQ-ZAK-002d Transition definitief → gearchiveerd is permitted

- **GIVEN** informatieobject with `status` = `definitief`
- **WHEN** `transitionStatus(id, 'gearchiveerd')` is called (e.g., by the archival process)
- **THEN** the status MUST update to `gearchiveerd`
- **AND** the transition MUST be recorded in the OpenRegister audit trail

@e2e exclude Archival transition is driven by a background/archival process, not a UI control, and the audit-trail write is an OpenRegister side effect; asserted in tests/Unit/Service/ZaakdossierServiceTest.

---

### Requirement: REQ-ZAK-003 Access to informatieobjecten MUST be gated by vertrouwelijkheidaanduiding

`InformatieobjectAccessGuard` MUST enforce the ZGW confidentiality hierarchy
(ordered lowest → highest: `openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`,
`vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`) on every read, share, publish,
and download operation. Guards MUST be checked at the service layer, not only in the UI.

#### Scenario: REQ-ZAK-003a User below clearance level cannot read a document

- **GIVEN** informatieobject has `vertrouwelijkheidaanduiding` = `geheim`
- **AND** the requesting user's clearance is `vertrouwelijk` (two levels below)
- **WHEN** `InformatieobjectAccessGuard.canRead(user, informatieobject)` is evaluated
- **THEN** the guard MUST return `false`
- **AND** the API MUST respond with HTTP 403 Forbidden
- **AND** the document MUST NOT appear in the dossier listing for that user

@e2e exclude Clearance guard (`InformatieobjectAccessGuard::canRead`) is asserted in tests/Unit/Service/InformatieobjectAccessGuardTest; exercising it through the UI would need two seeded users at different clearance levels, which the Playwright environment does not provision.

#### Scenario: REQ-ZAK-003b Filtered dossier listing respects clearance

- **GIVEN** a dossier with 10 documents at various vertrouwelijkheidaanduiding levels
- **AND** the user has clearance `intern`
- **WHEN** `GET /api/cases/{caseId}/dossier` is called
- **THEN** `InformatieobjectAccessGuard.filterDossierForUser()` MUST remove all documents
  with vertrouwelijkheidaanduiding above `intern` from the response
- **AND** documents with `openbaar`, `beperkt_openbaar`, or `intern` MUST be returned

@e2e exclude Listing-filter matrix (`filterDossierForUser`) is asserted per clearance level in tests/Unit/Service/InformatieobjectAccessGuardTest; a UI check could only observe one row count, not the filter contract.

#### Scenario: REQ-ZAK-003c Public share rejected for confidential documents

- **GIVEN** informatieobject has `vertrouwelijkheidaanduiding` = `vertrouwelijk`
- **WHEN** a user attempts to create a public share link for this document
- **THEN** `InformatieobjectAccessGuard.canPublish(informatieobject)` MUST return `false`
- **AND** the share creation MUST be blocked with an appropriate error message

@e2e exclude Public-share refusal (`canPublish`) is a service-layer guard asserted in tests/Unit/Service/InformatieobjectAccessGuardTest; share creation runs through Nextcloud's own sharing UI, outside the dossiq e2e surface.

#### Scenario: REQ-ZAK-003d Default vertrouwelijkheidaanduiding from informatieobjecttype

- **GIVEN** informatieobjecttype `intern-advies` has default `vertrouwelijkheidaanduiding` = `intern`
- **WHEN** a user uploads a document of this type without specifying a classification
- **THEN** the informatieobject MUST receive `vertrouwelijkheidaanduiding` = `intern`
- **AND** the user MAY override to a more restrictive level but NOT to a less restrictive one

@e2e exclude Default-classification inheritance from informatieobjecttype and the one-way override rule are resolved server-side on create; asserted in tests/Unit/Service/ZaakdossierServiceTest + Newman.

---

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

### Requirement: REQ-ZAK-006 Version history MUST be surfaced via Nextcloud Files versions API

`VersionHistoryPanel.vue` MUST fetch document versions via
`/dav/versions/{userId}/versions/{fileId}` and display for each version:
version number, timestamp, and uploader. Each version MUST be downloadable.
Restore action MUST be disabled when informatieobject status = `definitief`.

#### Scenario: REQ-ZAK-006a Concept document version history shows restore

- **GIVEN** informatieobject `aanvraag.pdf` with `status` = `concept` has 3 versions
- **WHEN** the user opens VersionHistoryPanel
- **THEN** all 3 versions MUST be listed with version number, timestamp, and uploader
- **AND** each version MUST have a "Downloaden" link
- **AND** versions 1 and 2 MUST have an active "Herstellen" button

@e2e exclude The blocker has MOVED and shrunk, which is why this is not simply "#764". A document with more than one Nextcloud file version was the missing piece, and it is missing no longer: `case-documents.spec.ts#seedVersionedDocument` writes the file twice and its docblock carries the storage path. What is left is one leg on the DRAFT side. `case-documents.spec.ts` opens the version panel for a `final` document and proves REQ-ZAK-006b's refusal; the same panel on a `draft` document, asserting the Herstellen button is ACTIVE and that pressing it issues the MOVE, is the honest home for this scenario. Restore the citation there once that leg exists and its enabled-restore assertion has been mutation checked, rather than on a body that never runs.

#### Scenario: REQ-ZAK-006b Restore is disabled for definitief documents

- **GIVEN** informatieobject `besluit.pdf` with `status` = `definitief` has 2 versions
- **WHEN** the user opens VersionHistoryPanel
- **THEN** both versions MUST be listed
- **AND** the "Herstellen" button MUST be visibly disabled on all versions
- **AND** hovering the disabled button MUST show "Definitieve documenten kunnen niet worden gewijzigd"

---

### Requirement: REQ-ZAK-007 Full-text search MUST be available within the dossier scope

`TextExtractionService` MUST run asynchronously via `FileTextExtractionJob` after each upload.
Extracted text MUST be indexed for search within the dossier. Dossier-scoped content and
metadata search MUST be supported via `FileSearchController`.

#### Scenario: REQ-ZAK-007a Upload triggers async text extraction

- **GIVEN** a user uploads `aanvraagformulier.pdf` containing readable text
- **WHEN** the upload completes
- **THEN** `TextExtractionService` MUST schedule asynchronous text extraction via
  `FileTextExtractionJob` or `CronFileTextExtractionJob`
- **AND** the document MUST become full-text searchable once extraction completes
- **AND** the upload response MUST not wait for extraction to finish

@e2e exclude Asynchronous job scheduling (`FileTextExtractionJob`) completes outside the request the browser observes; asserted in tests/Unit/Service/ZaakdossierServiceTest and by the background-job unit tests.

#### Scenario: REQ-ZAK-007b Dossier search returns only matching documents

- **GIVEN** a dossier with 25 documents, 3 containing the phrase "brandveiligheidsplan"
- **WHEN** the user searches "brandveiligheidsplan" in the dossier search bar
- **THEN** exactly the 3 matching documents MUST be returned with highlighted snippets
- **AND** documents not in this dossier MUST NOT appear in results

@e2e exclude Dossier-scoped search relevance depends on a 25-document seeded corpus with extracted full text; asserted at the API layer in tests/newman/document-zaakdossier.postman_collection.json.

---

### Requirement: REQ-ZAK-008 Bulk operations MUST support ZIP export with manifest, bulk status transition, and bulk metadata update

`BulkActionsBar.vue` MUST appear when the user selects multiple documents. `ZipManifestBuilder`
MUST produce a streaming ZIP (via ZipStream) containing selected documents in
informatieobjecttype sub-folders plus a `manifest.csv` with columns:
`bestandsnaam`, `titel`, `informatieobjecttype`, `status`, `vertrouwelijkheidaanduiding`,
`creatiedatum`, `auteur`. Documents above the caller's clearance MUST be excluded from the ZIP.

#### Scenario: REQ-ZAK-008a ZIP export includes manifest.csv and type sub-folders

- **GIVEN** a dossier with 8 documents across 3 informatieobjecttype values
- **WHEN** the user selects all 8 and clicks "Download selectie als ZIP"
- **THEN** `POST /api/cases/{caseId}/dossier/zip` MUST stream a ZIP without loading all files
  into memory simultaneously
- **AND** the ZIP MUST contain sub-folders per informatieobjecttype
- **AND** the ZIP MUST contain `manifest.csv` with all 8 rows populated

#### Scenario: REQ-ZAK-008b ZIP excludes documents above caller clearance

- **GIVEN** a dossier has 8 documents, 2 of which have `vertrouwelijkheidaanduiding` = `geheim`
- **AND** the caller's clearance is `vertrouwelijk`
- **WHEN** a full-dossier ZIP is requested
- **THEN** the ZIP MUST contain only 6 documents
- **AND** `manifest.csv` MUST contain only those 6 rows

@e2e exclude ZIP contents and manifest rows are inspected in tests/Unit/Service/ZipManifestBuilderTest; the browser receives an opaque streamed download that Playwright cannot open to count clearance-excluded entries.

#### Scenario: REQ-ZAK-008c Bulk status transition returns per-document result

- **GIVEN** the user selects 5 concept documents and clicks "Markeer als definitief"
- **WHEN** `POST /api/informatieobjecten/bulk/status` is called with all 5 IDs and `definitief`
- **THEN** `ZaakdossierService.bulkTransitionStatus()` MUST return a per-ID success/failure list
- **AND** the UI MUST display which transitions succeeded and which failed with reasons

---

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

### Requirement: REQ-ZAK-010 Existing linked files MUST be back-filled with ZGW informatieobject metadata

`BackfillInformatieobjectMetadata` MUST be an idempotent repair step registered via
`info.xml` `repair-steps`. It MUST iterate all existing object folders, create `informatieobject`
records with defaults (`status` = `concept`, `vertrouwelijkheidaanduiding` = `intern`,
`auteur` from file owner, `integriteit.waarde` computed via `hash_file('sha256', $path)`),
and create `zaakinformatieobject` joins for already-linked files. Files that already have an
informatieobject MUST be skipped.

#### Scenario: REQ-ZAK-010a Back-fill creates informatieobject for pre-existing file

- **GIVEN** a Nextcloud file `vergunning.pdf` linked to case `vergunning-1` without an
  informatieobject record
- **WHEN** `BackfillInformatieobjectMetadata::run()` executes
- **THEN** an `informatieobject` record MUST be created with `status` = `concept`,
  `vertrouwelijkheidaanduiding` = `intern`, `auteur` = file owner display name,
  and `integriteit.waarde` = SHA-256 hash of the file content
- **AND** a `zaakinformatieobject` join MUST link the informatieobject to `vergunning-1`

@e2e exclude `BackfillInformatieobjectMetadata` is an `info.xml` repair step that runs at install/upgrade, never from a UI action; asserted in the repair-step unit tests.

#### Scenario: REQ-ZAK-010b Back-fill is idempotent on re-run

- **GIVEN** `BackfillInformatieobjectMetadata` has already run and created informatieobject
  records for all existing files
- **WHEN** the repair step is executed a second time
- **THEN** NO new `informatieobject` or `zaakinformatieobject` records MUST be created
- **AND** existing records MUST remain unchanged

@e2e exclude Repair-step idempotence is proven by running the step twice and comparing record counts — a PHPUnit-level assertion (repair-step unit tests); there is no UI that re-runs a repair step.

### Requirement: REQ-ZAK-020 The version history of a case file opens from the file

The Files tab of a case SHALL offer Versions on every file row, and that
action SHALL open the version history of the file that was clicked. The
history SHALL list each version with its moment, its author and its size,
and SHALL offer download and restore on each. A property the server did
not send SHALL be shown as absent rather than as a value: an author
nobody recorded reads Unknown, and a size nobody sent is left off the
line rather than written as nought bytes.

Opening a version in a viewer is deliberately not required. Download is
how a reader opens an old version, because the Nextcloud viewer resolves
a file by its node and a version is not one. A separate View that only
downloaded would be a second name for one act.

dossiq SHALL store no version chain of its own: the versions are the
platform's, read and restored through the Nextcloud Files versions API.

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
