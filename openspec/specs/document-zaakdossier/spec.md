---
status: done
---

# document-zaakdossier Specification

## Purpose
Manages the document dossier of a zaak using ZGW-compliant informatieobject and zaakinformatieobject records, so a document can be linked to multiple cases without duplication and follows a forward-only concept → definitief → gearchiveerd lifecycle. Access to every document is gated by its vertrouwelijkheidaanduiding at the service layer, and the dossier view groups documents by type with upload (metadata dialog), version history, full-text search, bulk ZIP export with manifest, and a range-capable ZGW DRC download endpoint. A repair step back-fills ZGW metadata for pre-existing linked files.
## Requirements
### Requirement: REQ-ZAK-001 Zaak objects MUST support linked documents via ZGW informatieobject and zaakinformatieobject

Every uploaded document MUST be represented as both a Nextcloud file stored at
`Open Registers/{Register Title} Register/{objectUuid}/` AND an `informatieobject` register
object carrying the following ZGW DRC-compliant fields:
`titel`, `bestandsnaam`, `bestandsomvang`, `formaat`, `vertrouwelijkheidaanduiding`,
`auteur`, `status`, `informatieobjecttype`, `creatiedatum`, `bronorganisatie`, `taal`,
`beschrijving`, `link`, `integriteit.algoritme`, `integriteit.waarde`, `vergrendeldOp`, `fileId`.

The link between the zaak and the informatieobject MUST be a separate `zaakinformatieobject`
join object with fields: `zaak`, `informatieobject`, `aardRelatieWeergave`, `registratiedatum`.
This join pattern allows a single document to be linked to multiple cases without duplication.

#### Scenario: REQ-ZAK-001a Upload creates informatieobject and zaakinformatieobject

- **GIVEN** zaak `vergunning-2026-0042` exists in register `Vergunningen`
- **WHEN** a user uploads `aanvraagformulier.pdf` via `ZaakdossierService.uploadDocument()`
- **THEN** the file MUST be stored via `CreateFileHandler.addFile()` at
  `Open Registers/Vergunningen Register/{uuid}/aanvraagformulier.pdf`
- **AND** an `informatieobject` register object MUST be created with `titel`, `bestandsnaam`,
  `formaat`, `auteur`, `creatiedatum`, `bronorganisatie`, `taal` (`nld`) populated
- **AND** a `zaakinformatieobject` join object MUST be created with `zaak` →
  `vergunning-2026-0042`, `informatieobject` → new informatieobject UUID,
  `registratiedatum` → current timestamp
- **AND** the file MUST receive system tags `object:{uuid}` and `doctype:{type}` via `TaggingHandler`

@e2e exclude Service-layer contract (`ZaakdossierService::uploadDocument` + `CreateFileHandler`/`TaggingHandler` wiring) asserted in tests/Unit/Service/ZaakdossierServiceTest and at the API layer in tests/newman/document-zaakdossier.postman_collection.json; there is no UI assertion that can observe the join-object and system-tag side effects.

#### Scenario: REQ-ZAK-001b Same informatieobject linked to two cases without duplication

- **GIVEN** informatieobject `advies-brandweer.pdf` is already linked to `vergunning-1`
- **WHEN** `ZaakdossierService.linkExistingInformatieobject('vergunning-2', infoObjectId)` is called
- **THEN** a second `zaakinformatieobject` join object MUST be created for `vergunning-2`
- **AND** the informatieobject record itself MUST NOT be duplicated
- **AND** both zaak dossier views MUST show the document

@e2e exclude Non-duplication of the informatieobject across two zaakinformatieobject joins is a persistence invariant asserted in tests/Unit/Service/ZaakdossierServiceTest; the UI cannot distinguish "one record, two joins" from "two records".

#### Scenario: REQ-ZAK-001c Unlink preserves informatieobject

- **GIVEN** `bijlage.pdf` is linked to `vergunning-1` via `zaakinformatieobject` `zio-0001`
- **WHEN** `ZaakdossierService.unlinkInformatieobject('vergunning-1', infoObjectId)` is called
- **THEN** only the `zaakinformatieobject` join record MUST be deleted
- **AND** the informatieobject itself MUST remain in the register
- **AND** the Nextcloud file MUST remain in Nextcloud Files

@e2e exclude "Only the join record is deleted, the informatieobject and the Nextcloud file survive" is a storage-layer assertion (tests/Unit/Service/ZaakdossierServiceTest) — the dossier UI shows the same absence for a true delete and a mere unlink.

---

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

`DossierTab.vue` MUST render the complete dossier for a zaak, grouping documents in collapsible
sections per `informatieobjecttype` via `DossierGroup.vue`. `DocumentRow.vue` MUST display
for each document: thumbnail (Nextcloud preview API at `/index.php/core/preview?fileId={id}&x=64&y=64`),
titel, status badge (orange=concept, green=definitief, grey=gearchiveerd),
`vertrouwelijkheidaanduiding` badge, `creatiedatum`, `auteur`, `bestandsomvang`, and an action
menu (open, share, publish, version history, delete-if-concept). The tab header MUST show a
count badge (e.g., "Dossier (8)").

