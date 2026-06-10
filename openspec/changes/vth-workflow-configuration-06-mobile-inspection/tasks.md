# Tasks: vth-workflow-configuration-06-mobile-inspection


> **Build status (hydra audit 2026-06-10).** `lib/Service/InspectionService.php` (`getInspections`, `captureLocation`, `addPhoto`, `completeInspection`) + `InspectionChecklistService` + `InspectionController` ship on dev. Responsive Vue mobile views are the remaining work — closely related to mobiel-inspectie-offline (PWA chain) which is the canonical home for the offline-client work.
Mobile inspection service + responsive UI. Traces to giant Tasks 8, 9. (Checklist-config UI = giant Task 10 → member 09.)

## 1. MobileInspectionService

- [ ] Implement `getChecklist(caseId)` → mobile-formatted checklist
- [ ] Implement photo upload handling (compress for mobile, store in Nextcloud)
- [ ] Implement GPS capture and manual-entry fallback
- [ ] Implement `submitInspectionResult(caseId, answers, photos, gps)` with required-item/photo validation
- [ ] Create `MobileInspectionController` (GET checklist, POST inspection-result) with per-object guard
- [ ] Test checklist retrieval, photo storage, GPS fallback, and validation errors

## 2. Responsive UI

- [ ] Create `MobileInspectionView.vue` (responsive single-column, touch-friendly)
- [ ] Build `MobileChecklistItem.vue` rendering question + type-specific input
- [ ] Create `PhotoUploadInput.vue` (picker, preview, compression, progress)
- [ ] Create `GpsLocationInput.vue` (capture button, manual fallback, map preview)
- [ ] Implement progress bar and Prev/Next/Submit navigation
- [ ] Add offline support (localStorage drafts; sync on reconnect)
- [ ] Test responsive layout, photo upload on slow connection, and GPS capture/fallback
