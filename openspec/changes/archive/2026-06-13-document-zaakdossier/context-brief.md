# Proposal: document-zaakdossier

## Summary

Implement the ZGW DRC-compliant case dossier (zaakdossier) inside Procest. Every zaak gets a structured document dossier backed by Nextcloud Files, where each linked file is represented as an `informatieobject` with full ZGW metadata (titel, vertrouwelijkheidaanduiding, auteur, status, informatieobjecttype, integriteit) and joined to the case via `zaakinformatieobject`. The dossier groups documents by informatieobjecttype, enforces a `concept -> definitief -> gearchiveerd` status lifecycle, supports drag-and-drop upload with metadata, full-text search, version history, bulk operations, and ZIP export with `manifest.csv`.

## Motivation

80% of analyzed Dutch government tenders require structured case dossier management and 65% explicitly demand ZGW DRC compliance (informatieobjecten, vertrouwelijkheidaanduiding, document status lifecycle). Procest's current file handling is generic and lacks the ZGW metadata layer, status enforcement, and confidentiality-based access controls that municipalities need. This change builds the dossier layer on top of OpenRegister's existing file handlers without duplicating Nextcloud Files functionality.

## Affected Projects

- [ ] Project: `procest` — Backend dossier services, controllers, schemas, and Vue components for case dossier UI

## Scope

### In Scope

- **ZGW schemas** — `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype`
- **Status lifecycle** — `concept -> definitief -> gearchiveerd` with immutability enforcement at `definitief`
- **Vertrouwelijkheidaanduiding** — Access control by ZGW confidentiality enum
- **Dossier view** — Documents grouped by informatieobjecttype, count badge, filter/sort, thumbnails
- **Drag-and-drop upload** — Metadata dialog (informatieobjecttype, vertrouwelijkheidaanduiding, titel, beschrijving)
- **Version history** — Surface Nextcloud Files versions in dossier UI; block versions on definitief
- **Full-text search** — Dossier-scoped search via `TextExtractionService`
- **Bulk operations** — ZIP export with `manifest.csv`, bulk status transition, bulk metadata update
- **Sharing / publishing** — Public share links honoring vertrouwelijkheidaanduiding rules
- **ZGW DRC download endpoints** — Single, batch, and Range-supported streaming

### Out of Scope

- CMIS integration with external DMS systems
- MDTO archival (covered by `archivering-vernietiging` spec)
- BIM / 3D model viewing
- OCR for scanned image documents (deferred)

## Approach

1. Add ZGW DRC schemas to `procest_register.json` (`informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype`)
2. Create `ZaakdossierService` that orchestrates OpenRegister's existing handlers (`CreateFileHandler`, `FileSharingHandler`, `FilePublishingHandler`, `TextExtractionService`)
3. Add `ZaakdossierController` with REST endpoints for upload, status, bulk ops, and ZGW DRC-compatible download
4. Vue: `DossierTab.vue`, `DocumentMetadataDialog.vue`, `DossierGroup.vue`, `VersionHistoryPanel.vue`
5. Extend `SettingsService` with `dossier_*` config keys (per-register file size limit, sub-folder organization toggle)
6. Add status-transition middleware to enforce immutability and integriteit hash on save

## Risks

- Vertrouwelijkheidaanduiding-based access must be enforced at API layer, not just UI
- Status immutability must reject both `CreateFileHandler.saveFile()` upsert and direct Nextcloud writes for `definitief` files
- ZIP export of large dossiers needs streaming to avoid memory exhaustion
- Existing files already linked to objects must be back-filled with ZGW metadata via repair step



## Design

# Design: document-zaakdossier

## Architecture Overview

The zaakdossier layer sits between Procest's case views and OpenRegister's file handlers. Every uploaded document becomes both a Nextcloud file (stored at `Open Registers/{Register Title} Register/{objectUuid}/`) and an `informatieobject` register object carrying ZGW DRC metadata. The link to the case is a separate `zaakinformatieobject` join object — this matches the ZGW DRC model and lets a single document be linked to multiple cases without duplication. Documents linked to besluiten use the analogous `besluitinformatieobject` join. A `ZaakdossierService` orchestrates the existing OpenRegister file handlers; access control is enforced by a service-layer guard that checks user clearance against `vertrouwelijkheidaanduiding`. The dossier UI is a tab in `CaseDetail.vue` rendering documents grouped by informatieobjecttype.