#### Scenario: REQ-ZAK-004a Dossier groups documents by type with count badge

- **GIVEN** zaak `vergunning-2026-0042` has 8 informatieobjecten: Aanvraag (2), Advies (3),
  Beschikking (1), Correspondentie (2)
- **WHEN** the user opens the DossierTab
- **THEN** documents MUST be grouped into 4 collapsible sections by informatieobjecttype
- **AND** the tab header MUST show "Dossier (8)"
- **AND** each row MUST show titel, status badge, creatiedatum, auteur,
  bestandsomvang, vertrouwelijkheidaanduiding badge

#### Scenario: REQ-ZAK-004b Empty dossier shows upload CTA with drag-and-drop zone

- **GIVEN** a new zaak with no linked informatieobjecten
- **WHEN** the user opens the DossierTab
- **THEN** an empty state MUST be shown with an upload button and drag-and-drop zone indicator
- **AND** no error or broken state MUST appear

#### Scenario: REQ-ZAK-004c Sort and filter controls work per column

- **GIVEN** a dossier with 25 informatieobjecten of mixed status and type
- **WHEN** the user filters by `status = definitief` and sorts by `creatiedatum` ascending
- **THEN** only definitief documents MUST be shown, sorted oldest-first
- **AND** the filter+sort state MUST be reflected in the URL

---

### Requirement: REQ-ZAK-005 Upload MUST present a metadata dialog and require informatieobjecttype and vertrouwelijkheidaanduiding

`DocumentMetadataDialog.vue` MUST be shown on drag-drop or upload click, requiring the user to
select an `informatieobjecttype` (dropdown from catalog filtered by current register schema)
and a `vertrouwelijkheidaanduiding` (default from selected type, overridable to more restrictive).
`titel` MUST be pre-filled from the filename and be editable. `beschrijving` is optional.
Multi-file upload MUST share the same metadata with per-file upload progress indicators.

#### Scenario: REQ-ZAK-005a Drag-drop triggers metadata dialog before upload

- **GIVEN** the user drags two PDF files onto the DossierTab drop zone
- **WHEN** the files are dropped
- **THEN** `DocumentMetadataDialog` MUST open showing both filenames
- **AND** a single `informatieobjecttype` selection MUST apply to both files
- **AND** the dialog MUST NOT close or upload until all required fields are filled

#### Scenario: REQ-ZAK-005b Per-file upload progress with shared metadata

- **GIVEN** the user has filled in metadata and clicks "Uploaden" for 3 files
- **WHEN** upload starts
- **THEN** each file MUST show an individual progress indicator (0–100%)
- **AND** on completion, each informatieobject MUST be created in the register
- **AND** a failure on one file MUST NOT block successful upload of the other two

#### Scenario: REQ-ZAK-005c File validation blocks executable uploads

- **GIVEN** a user drops `malware.exe` onto the dossier
- **WHEN** `FileValidationHandler.blockExecutableFile()` runs before storage
- **THEN** the upload MUST be rejected before the file is written to disk
- **AND** both extension check AND magic-byte detection MUST run
- **AND** the error MUST state the filename and reason (executable type)

---

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

`ZaakdossierController.downloadZgwDocumenten()` MUST expose a ZGW DRC-compatible endpoint at
`GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` that uses
`StreamResponse` with `Content-Range` support for resumable transfers of large files.

All download endpoints MUST pass through `InformatieobjectAccessGuard.canRead()` before
streaming begins.

#### Scenario: REQ-ZAK-009a ZGW DRC endpoint streams large file with Range support

- **GIVEN** informatieobject with UUID `inf-bbbb-0002` has `bestandsomvang` = 52 MB
- **WHEN** the client sends `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/inf-bbbb-0002/download`
  with header `Range: bytes=0-1048575`
- **THEN** the server MUST respond HTTP 206 Partial Content
- **AND** the response MUST include `Content-Range: bytes 0-1048575/54525952`
- **AND** the first 1 MB of file content MUST be returned

@e2e exclude HTTP 206 + `Content-Range` on a Range request is a transport contract asserted in tests/Unit/Http/RangeStreamResponseTest and in the Newman collection; a browser page navigation cannot set a Range header or assert partial-content framing.

#### Scenario: REQ-ZAK-009b Download blocked when user lacks clearance

- **GIVEN** informatieobject has `vertrouwelijkheidaanduiding` = `geheim`
- **AND** the requesting user's clearance is `intern`
- **WHEN** a download request arrives at the ZGW DRC endpoint
- **THEN** `InformatieobjectAccessGuard.canRead()` MUST deny access
- **AND** the server MUST return HTTP 403 Forbidden before streaming any content

@e2e exclude "403 before any bytes stream" is a guard-ordering assertion on the ZGW DRC endpoint, covered in tests/Unit/Service/InformatieobjectAccessGuardTest + Newman; the UI never links a document the caller may not read.

---

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

