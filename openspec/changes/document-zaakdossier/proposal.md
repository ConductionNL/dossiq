---
kind: implementation
depends_on: []
chain: []
---

# Proposal: document-zaakdossier

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

80% of analysed Dutch government tenders require structured case dossier management and 65%
explicitly demand ZGW DRC compliance (informatieobjecten, vertrouwelijkheidaanduiding, document
status lifecycle). Procest's current file handling is generic and lacks the ZGW metadata layer,
status enforcement, and confidentiality-based access controls that municipalities need.

Without this change every Procest deployment either (a) fails compliance checks on tender
scoring, or (b) requires manual workarounds that diverge across municipalities. This change
builds the dossier layer on top of OpenRegister's existing file handlers
(`CreateFileHandler`, `FileSharingHandler`, `FilePublishingHandler`, `TextExtractionService`)
without duplicating Nextcloud Files functionality.

## What changes

1. **Four ZGW DRC schemas** added to `lib/Settings/procest_register.json`:
   `informatieobject` (document with full ZGW metadata), `zaakinformatieobject` (case↔document
   join), `besluitinformatieobject` (decision↔document join), `informatieobjecttype` (document
   type catalog).

2. **`ZaakdossierService`** — orchestrator: upload, link/unlink, status transition,
   integrity hash (SHA-256), dossier listing grouped by type, bulk ops.

3. **`InformatieobjectAccessGuard`** — service-layer enforcement of `vertrouwelijkheidaanduiding`
   hierarchy on every read, share, publish, and download operation.

4. **`ZipManifestBuilder`** — streaming ZIP export with `manifest.csv` and
   informatieobjecttype sub-folders via `ZipStream`; documents above caller's clearance
   are excluded automatically.

5. **`ZaakdossierController`** — authenticated REST endpoints for upload, link, status
   transition, bulk operations, and ZGW DRC-compatible streaming download (Range-supported).

6. **Six Vue components** — `DossierTab.vue`, `DossierGroup.vue`, `DocumentRow.vue`,
   `DocumentMetadataDialog.vue`, `VersionHistoryPanel.vue`, `BulkActionsBar.vue`.

7. **`BackfillInformatieobjectMetadata` repair step** — idempotent migration converting
   existing linked files to ZGW informatieobject metadata.

8. **Settings** — `dossier_*` config keys (`dossier_max_file_size`, `dossier_subfolder_per_type`,
   schema refs) added to `SettingsService`.

## Impact

| File | Change |
|------|--------|
| `lib/Settings/procest_register.json` | Add 4 ZGW DRC schemas |
| `lib/Service/ZaakdossierService.php` | New — dossier orchestrator |
| `lib/Service/InformatieobjectAccessGuard.php` | New — vertrouwelijkheid enforcement |
| `lib/Service/ZipManifestBuilder.php` | New — streaming ZIP builder |
| `lib/Controller/ZaakdossierController.php` | New — dossier REST endpoints |
| `lib/Migration/BackfillInformatieobjectMetadata.php` | New — repair step |
| `lib/Service/SettingsService.php` | Add `dossier_*` config keys |
| `appinfo/routes.php` | Add dossier + ZGW DRC download routes |
| `src/views/cases/CaseDetail.vue` | Replace generic Files tab with DossierTab |
| `src/views/cases/components/DossierTab.vue` | New |
| `src/views/cases/components/DossierGroup.vue` | New |
| `src/views/cases/components/DocumentRow.vue` | New |
| `src/views/cases/components/DocumentMetadataDialog.vue` | New |
| `src/views/cases/components/VersionHistoryPanel.vue` | New |
| `src/views/cases/components/BulkActionsBar.vue` | New |

## Out of scope

- CMIS integration with external DMS systems
- MDTO archival (covered by the `archivering-vernietiging` change)
- BIM / 3D model viewing
- OCR for scanned image documents (deferred)

## Risks

| Risk | Mitigation |
|------|-----------|
| Vertrouwelijkheidaanduiding enforcement bypass via direct OR API | `InformatieobjectAccessGuard` hooked at controller AND `FilePublishingHandler` level — UI alone is insufficient |
| Status immutability bypass via `CreateFileHandler.saveFile()` upsert | Status middleware rejects upsert when status = `definitief`; `DeleteFileHandler` returns HTTP 409 |
| ZIP export memory exhaustion on large dossiers | ZipStream streaming — never loads full dossier into memory |
| Existing linked files lack ZGW metadata | `BackfillInformatieobjectMetadata` repair step; idempotent and registered via `info.xml` repair-steps |
