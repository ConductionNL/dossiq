# Design: document-zaakdossier

status: pr-created

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
