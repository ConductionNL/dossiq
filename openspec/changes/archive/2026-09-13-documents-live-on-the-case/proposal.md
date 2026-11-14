# Proposal: documents live on the case

## Why

A ZGW document on a case is stored today as a file attached to its own `informatieobject` record, in that record's folder, while the case's own folder stays empty. A handler who opens the case's Files tab sees an empty folder next to a list of documents that live somewhere else, and a file dropped into the case folder is not a document at all. Ruben, 2026-09-13: "they should be normal files attached to the case object in the normal way (being in the folder) but have the additional metadata assigned so that we can also provide them through the ZGW API." The Files tab just became the case folder (#2631), so this is the moment the two models have to become one.

## What Changes

- **The file is on the case.** A document's file lives in the case's folder, attached to the case object, with sharing, versions, comments, the Files app's actions and the Files tab as they are for any file.
- **The `informatieobject` record becomes the projection of that file.** It keeps the DRC metadata (title, type, confidentiality, author, status, language, organisation, format, size, integrity) and `fileId`, but dossiq creates it and keeps it in step from the file, instead of the record owning the file. The `zaakinformatieobject` join stays, so one file can be linked to several cases.
- **A drop in the Files tab is a document.** dossiq listens to Nextcloud's node events under case folders and creates the projection with derived defaults (title from the name, format and size from the node, author the uploader, confidentiality and document type from the case type's defaults, status concept). A second write to the same file refreshes size, format and integrity. A rename follows; a delete retires the projection and its joins.
- **Metadata is edited on the row.** The Files tab offers a Document properties action per file that opens the existing metadata dialog on the file's projection; a file without a projection gets one on save.
- **API-first documents move into the case.** dossiq's DRC keeps storing `inhoud` in the record's own folder when the record is created before any join; when the `zaakinformatieobject` lands, the file moves into that case's folder and keeps its file id. A later join to another case does not move it again.
- **Linked documents show as linked.** A case that is joined to a document whose file lives in another case's folder shows it in its Files tab as a linked row (name, type, the owning case) with open and download, not as a file in its folder.
- **Existing documents are migrated.** A repair step moves every joined document's file into the folder of the case that owns it (the first join, by registration date) and refreshes `fileId`.
- **BREAKING for the retired UI path only:** the upload dialog no longer attaches the file to the `informatieobject`; it attaches it to the case and lets the projection follow. The DRC API's request and response shapes do not change.

## Capabilities

### New Capabilities

- `document-projection`: the rules that keep an `informatieobject` record in step with the file on the case: creation from a node event with derived defaults, refresh on write, rename and delete, the API-first move on join, the linked row for other cases, and the migration of existing documents.

### Modified Capabilities

- `document-zaakdossier`: REQ-ZAK-001 changes from "the file is stored in the informatieobject's folder" to "the file is stored in the case's folder and the informatieobject references it"; the upload scenario, the download endpoint scenario and the retirement note written under REQ-ZAK-004 on 2026-09-13 change with it.
- `case-dashboard-view`: the scenario "Files is the case folder and nothing else" gains the Document properties action and the linked rows; the Files tab stays the only document surface.

## Impact

- **dossiq PHP**: a node listener under case folders (`lib/Listener`), a `DocumentProjectionService` in `lib/Service/Zaakdossier`, `DrcController` and `ZrcController` on create and on join, a repair step in `lib/Repair` registered under `post-migration` with a persisted version key (ADR-106), the `DossierUploadHandler` attaching to the case. Nothing new in `register()` beyond the listener registration.
- **dossiq frontend**: the Files tab passes the case's linked documents and the Document properties action into the files browser; `DocumentMetadataDialog` is reused on a file; the manifest's `case-files` widget gains the configuration.
- **@conduction/nextcloud-vue**: `CnFilesBrowser` gains two additive props, `rowActions` (host-provided per-row actions) and `linkedItems` (rows that are not nodes in this folder), forwarded by `CnFilesTab`. A library release sits between the library PR and the dossiq PR that uses it.
- **OpenRegister**: consumed, not changed. `FolderManagementHandler::getObjectFolder()` resolves case folders; the file record (`openregister_files`) follows the file id on a move; if its object association does not follow, that is an OpenRegister fix, filed there.
- **zaakafhandelapp**: its listener on the dev instance is 39 commits stale and refuses uploads into case folders with a 422; its #665 scopes it to its own registers. Until that checkout moves, the drop flow cannot be shown on the shared instance. No code change here.
- **Pipelinq**: the request-to-case bridge attaches request files to the created case through OpenRegister; those files become documents by the same node rule, which is the intended effect.
- **Dependencies**: none new. `@nextcloud/files` 4 already arrives through the library.
