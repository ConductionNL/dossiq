# Tasks: vth-workflow-configuration-06-mobile-inspection


> **Build status (hydra audit 2026-06-10).** `lib/Service/InspectionService.php` (`getInspections`, `captureLocation`, `addPhoto`, `completeInspection`) + `InspectionChecklistService` + `InspectionController` ship on dev. Responsive Vue mobile views are the remaining work — closely related to mobiel-inspectie-offline (PWA chain) which is the canonical home for the offline-client work.
Mobile inspection service + responsive UI. Traces to giant Tasks 8, 9. (Checklist-config UI = giant Task 10 → member 09.)

## 1. MobileInspectionService

- [x] Implement `getChecklist(caseId)` → mobile-formatted checklist (verified on dev: `lib/Service/InspectionChecklistService.php` + `InspectionService::getInspections`)
- [x] Implement photo upload handling (compress for mobile, store in Nextcloud) (verified on dev: `InspectionService::addPhoto`)
- [x] Implement GPS capture and manual-entry fallback (verified on dev: `InspectionService::captureLocation`)
- [x] Implement `submitInspectionResult(caseId, answers, photos, gps)` with required-item/photo validation (verified on dev: `InspectionService::completeInspection`)
- [x] Create `MobileInspectionController` (GET checklist, POST inspection-result) with per-object guard (verified on dev: `lib/Controller/InspectionController.php`)
- [~] Test checklist retrieval, photo storage, GPS fallback, and validation errors (deferred to vth-workflow-configuration-10-testing)

## 2. Responsive UI

- [~] Create `MobileInspectionView.vue` (responsive single-column, touch-friendly) (canonical home: mobiel-inspectie-offline PWA chain)
- [~] Build `MobileChecklistItem.vue` rendering question + type-specific input (canonical home: mobiel-inspectie-offline)
- [~] Create `PhotoUploadInput.vue` (picker, preview, compression, progress) (canonical home: mobiel-inspectie-offline)
- [~] Create `GpsLocationInput.vue` (capture button, manual fallback, map preview) (canonical home: mobiel-inspectie-offline)
- [~] Implement progress bar and Prev/Next/Submit navigation (canonical home: mobiel-inspectie-offline)
- [~] Add offline support (localStorage drafts; sync on reconnect) (canonical home: mobiel-inspectie-offline)
- [~] Test responsive layout, photo upload on slow connection, and GPS capture/fallback (deferred to vth-workflow-configuration-10-testing)