```
CaseDetail.vue
└── DossierTab (new sidebar tab, count badge)
    ├── DossierGroup.vue (collapsible group per informatieobjecttype)
    │   └── DocumentRow.vue (thumbnail, status badge, vertrouwelijkheid badge, actions)
    ├── DocumentMetadataDialog.vue (drag-drop / upload metadata)
    ├── VersionHistoryPanel.vue (side panel showing Nextcloud versions)
    └── BulkActionsBar.vue (status transition, metadata update, ZIP download)

Backend
├── ZaakdossierService (orchestrator)
├── InformatieobjectAccessGuard (vertrouwelijkheid enforcement)
└── ZaakdossierController (REST endpoints)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/ZaakdossierService.php` | Orchestrates upload, link, status transitions, integrity hash, dossier listing |
| `lib/Service/InformatieobjectAccessGuard.php` | Enforces vertrouwelijkheidaanduiding hierarchy on read/share/publish |
| `lib/Service/ZipManifestBuilder.php` | Builds `manifest.csv` and informatieobjecttype-foldered ZIP via `FilePublishingHandler.createObjectFilesZip()` |
| `lib/Controller/ZaakdossierController.php` | Authenticated REST: upload, link, status transition, bulk ops, download |
| `lib/Migration/BackfillInformatieobjectMetadata.php` | Repair step: convert existing linked files to ZGW informatieobject metadata |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/DossierTab.vue` | Case detail tab with grouped dossier and count badge |
| `src/views/cases/components/DossierGroup.vue` | Collapsible group per informatieobjecttype with sort/filter |
| `src/views/cases/components/DocumentRow.vue` | Row: thumbnail, status/vertrouwelijkheid badges, version history button, share/publish action |
| `src/views/cases/components/DocumentMetadataDialog.vue` | Upload metadata dialog (type, vertrouwelijkheid, titel, beschrijving) |
| `src/views/cases/components/VersionHistoryPanel.vue` | Side panel exposing Nextcloud versions API |
| `src/views/cases/components/BulkActionsBar.vue` | Multi-select actions: status transition, metadata update, ZIP export |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype` schemas |
| `lib/Service/SettingsService.php` | Add `dossier_*` config keys and SLUG_TO_CONFIG_KEY entries |
| `appinfo/routes.php` | Add dossier and ZGW DRC download routes |
| `src/views/cases/CaseDetail.vue` | Replace generic Files tab with DossierTab |

## Data Model

### informatieobject Schema (schema.org `DigitalDocument`)
- `titel` (string, required) — Document title
- `bestandsnaam` (string, required) — Filename
- `bestandsomvang` (integer) — Size in bytes
- `formaat` (string) — MIME type
- `vertrouwelijkheidaanduiding` (enum, required) — `openbaar` / `beperkt_openbaar` / `intern` / `zaakvertrouwelijk` / `vertrouwelijk` / `confidentieel` / `geheim` / `zeer_geheim`
- `auteur` (string) — Display name of author
- `status` (enum) — `concept` / `definitief` / `gearchiveerd`
- `informatieobjecttype` (string/reference, required) — Document type from catalog
- `creatiedatum` (date, ISO 8601)
- `bronorganisatie` (string) — RSIN of organization
- `taal` (string, ISO 639-2/B, default `nld`)
- `beschrijving` (string, optional)
- `link` (string, optional) — External URI reference
- `integriteit.algoritme` (string, fixed `sha256`)
- `integriteit.waarde` (string) — SHA-256 hash
- `vergrendeldOp` (datetime, optional) — Set when status -> definitief
- `fileId` (integer) — Nextcloud file ID

### zaakinformatieobject Schema (join)
- `zaak` (string/reference, required) — Reference to case
- `informatieobject` (string/reference, required) — Reference to informatieobject
- `aardRelatieWeergave` (enum) — `Hoort bij, omgekeerd` / `Legt vast, omgekeerd`
- `registratiedatum` (datetime)

### besluitinformatieobject Schema (join)
- `besluit` (string/reference, required) — Reference to besluit
- `informatieobject` (string/reference, required) — Reference to informatieobject
- `registratiedatum` (datetime)

### informatieobjecttype Schema (catalog)
- `omschrijving` (string, required) — Type name (e.g. `Advies`)
- `informatieobjectcategorie` (string)
- `vertrouwelijkheidaanduiding` (enum) — Default classification
- `schema` (string) — Schema this type applies to
- `beginGeldigheid` (date)
- `eindeGeldigheid` (date, nullable)

## API Design

