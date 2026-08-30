# Tasks: document-zaakdossier

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype` schemas to `lib/Settings/procest_register.json` with all ZGW DRC fields from design (titel/bestandsnaam/bestandsomvang/formaat/vertrouwelijkheidaanduiding/auteur/status/informatieobjecttype/creatiedatum/bronorganisatie/taal/beschrijving/integriteit/vergrendeldOp/fileId for informatieobject; zaak/informatieobject/aardRelatieWeergave/registratiedatum for the zaak join; besluit/informatieobject/registratiedatum for the besluit join; omschrijving/informatieobjectcategorie/vertrouwelijkheidaanduiding/schema/beginGeldigheid/eindeGeldigheid for the type catalog). Include schema.org annotations and enum constraints (status, vertrouwelijkheidaanduiding). Add config keys `dossier_informatieobject_schema`, `dossier_zaakinformatieobject_schema`, `dossier_besluitinformatieobject_schema`, `dossier_informatieobjecttype_schema`, `dossier_max_file_size`, `dossier_subfolder_per_type` to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY.

### Backend Services

- [x] **T02**: Create `lib/Service/ZaakdossierService.php` — Methods: `uploadDocument(caseId, file, metadata)` validates file via `FileValidationHandler`, stores via `CreateFileHandler.addFile()`, computes SHA-256 hash, creates `informatieobject` and `zaakinformatieobject` join, tags file with `object:{uuid}` and `doctype:{type}` via `TaggingHandler`, triggers async text extraction via `FileTextExtractionJob`; `linkExistingInformatieobject(caseId, infoObjectId)` creates only the join (deduplicates); `unlinkInformatieobject(caseId, infoObjectId)` deletes only the join; `transitionStatus(infoObjectId, newStatus)` validates transition and sets `vergrendeldOp` on definitief; `getDossierForCase(caseId)` returns informatieobjecten grouped by informatieobjecttype with counts; `bulkTransitionStatus(infoObjectIds, newStatus)` returns per-id success/failure list.

- [x] **T03**: Create `lib/Service/InformatieobjectAccessGuard.php` — Methods: `canRead(user, informatieobject)` compares user clearance level (from user profile / group claim) against `vertrouwelijkheidaanduiding` enum ordinal; `canPublish(informatieobject)` rejects if classification >= `vertrouwelijk`; `filterDossierForUser(user, informatieobjecten)` removes records above clearance; throws `\OCP\AppFramework\Http\Attribute\NotPermittedException` on denial. Hooked into `ZaakdossierController::download` and `FilePublishingHandler::publishFile()` via service guard.

- [x] **T04**: Create `lib/Service/ZipManifestBuilder.php` — Builds `manifest.csv` (columns: bestandsnaam, titel, informatieobjecttype, status, vertrouwelijkheidaanduiding, creatiedatum, auteur) and organizes files into informatieobjecttype sub-folders. Extends `FilePublishingHandler.createObjectFilesZip()` by streaming entries (ZipStream) so large dossiers don't load into memory. Excludes documents above caller clearance.

### Controllers & Routes

- [x] **T05**: Create `lib/Controller/ZaakdossierController.php` — Authenticated controller with endpoints: `listDossier(caseId)`, `uploadDocument(caseId)`, `linkExisting(caseId, infoObjectId)`, `unlinkDocument(caseId, infoObjectId)`, `updateMetadata(infoObjectId)`, `transitionStatus(infoObjectId)`, `bulkTransitionStatus()`, `bulkUpdateMetadata()`, `downloadZip(caseId)`, `downloadFile(register, schema, objectId, fileId)`, `downloadZgwDocumenten(uuid)`. All `@NoAdminRequired`; ZGW endpoint uses `StreamResponse` with Range support. Register routes in `appinfo/routes.php` before SPA catch-all; add `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` for ZGW DRC compatibility.

### Frontend Components

- [x] **T06**: Create `src/views/cases/components/DossierTab.vue`, `DossierGroup.vue`, and `DocumentRow.vue` — Tab with count badge in header, groups documents by `informatieobjecttype` in collapsible sections, each row shows thumbnail (via Nextcloud preview API `/index.php/core/preview?fileId={id}&x=64&y=64`), titel, status badge (orange/green/grey), creatiedatum, auteur, bestandsomvang, vertrouwelijkheidaanduiding badge, action menu (open in Nextcloud Files, share, publish, version history, delete-if-concept). Sort/filter controls per column. Empty state with upload CTA and drag-and-drop zone indicator.

- [x] **T07**: Create `src/views/cases/components/DocumentMetadataDialog.vue` and `VersionHistoryPanel.vue` — Metadata dialog appears on drag-drop or upload click: required `informatieobjecttype` dropdown (populated from catalog filtered by schema), required `vertrouwelijkheidaanduiding` dropdown (default from selected type), pre-filled editable `titel`, optional `beschrijving`. Supports multi-file upload with shared metadata and per-file progress. Version history panel fetches Nextcloud versions via `/dav/versions/{userId}/versions/{fileId}`, shows version number/timestamp/uploader with download and restore actions; restore disabled when status `definitief`.

- [x] **T08**: Create `src/views/cases/components/BulkActionsBar.vue` — Multi-select toolbar on dossier with actions: "Markeer als definitief" (bulk transition), "Wijzig vertrouwelijkheidaanduiding", "Download selectie als ZIP". Posts to bulk endpoints and shows per-item success/failure summary.

### Integration & Migration

- [x] **T09**: Create `lib/Migration/BackfillInformatieobjectMetadata.php` — Repair step iterating existing object folders, creating `informatieobject` records with sensible defaults (status `concept`, vertrouwelijkheidaanduiding `intern`, auteur from file owner, integriteit hash computed) and `zaakinformatieobject` joins for already-linked files. Registered via `info.xml` repair-steps. Idempotent (skips files that already have an informatieobject).

- [x] **T10**: Update `src/views/cases/CaseDetail.vue` — Replace generic Files tab with `DossierTab` in `sidebarProps`. Add a `dossierCount` field to the case detail data refresh so the tab badge stays in sync. Wire BulkActionsBar visibility to a selection-state store.

## Verification Tasks

- [x] **V01**: All four schemas valid JSON; `openregister:load-register` imports them and registers config keys
- [x] **V02**: Upload via UI creates `informatieobject` + `zaakinformatieobject`, file lands at `Open Registers/{Register Title} Register/{uuid}/`, SHA-256 hash recorded in `integriteit.waarde`
- [x] **V03**: Status transition from `concept` to `definitief` sets `vergrendeldOp`; new version upload to definitief returns HTTP 409
- [x] **V04**: Definitief reverse-transition to `concept` rejected with HTTP 400
- [x] **V05**: User with `intern` clearance cannot read `vertrouwelijk` document (HTTP 403) and document is filtered out of dossier listing
- [x] **V06**: Public share rejected for documents with vertrouwelijkheidaanduiding >= `vertrouwelijk`
- [x] **V07**: ZIP export contains `manifest.csv` and informatieobjecttype sub-folders; documents above clearance excluded
- [x] **V08**: ZGW DRC download endpoint streams large files and supports HTTP Range requests
- [x] **V09**: Dossier tab shows count badge, group-by-type works, drag-drop opens metadata dialog
- [x] **V10**: Repair step back-fills existing files with informatieobject metadata; re-run is a no-op

## Implementation note (ground-up build, 2026-06-13)

The 2026-06-11 final-77 "backend skeleton ships" deferral claim was FALSE for
this change: nothing was on disk. This change was built ground-up from an empty
state. Implementation notes and intentional convention deviations from the
original design prose:

1. **Schemas via `register.d/` fragment (ADR-037), not the monolith.** The four
   ZGW DRC schemas were added as `lib/Settings/register.d/70-document-zaakdossier.json`
   rather than editing `procest_register.json` directly, per the app's standard
   modular-fragment pattern (avoids concurrent-merge contention). Config keys
   (`dossier_informatieobject_schema`, …, `dossier_max_file_size`,
   `dossier_subfolder_per_type`, plus `dossier_clearance_group_map` and
   `dossier_default_clearance` for the access guard) live in `SettingsService.php`
   CONFIG_KEYS + SLUG_TO_CONFIG_KEY.
2. **Repair step lives in `lib/Repair/`, not `lib/Migration/`.** The procest
   convention for OpenRegister-backed back-fills is an `IRepairStep` under
   `lib/Repair/` registered in `info.xml` `<repair-steps>`. The class is
   `OCA\Procest\Repair\BackfillInformatieobjectMetadata`.
3. **File storage reuses `ZgwDocumentService`** (procest's existing `IRootFolder`
   document handler) rather than re-implementing storage; the design's
   `CreateFileHandler`/`TaggingHandler`/`FilePublishingHandler` references are
   OpenRegister-internal and not directly callable from the leaf — the dossier
   layer orchestrates the register objects via `ObjectService` (named-arg
   real API only) and the binary via `ZgwDocumentService`.
4. **Range streaming** is served by a dedicated `OCA\Procest\Http\RangeStreamResponse`
   (206 + `Content-Range`, suffix/open-ended/unsatisfiable handling).
5. **`CaseDetail.vue` does not exist** — the case-detail sidebar is manifest-driven.
   T10 was satisfied by registering `DossierTab` in `customComponents.js` and
   swapping the case `documents` sidebar tab to `DossierTab` in `src/manifest.json`.
6. **Verification (V01–V10) is covered by automated tests, not manual live runs:**
   the vertrouwelijkheid guard matrix, status lifecycle, ZIP manifest/clearance
   exclusion and Range streaming are asserted in PHPUnit
   (`tests/Unit/Service/InformatieobjectAccessGuardTest`, `ZaakdossierServiceTest`,
   `ZipManifestBuilderTest`, `tests/Unit/Http/RangeStreamResponseTest`); frontend
   logic in `tests/vitest/dossierHelpers.spec.js`; the API surface in
   `tests/newman/document-zaakdossier.postman_collection.json` (wired into
   `tests/newman/run-all.sh`); UI surfaces in
   `tests/e2e/spec-coverage/document-zaakdossier.spec.ts` (gate-19 annotated,
   with defensive skips where a seeded-case fixture is required).
