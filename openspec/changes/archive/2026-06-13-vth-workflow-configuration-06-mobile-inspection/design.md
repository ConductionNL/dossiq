# Design: vth-workflow-configuration-06-mobile-inspection

## Architecture

`kind: code` member (ADR-032). Backend service + controller (ADR-003) consuming the inspection checklists declared by member 01 (ADR-031). Vue responsive UI (ADR-004). Objects (inspectionResult, photos) persisted via OpenRegister/FileService (ADR-001/ADR-022).

## Service Layout

- `MobileInspectionService.getChecklist(caseId)` → mobile-formatted checklist from the member-01 checklist data.
- Photo upload: compress for mobile, store via Nextcloud FileService, record file ID.
- GPS capture with manual-entry fallback.
- `submitInspectionResult(caseId, answers, photos, gps)` validates required items/photos, creates an inspectionResult.

## UI

`MobileInspectionView.vue` (single-column, touch-friendly), `MobileChecklistItem.vue` (type-specific input), `PhotoUploadInput.vue` (picker, preview, compression), `GpsLocationInput.vue` (capture + manual fallback + map preview). Progress bar, Prev/Next/Submit. Offline drafts in localStorage, synced on reconnect.

## Security (ADR-005)

Checklist retrieval and submission validate the inspector's access to the toezichtzaak (per-object guard). Uploaded photos go through Nextcloud's file permissions; GPS/answers are validated server-side on submit.