### Authenticated Endpoints (ZaakdossierController)
- `GET /api/cases/{caseId}/dossier` — List informatieobjecten grouped by type
- `POST /api/cases/{caseId}/dossier` — Upload one or many files with metadata
- `POST /api/cases/{caseId}/dossier/{infoObjectId}/link` — Link existing informatieobject to a case
- `DELETE /api/cases/{caseId}/dossier/{infoObjectId}/link` — Unlink (preserves informatieobject)
- `PATCH /api/informatieobjecten/{id}` — Update metadata (titel, beschrijving, type)
- `PATCH /api/informatieobjecten/{id}/status` — Status transition with validation
- `POST /api/informatieobjecten/bulk/status` — Bulk status transition
- `POST /api/cases/{caseId}/dossier/zip` — Generate ZIP with manifest (selected or full)
- `GET /api/objects/{register}/{schema}/{objectId}/files/{fileId}/download` — Single file download (vertrouwelijkheid-gated)
- `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` — ZGW DRC-compatible download (Range-supported streaming)

## Security & Compliance

- `InformatieobjectAccessGuard.canRead(user, informatieobject)` checks user clearance vs `vertrouwelijkheidaanduiding` (ordinal compare on enum array)
- Status transitions validated: `concept -> definitief -> gearchiveerd`; reverse transitions return HTTP 400
- Definitief documents reject `CreateFileHandler.saveFile()` upsert; `DeleteFileHandler` returns HTTP 409
- SHA-256 integrity hash computed on save via `hash_file('sha256', $path)`
- Public share rejected when `vertrouwelijkheidaanduiding >= vertrouwelijk`
- ZGW download endpoint uses `StreamResponse` with HTTP Range support for resumable downloads
- All operations (upload, status change, publish, delete) recorded in OpenRegister audit trail



## Tasks

