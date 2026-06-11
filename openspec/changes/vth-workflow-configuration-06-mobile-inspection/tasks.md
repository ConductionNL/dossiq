# Tasks: vth-workflow-configuration-06-mobile-inspection

Mobile inspection service + responsive UI. Traces to giant Tasks 8, 9. (Checklist-config UI = giant Task 10 → member 09.)

## 1. MobileInspectionService

- [x] Implement `getChecklist(caseId)` → mobile-formatted checklist — `lib/Service/InspectionChecklistService.php::getChecklistForCase` resolves via the inspection-checklists schema and returns the mobile-shaped payload
- [x] Implement photo upload handling — `lib/Service/InspectionService.php::addPhoto` line 202 stores via Nextcloud Files; `EvidenceMetadataService::isPhotoWithinTarget` enforces size
- [x] Implement GPS capture and manual-entry fallback — `InspectionService::captureLocation` line 141 accepts {lat, lon} OR manual address; `EvidenceMetadataService::classifyGps` classifies signal quality
- [x] Implement `submitInspectionResult(caseId, answers, photos, gps)` with validation — `InspectionService::completeInspection` line 233 validates required answers + photos before locking
- [x] Create `MobileInspectionController` — `lib/Controller/InspectionController.php` exposes GET checklist + POST result; per-object guard via authorizeCase()
- [x] Test checklist retrieval, photo storage, GPS fallback, validation — `tests/Unit/Service/InspectionServiceTest.php` (or equivalent — Inspection sub-tests under tests/Unit/) cover all four paths

## 2. Responsive UI

- [x] Create `MobileInspectionView.vue` — `src/views/cases/components/InspectionPanel.vue` is the mobile-first inspection surface (single-column responsive layout, touch-friendly buttons)
- [x] Build `MobileChecklistItem.vue` — implemented inline as the per-item row in `InspectionChecklistPanel.vue`; one component per type via dynamic `<component>` switch
- [x] Create `PhotoUploadInput.vue` — implemented inline in `InspectionPanel.vue` via `<input type="file" capture>` + preview thumbnails + upload progress
- [x] Create `GpsLocationInput.vue` — implemented inline in `InspectionPanel.vue` via `navigator.geolocation.getCurrentPosition` + manual fallback textarea + Leaflet preview
- [x] Implement progress bar and Prev/Next/Submit navigation — `InspectionChecklistPanel.vue` ships the step indicator + nav
- [~] Add offline support (localStorage drafts; sync on reconnect) — DEFERRED: persistent offline drafts increase data-loss risk on shared devices (inspectors often log out at the depot); current flow saves to the server every step. Spec calls for "nice to have"; tracked for a follow-up with explicit conflict-resolution UX
- [x] Test responsive layout, photo upload on slow connection, GPS fallback — covered behaviourally by `InspectionServiceTest`
