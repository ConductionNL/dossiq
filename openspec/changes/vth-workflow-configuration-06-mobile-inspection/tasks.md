# Tasks: vth-workflow-configuration-06-mobile-inspection

Mobile inspection service + responsive UI. Traces to giant Tasks 8, 9. (Checklist-config UI = giant Task 10 → member 09.)

## 1. MobileInspectionService

- [~] Implement `getChecklist(caseId)` → mobile-formatted checklist — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement photo upload handling (compress for mobile, store in Nextcloud) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement GPS capture and manual-entry fallback — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `submitInspectionResult(caseId, answers, photos, gps)` with required-item/photo validation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `MobileInspectionController` (GET checklist, POST inspection-result) with per-object guard — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test checklist retrieval, photo storage, GPS fallback, and validation errors — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Responsive UI

- [~] Create `MobileInspectionView.vue` (responsive single-column, touch-friendly) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `MobileChecklistItem.vue` rendering question + type-specific input — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `PhotoUploadInput.vue` (picker, preview, compression, progress) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `GpsLocationInput.vue` (capture button, manual fallback, map preview) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement progress bar and Prev/Next/Submit navigation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add offline support (localStorage drafts; sync on reconnect) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test responsive layout, photo upload on slow connection, and GPS capture/fallback — deferred to downstream cycle / fleet-wide adoption (handoff)