# Tasks: document-zaakdossier

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Add `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, `informatieobjecttype` schemas to `lib/Settings/procest_register.json` with all ZGW DRC fields from design (titel/bestandsnaam/bestandsomvang/formaat/vertrouwelijkheidaanduiding/auteur/status/informatieobjecttype/creatiedatum/bronorganisatie/taal/beschrijving/integriteit/vergrendeldOp/fileId for informatieobject; zaak/informatieobject/aardRelatieWeergave/registratiedatum for the zaak join; besluit/informatieobject/registratiedatum for the besluit join; omschrijving/informatieobjectcategorie/vertrouwelijkheidaanduiding/schema/beginGeldigheid/eindeGeldigheid for the type catalog). Include schema.org annotations and enum constraints (status, vertrouwelijkheidaanduiding). Add config keys `dossier_informatieobject_schema`, `dossier_zaakinformatieobject_schema`, `dossier_besluitinformatieobject_schema`, `dossier_informatieobjecttype_schema`, `dossier_max_file_size`, `dossier_subfolder_per_type` to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY.

### Backend Services

- [ ] **T02**: Create `lib/Service/ZaakdossierService.php` — Methods: `uploadDocument(caseId, file, metadata)` validates file via `FileValidationHandler`, stores via `CreateFileHandler.addFile()`, computes SHA-256 hash, creates `informatieobject` and `zaakinformatieobject` join, tags file with `object:{uuid}` and `doctype:{type}` via `TaggingHandler`, triggers async text extraction via `FileTextExtractionJob`; `linkExistingInformatieobject(caseId, infoObjectId)` creates only the join (deduplicates); `unlinkInformatieobject(caseId, infoObjectId)` deletes only the join; `transitionStatus(infoObjectId, newStatus)` validates transition and sets `vergrendeldOp` on definitief; `getDossierForCase(caseId)` returns informatieobjecten grouped by informatieobjecttype with counts; `bulkTransitionStatus(infoObjectIds, newStatus)` returns per-id success/failure list.

- [ ] **T03**: Create `lib/Service/InformatieobjectAccessGuard.php` — Methods: `canRead(user, informatieobject)` compares user clearance level (from user profile / group claim) against `vertrouwelijkheidaanduiding` enum ordinal; `canPublish(informatieobject)` rejects if classification >= `vertrouwelijk`; `filterDossierForUser(user, informatieobjecten)` removes records above clearance; throws `\OCP\AppFramework\Http\Attribute\NotPermittedException` on denial. Hooked into `ZaakdossierController::download` and `FilePublishingHandler::publishFile()` via service guard.

- [ ] **T04**: Create `lib/Service/ZipManifestBuilder.php` — Builds `manifest.csv` (columns: bestandsnaam, titel, informatieobjecttype, status, vertrouwelijkheidaanduiding, creatiedatum, auteur) and organizes files into informatieobjecttype sub-folders. Extends `FilePublishingHandler.createObjectFilesZip()` by streaming entries (ZipStream) so large dossiers don't load into memory. Excludes documents above caller clearance.

### Controllers & Routes

- [ ] **T05**: Create `lib/Controller/ZaakdossierController.php` — Authenticated controller with endpoints: `listDossier(caseId)`, `uploadDocument(caseId)`, `linkExisting(caseId, infoObjectId)`, `unlinkDocument(caseId, infoObjectId)`, `updateMetadata(infoObjectId)`, `transitionStatus(infoObjectId)`, `bulkTransitionStatus()`, `bulkUpdateMetadata()`, `downloadZip(caseId)`, `downloadFile(register, schema, objectId, fileId)`, `downloadZgwDocumenten(uuid)`. All `@NoAdminRequired`; ZGW endpoint uses `StreamResponse` with Range support. Register routes in `appinfo/routes.php` before SPA catch-all; add `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` for ZGW DRC compatibility.

### Frontend Components

- [ ] **T06**: Create `src/views/cases/components/DossierTab.vue`, `DossierGroup.vue`, and `DocumentRow.vue` — Tab with count badge in header, groups documents by `informatieobjecttype` in collapsible sections, each row shows thumbnail (via Nextcloud preview API `/index.php/core/preview?fileId={id}&x=64&y=64`), titel, status badge (orange/green/grey), creatiedatum, auteur, bestandsomvang, vertrouwelijkheidaanduiding badge, action menu (open in Nextcloud Files, share, publish, version history, delete-if-concept). Sort/filter controls per column. Empty state with upload CTA and drag-and-drop zone indicator.

- [ ] **T07**: Create `src/views/cases/components/DocumentMetadataDialog.vue` and `VersionHistoryPanel.vue` — Metadata dialog appears on drag-drop or upload click: required `informatieobjecttype` dropdown (populated from catalog filtered by schema), required `vertrouwelijkheidaanduiding` dropdown (default from selected type), pre-filled editable `titel`, optional `beschrijving`. Supports multi-file upload with shared metadata and per-file progress. Version history panel fetches Nextcloud versions via `/dav/versions/{userId}/versions/{fileId}`, shows version number/timestamp/uploader with download and restore actions; restore disabled when status `definitief`.

- [ ] **T08**: Create `src/views/cases/components/BulkActionsBar.vue` — Multi-select toolbar on dossier with actions: "Markeer als definitief" (bulk transition), "Wijzig vertrouwelijkheidaanduiding", "Download selectie als ZIP". Posts to bulk endpoints and shows per-item success/failure summary.

### Integration & Migration

- [ ] **T09**: Create `lib/Migration/BackfillInformatieobjectMetadata.php` — Repair step iterating existing object folders, creating `informatieobject` records with sensible defaults (status `concept`, vertrouwelijkheidaanduiding `intern`, auteur from file owner, integriteit hash computed) and `zaakinformatieobject` joins for already-linked files. Registered via `info.xml` repair-steps. Idempotent (skips files that already have an informatieobject).

- [ ] **T10**: Update `src/views/cases/CaseDetail.vue` — Replace generic Files tab with `DossierTab` in `sidebarProps`. Add a `dossierCount` field to the case detail data refresh so the tab badge stays in sync. Wire BulkActionsBar visibility to a selection-state store.

## Verification Tasks

- [ ] **V01**: All four schemas valid JSON; `openregister:load-register` imports them and registers config keys
- [ ] **V02**: Upload via UI creates `informatieobject` + `zaakinformatieobject`, file lands at `Open Registers/{Register Title} Register/{uuid}/`, SHA-256 hash recorded in `integriteit.waarde`
- [ ] **V03**: Status transition from `concept` to `definitief` sets `vergrendeldOp`; new version upload to definitief returns HTTP 409
- [ ] **V04**: Definitief reverse-transition to `concept` rejected with HTTP 400
- [ ] **V05**: User with `intern` clearance cannot read `vertrouwelijk` document (HTTP 403) and document is filtered out of dossier listing
- [ ] **V06**: Public share rejected for documents with vertrouwelijkheidaanduiding >= `vertrouwelijk`
- [ ] **V07**: ZIP export contains `manifest.csv` and informatieobjecttype sub-folders; documents above clearance excluded
- [ ] **V08**: ZGW DRC download endpoint streams large files and supports HTTP Range requests
- [ ] **V09**: Dossier tab shows count badge, group-by-type works, drag-drop opens metadata dialog
- [ ] **V10**: Repair step back-fills existing files with informatieobject metadata; re-run is a no-op