# Design: documents live on the case

## Context

Today `DocumentMetadataDialog` and the DRC `create` both call OpenRegister's file service with the `informatieobject` as the owning object, so the file lands in `Open Registers/<register>/<informatieobject uuid>/`. The case's own folder, which the Files tab now renders (#2631), holds nothing. The `informatieobject` register schema (`lib/Settings/register.d/70-document-zaakdossier.json`) carries English property names (`title`, `fileName`, `format`, `description`, `integrity`, `status` draft|final|archived, `fileId`) that the ZGW mappings translate to the DRC vocabulary; `informatieobjecttype` and `vertrouwelijkheidaanduiding` are required. `zaakinformatieobject` joins `case` to `informatieobject`. OpenRegister raises Nextcloud's own node events for every write under an object folder and resolves a case's folder through `FolderManagementHandler::getObjectFolder()`; its file record follows the Nextcloud file id. The library's `CnFilesBrowser` lists a folder over DAV with the Files app's registered actions and has no hook for host actions or foreign rows yet.

## Goals / Non-Goals

Goals: a file in the case folder is a document with a ZGW record; the record follows the file; API-first documents join their case's folder; other cases see a linked row; existing documents migrate; the DRC API's shapes stay as they are.

Non-goals: changing zaakafhandelapp; changing the DRC request or response shapes; a documents widget beside the Files tab; document defaults per case type beyond one type and one confidentiality; the people model (its own change).

## Decisions

### D1: The Nextcloud node event is the trigger, with the case resolved through the folder

dossiq registers one listener on `NodeCreatedEvent`, `NodeWrittenEvent`, `NodeRenamedEvent` and `NodeDeletedEvent` (`OCP\Files\Events\Node\*`). It ignores folders, ignores nodes whose path is not under `Open Registers/`, and resolves the case by walking up to the first ancestor whose file id equals some case's `@self.folder`, reading that through OpenRegister's object service with a filter on the folder id. A node under a sub-folder of the case folder is the case's document (REQ-DPR-001, folder scenario). The listener does the work in the request, because a document that appears seconds after its upload is what the Files tab shows next, and the write is one object create; the heavy part (text extraction) already runs asynchronously under REQ-ZAK-007.

Why not OpenRegister's own `FileChangeListener` events: it raises none for a created file, only for copy, move, rename, lock and version restore. Why not a cron sweep: a drop has to be a document before the handler opens Document properties.

### D2: One record per file id, refreshed in place

`DocumentProjectionService::projectNode(File $node, ObjectEntity $case, IUser $actor)` looks the record up by `fileId` first. Absent: create with the derived defaults. Present: refresh `bestandsomvang`, `format`, `integrity` and, for a rename, `fileName` and a still-derived `title`. The lookup by `fileId` is what makes chunked uploads, `NodeWritten` after `NodeCreated`, and version restores idempotent (REQ-DPR-001 second-write scenario). The join is created only when no join for (case, record) exists.

### D3: Defaults come from the case type, then the document type, then fixed values

`informatieobjecttype` defaults to `caseType.defaultInformatieobjecttype` (a new optional reference property on the `caseType` schema, fragment `lib/Settings/register.d/`), else the register's first `informatieobjecttype` by title; `vertrouwelijkheidaanduiding` defaults to that type's own `vertrouwelijkheidaanduiding` (REQ-ZAK-003d), else `zaakvertrouwelijk`. Both are required by the schema, so the record is always valid; the handler corrects them in Document properties. `direction` defaults to `incoming` for a drop by a user who is not the case's assignee and `internal` otherwise.

### D4: Delete retires by status; move re-homes

`NodeDeletedEvent`: a draft record and its joins are deleted; a final record keeps the record, drops the joins and sets `status` archived, so the DRC API still answers for it (REQ-DPR-002). `NodeRenamedEvent` carries source and target: same parent means rename; a different parent under another case's folder means unjoin the source case, join the target case, keep the record; a different parent outside any case folder means delete-on-this-case.

### D5: API-first documents stage in their own folder and move on the first join

`DrcController::create` keeps its current path (file in the record's own folder, `fileId` resolved through `ZgwDocumentService::getFileId()`). `ZrcController` on a created `zaakinformatieobject` calls `DocumentProjectionService::homeDocument(record, case)`: when the record's file is not under any case folder, move the node into the case's folder with Nextcloud's `Node::move()`, then re-read `fileId` (a move keeps it on the same storage) and write it back only if it changed. A later join finds the file already under a case folder and does nothing (REQ-DPR-003). The listener of D1 sees the move as a rename with a new parent and, finding the record already joined, refreshes and returns.

### D6: Linked rows and the row action are library extension points, not dossiq forks

`CnFilesBrowser` gains `rowActions: Array<{ id, label, icon, run(node) }>` rendered after the registered actions, and `linkedItems: Array<{ id, name, mime, size, mtime, href, downloadHref, note }>` rendered as rows after the folder's nodes with open and download only. `CnFilesTab` forwards both from new props. dossiq's `case-files` widget configuration names the action (`Document properties`, opening `DocumentMetadataDialog` with the node's file id) and the tab fetches the case's joins whose record `fileId` resolves outside the case folder to build the linked list (REQ-DPR-004). Library first, release, then dossiq.

### D7: The repair step is post-migration, version-gated, idempotent

`lib/Repair/MoveDocumentsIntoCaseFolders.php` under `repair-steps/post-migration`, gated on a persisted app-config key `documents_on_case_migrated` (ADR-106). For every `informatieobject` with a `fileId`: resolve the node; if it is already under a case folder, skip; else find the earliest join by `registratiedatum`, move the node into that case's folder, refresh `fileId`; a missing node is logged with the record uuid and skipped (REQ-DPR-005). Re-running finds everything under a case folder and moves nothing.

### D8: The upload dialog attaches to the case

`DocumentMetadataDialog` posts the file to `/objects/dossiq/case/{caseId}/files` instead of the record's files endpoint and then saves the metadata onto the record the listener created, found by `fileId` after the upload response. The dialog keeps working from the Files tab row on an existing file.

## Declarative-vs-imperative decision (ADR-031)

Checked against `x-openregister-lifecycle`, `-relations`, `-notifications` and `-flows`: none reacts to a Nextcloud node event or moves a file between object folders, and the projection is a derived write, not a state machine. The listener and the service are imperative by necessity; the record's lifecycle (draft, final, archived) stays the declarative `x-openregister-lifecycle` it already is, and the service only sets `status` archived through it. The `caseType.defaultInformatieobjecttype` property is declarative.

## Seed Data

The English demo set (`46-demo-cases-english.json`) declares two `informatieobject` rows with `fileName` and no file; they are records without nodes and stay as they are (the repair step names them and skips). No new seed rows.

## Risks / Trade-offs

- Every WebDAV write under `Open Registers/` costs one object lookup by folder id. Bounded by the listener's early exits (folders, paths outside the register root) and one indexed filter; measured against the e2e upload before merge.
- The zaakafhandelapp listener on the dev instance rejects uploads into case folders (its 422); the drop flow is verifiable there only after that checkout moves to its development. Unit and e2e tests cover it regardless.
- Moving a node between object folders relies on OpenRegister's file record following the file id. Verified in a unit test against the mapper before the repair step is written; if the association does not follow, the fix goes to OpenRegister and the step waits for it.
- A definitief document deleted from the file system keeps a record with no file: the DRC download answers 404 for it, which is honest, and the audit trail keeps the delete.
