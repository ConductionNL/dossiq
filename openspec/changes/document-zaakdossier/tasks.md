# Tasks: document-zaakdossier

## Implementation Tasks

### Schema & Configuration

- [~] **T01**: Add `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype` schemas to `lib/Settings/procest_register.json` with all ZGW DRC fields from design (titel/bestandsnaam/bestandsomvang/formaat/vertrouwelijkheidaanduiding/auteur/status/informatieobjecttype/creatiedatum/bronorganisatie/taal/beschrijving/integriteit/vergrendeldOp/fileId for informatieobject; zaak/informatieobject/aardRelatieWeergave/registratiedatum for the zaak join; besluit/informatieobject/registratiedatum for the besluit join; omschrijving/informatieobjectcategorie/vertrouwelijkheidaanduiding/schema/beginGeldigheid/eindeGeldigheid for the type catalog). Include schema.org annotations and enum constraints (status, vertrouwelijkheidaanduiding). Add config keys `dossier_informatieobject_schema`, `dossier_zaakinformatieobject_schema`, `dossier_besluitinformatieobject_schema`, `dossier_informatieobjecttype_schema`, `dossier_max_file_size`, `dossier_subfolder_per_type` to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY. — deferred to downstream cycle / fleet-wide adoption (handoff)

### Backend Services

- [~] **T02**: Create `lib/Service/ZaakdossierService.php` — Methods: `uploadDocument(caseId, file, metadata)` validates file via `FileValidationHandler`, stores via `CreateFileHandler.addFile()`, computes SHA-256 hash, creates `informatieobject` and `zaakinformatieobject` join, tags file with `object:{uuid}` and `doctype:{type}` via `TaggingHandler`, triggers async text extraction via `FileTextExtractionJob`; `linkExistingInformatieobject(caseId, infoObjectId)` creates only the join (deduplicates); `unlinkInformatieobject(caseId, infoObjectId)` deletes only the join; `transitionStatus(infoObjectId, newStatus)` validates transition and sets `vergrendeldOp` on definitief; `getDossierForCase(caseId)` returns informatieobjecten grouped by informatieobjecttype with counts; `bulkTransitionStatus(infoObjectIds, newStatus)` returns per-id success/failure list. — deferred to downstream cycle / fleet-wide adoption (handoff)

- [~] **T03**: Create `lib/Service/InformatieobjectAccessGuard.php` — Methods: `canRead(user, informatieobject)` compares user clearance level (from user profile / group claim) against `vertrouwelijkheidaanduiding` enum ordinal; `canPublish(informatieobject)` rejects if classification >= `vertrouwelijk`; `filterDossierForUser(user, informatieobjecten)` removes records above clearance; throws `\OCP\AppFramework\Http\Attribute\NotPermittedException` on denial. Hooked into `ZaakdossierController::download` and `FilePublishingHandler::publishFile()` via service guard. — deferred to downstream cycle / fleet-wide adoption (handoff)

- [~] **T04**: Create `lib/Service/ZipManifestBuilder.php` — Builds `manifest.csv` (columns: bestandsnaam, titel, informatieobjecttype, status, vertrouwelijkheidaanduiding, creatiedatum, auteur) and organizes files into informatieobjecttype sub-folders. Extends `FilePublishingHandler.createObjectFilesZip()` by streaming entries (ZipStream) so large dossiers don't load into memory. Excludes documents above caller clearance. — deferred to downstream cycle / fleet-wide adoption (handoff)

### Controllers & Routes

- [~] **T05**: Create `lib/Controller/ZaakdossierController.php` — Authenticated controller with endpoints: `listDossier(caseId)`, `uploadDocument(caseId)`, `linkExisting(caseId, infoObjectId)`, `unlinkDocument(caseId, infoObjectId)`, `updateMetadata(infoObjectId)`, `transitionStatus(infoObjectId)`, `bulkTransitionStatus()`, `bulkUpdateMetadata()`, `downloadZip(caseId)`, `downloadFile(register, schema, objectId, fileId)`, `downloadZgwDocumenten(uuid)`. All `@NoAdminRequired`; ZGW endpoint uses `StreamResponse` with Range support. Register routes in `appinfo/routes.php` before SPA catch-all; add `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` for ZGW DRC compatibility. — deferred to downstream cycle / fleet-wide adoption (handoff)

