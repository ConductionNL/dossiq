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