### Frontend Components

- [~] **T06**: Create `src/views/cases/components/DossierTab.vue`, `DossierGroup.vue`, and `DocumentRow.vue` — Tab with count badge in header, groups documents by `informatieobjecttype` in collapsible sections, each row shows thumbnail (via Nextcloud preview API `/index.php/core/preview?fileId={id}&x=64&y=64`), titel, status badge (orange/green/grey), creatiedatum, auteur, bestandsomvang, vertrouwelijkheidaanduiding badge, action menu (open in Nextcloud Files, share, publish, version history, delete-if-concept). Sort/filter controls per column. Empty state with upload CTA and drag-and-drop zone indicator. — deferred to downstream cycle / fleet-wide adoption (handoff)

- [~] **T07**: Create `src/views/cases/components/DocumentMetadataDialog.vue` and `VersionHistoryPanel.vue` — Metadata dialog appears on drag-drop or upload click: required `informatieobjecttype` dropdown (populated from catalog filtered by schema), required `vertrouwelijkheidaanduiding` dropdown (default from selected type), pre-filled editable `titel`, optional `beschrijving`. Supports multi-file upload with shared metadata and per-file progress. Version history panel fetches Nextcloud versions via `/dav/versions/{userId}/versions/{fileId}`, shows version number/timestamp/uploader with download and restore actions; restore disabled when status `definitief`. — deferred to downstream cycle / fleet-wide adoption (handoff)

- [~] **T08**: Create `src/views/cases/components/BulkActionsBar.vue` — Multi-select toolbar on dossier with actions: "Markeer als definitief" (bulk transition), "Wijzig vertrouwelijkheidaanduiding", "Download selectie als ZIP". Posts to bulk endpoints and shows per-item success/failure summary. — deferred to downstream cycle / fleet-wide adoption (handoff)

### Integration & Migration

- [~] **T09**: Create `lib/Migration/BackfillInformatieobjectMetadata.php` — Repair step iterating existing object folders, creating `informatieobject` records with sensible defaults (status `concept`, vertrouwelijkheidaanduiding `intern`, auteur from file owner, integriteit hash computed) and `zaakinformatieobject` joins for already-linked files. Registered via `info.xml` repair-steps. Idempotent (skips files that already have an informatieobject). — deferred to downstream cycle / fleet-wide adoption (handoff)

- [~] **T10**: Update `src/views/cases/CaseDetail.vue` — Replace generic Files tab with `DossierTab` in `sidebarProps`. Add a `dossierCount` field to the case detail data refresh so the tab badge stays in sync. Wire BulkActionsBar visibility to a selection-state store. — deferred to downstream cycle / fleet-wide adoption (handoff)

## Verification Tasks

- [~] **V01**: All four schemas valid JSON; `openregister:load-register` imports them and registers config keys — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V02**: Upload via UI creates `informatieobject` + `zaakinformatieobject`, file lands at `Open Registers/{Register Title} Register/{uuid}/`, SHA-256 hash recorded in `integriteit.waarde` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V03**: Status transition from `concept` to `definitief` sets `vergrendeldOp`; new version upload to definitief returns HTTP 409 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V04**: Definitief reverse-transition to `concept` rejected with HTTP 400 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V05**: User with `intern` clearance cannot read `vertrouwelijk` document (HTTP 403) and document is filtered out of dossier listing — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V06**: Public share rejected for documents with vertrouwelijkheidaanduiding >= `vertrouwelijk` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V07**: ZIP export contains `manifest.csv` and informatieobjecttype sub-folders; documents above clearance excluded — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V08**: ZGW DRC download endpoint streams large files and supports HTTP Range requests — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V09**: Dossier tab shows count badge, group-by-type works, drag-drop opens metadata dialog — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V10**: Repair step back-fills existing files with informatieobject metadata; re-run is a no-op — deferred to downstream cycle / fleet-wide adoption (handoff)
