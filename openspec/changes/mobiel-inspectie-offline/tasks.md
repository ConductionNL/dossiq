# Tasks: mobiel-inspectie-offline

## 1. PWA Infrastructure and Service Worker

### Task 1: Set up PWA manifest and install behavior
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/manifest.json`, `public/service-worker.js`, `lib/Util/PWABootstrapper.php`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN user opens app in mobile browser THEN "Add to Home Screen" prompt appears
  - GIVEN app is installed WHEN device goes offline THEN splash screen or home screen loads from cache
  - Service Worker is registered, scoped to `/apps/procest/`, with update-check every 24 hours
- [ ] Create manifest.json with icons, theme colors, start_url, display: standalone
- [ ] Implement service-worker.js with install, activate, fetch event handlers
- [ ] Register service worker in main layout template
- [ ] Add PWABootstrapper to warm-up cache on first install

### Task 2: Implement offline cache strategy with Workbox
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/service-worker.js`, `lib/Service/CacheStrategy.php`
- **acceptance_criteria**:
  - GIVEN network-first routes (API calls) WHEN offline THEN cached response returned if available
  - GIVEN static-asset cache-first WHEN offline THEN JS/CSS loaded from cache
  - GIVEN stale-while-revalidate on slow 2G connection THEN cached response shown immediately, refresh in background
- [ ] Configure Workbox routing for API endpoints (network-first + fallback)
- [ ] Configure static assets (cache-first) for JS, CSS, images
- [ ] Implement cache versioning and cleanup for stale entries
- [ ] Add offline fallback page for 404/500 errors

## 2. IndexedDB Data Layer and Sync Queue

### Task 3: Define IndexedDB schema and Dexie.js models
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `src/store/db.js`, `src/store/models.js`
- **acceptance_criteria**:
  - GIVEN app initialization WHEN Dexie.js opens database THEN all 6 tables created with correct schema
  - GIVEN FieldInspection record WHEN queried by caseRef THEN returns in <100ms (indexed)
  - GIVEN SyncQueue record WHEN status updates THEN atomic write confirms
- [ ] Define Dexie database schema with tables: fieldInspection, checklistResult, fieldEvidence, checklistTemplate, syncQueue, conflictRecord
- [ ] Add indexes: caseRef, deviceId, inspectionRef, status, queuedAt
- [ ] Implement open/close, transaction, and error-handling logic

### Task 4: Implement SyncQueue entity in OpenRegister schema
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Settings/procest_register.json`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN repair step runs THEN SyncQueue schema registered in OpenRegister
  - GIVEN SyncQueue operation WHEN 409 Conflict received THEN ConflictRecord created with server/client versions
- [ ] Add SyncQueue schema: id, deviceId, operationType, targetEntity, targetId, payload, queuedAt, status, attemptCount, lastError
- [ ] Add ConflictRecord schema: id, syncQueueRef, serverVersion, clientVersion, conflictType, resolution, resolvedBy, resolvedAt
- [ ] Add FieldInspection, FieldEvidence, ChecklistResult schemas
- [ ] Update ChecklistTemplate schema with domain, items array structure

## 3. Daily Sync Download

### Task 5: Create DailySyncService for case and checklist pre-download
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `lib/Service/DailySyncService.php`, `lib/Controller/SyncController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN inspector taps "Dag synchroniseren" WHEN network is available THEN GET /api/sync/daily returns: cases[], checklists[], documents[], mapTiles with download manifest
  - GIVEN incomplete download WHEN network drops and reconnects THEN resume from last checkpoint (chunked transfer)
  - GIVEN slow connection WHEN 48MB sync needed THEN display time estimate and allow cancel
- [ ] Implement DailySyncService.getDailySchedule() returning cases for today (inspectorRef, status=planned)
- [ ] Implement DailySyncService.getChecklistTemplates() returning referenced checklists with full items
- [ ] Implement DailySyncService.getHistoricalDocuments() returning linked documents per case
- [ ] Implement resumable/chunked download with checkpoint tracking
- [ ] Register route POST /api/sync/daily and GET /api/sync/daily/status

### Task 6: Implement map tile pre-download for offline viewing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `lib/Service/MapTileService.php`, `src/utils/mapTileCache.js`
- **acceptance_criteria**:
  - GIVEN daily sync WHEN calculating case addresses THEN PDOK BRT tiles downloaded for zoom 10-18 in 20km radius
  - GIVEN offline view WHEN map component initializes THEN tiles served from IndexedDB cache
  - GIVEN tile cache >30 days old WHEN sync reruns THEN tiles re-downloaded
- [ ] Implement MapTileService.downloadTiles(bounds, zoomLevels) querying PDOK WMTS API
- [ ] Store tiles in IndexedDB with cache key scheme: z/x/y.{format}
- [ ] Implement cache-first fetch in service worker for tile requests
- [ ] Track cache version and expiry timestamp for stale-tile cleanup

## 4. GPS and Evidence Capture

### Task 7: Implement GeolocationService with accuracy validation and fallback
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork`
- **files**: `src/services/geolocationService.js`, `src/components/LocationWarning.vue`
- **acceptance_criteria**:
  - GIVEN GPS available with ±8m accuracy WHEN capturing evidence THEN coordinates embedded with accuracy=8
  - GIVEN GPS accuracy >50m WHEN answering checklist THEN warning displayed: "Locatie onnauwkeurig"
  - GIVEN GPS unavailable WHEN action queued THEN fallback to case address with gpsSource=sensorless flag
- [ ] Implement Geolocation API wrapper with timeout and error handling
- [ ] Add accuracy validation: warn if >50m, fail if completely unavailable
- [ ] Implement fallback to case address from FieldInspection.caseRef lookup
- [ ] Build LocationWarning component showing accuracy and manual-override option

### Task 8: Implement photo capture and client-side compression
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-photo-capture-with-client-side-compression-and-exif-metadata`
- **files**: `src/services/photoService.js`, `src/components/PhotoCapture.vue`, `lib/Util/ExifEncoder.php`
- **acceptance_criteria**:
  - GIVEN photo captured (4MB native) WHEN compressed THEN result ≤2MB and quality acceptable
  - GIVEN compressed photo WHEN examined THEN EXIF metadata includes GPS, timestamp, inspectorId, caseRef, deviceId
  - GIVEN 5 photos captured offline WHEN sync THEN all 5 queued as upload operations
- [ ] Implement PhotoCapture component using Web Camera API or native file input
- [ ] Add canvas-based compression: JPEG quality 80, max-width 1920px, max-height 1440px
- [ ] Implement EXIF metadata encoder: gps:lat/lon, Image:DateTime, custom UserComment with context JSON
- [ ] Store blob in IndexedDB as FieldEvidence record with localBlobRef
- [ ] Queue upload SyncQueue operation

### Task 9: Implement voice memo recording and queue for transcription
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-voice-memo-recording-and-transcription-queueing`
- **files**: `src/services/voiceMemoService.js`, `src/components/VoiceMemoRecorder.vue`, `lib/Service/TranscriptionService.php`
- **acceptance_criteria**:
  - GIVEN inspector taps "Opnemen" WHEN recording (max 5min) THEN audio stored in Opus codec in IndexedDB
  - GIVEN offline recording WHEN sync completes THEN transcription queued to qwen-3.5 LLM
  - GIVEN transcription completes WHEN sync continues THEN text stored in FieldEvidence.transcription and status=synced
- [ ] Implement VoiceMemoRecorder component using MediaRecorder API with Opus codec
- [ ] Add duration timer and max-5min cutoff
- [ ] Store blob in IndexedDB as FieldEvidence with type=voice_memo
- [ ] Implement TranscriptionService.transcribeVoiceMemo(evidenceId, audioBlob) querying qwen LLM endpoint
- [ ] Queue transcription operation with fallback to manual transcription if LLM unavailable

## 5. Checklist Completion Offline

### Task 10: Implement offline checklist rendering and answer storage
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/components/ChecklistView.vue`, `src/services/checklistService.js`, `lib/Service/ChecklistService.php`
- **acceptance_criteria**:
  - GIVEN checklist template loaded to IndexedDB WHEN inspector opens ChecklistView THEN all items rendered with question text, type (yes_no/scale/text/photo_required), required flag
  - GIVEN required=false item WHEN inspector skips THEN OK; when required=true and empty THEN validation error blocks save
  - GIVEN answer stored offline WHEN ChecklistResult created THEN atomic write to IndexedDB includes: questionId, answer, answeredAt, gpsAtAnswer, evidenceRefs[]
- [ ] Build ChecklistView component: load template from IndexedDB, render items sequentially or in flat view
- [ ] Implement answer-storage logic: validate required fields, prevent empty submission for required items
- [ ] Store ChecklistResult record in IndexedDB atomic transaction
- [ ] Queue SyncQueue operation of type "create" for the ChecklistResult
- [ ] Implement progress bar showing N/M items completed

### Task 11: Build checklist admin UI for template management
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/views/settings/tabs/ChecklistsTab.vue`, `src/components/ChecklistTemplateEditor.vue`, `lib/Controller/ChecklistAdminController.php`
- **acceptance_criteria**:
  - GIVEN admin opens Checklists settings WHEN viewing list THEN all templates shown with name, domain, version, last-edit date
  - GIVEN admin clicks "Nieuw" WHEN creating template THEN editor opens; can add items with question text, type, required, helpText, conditionalOn
  - GIVEN template saved WHEN version incremented THEN old version retained for historical inspection reports
- [ ] Create ChecklistsTab in settings navigation
- [ ] Implement ChecklistTemplateEditor: add/edit/delete items, set required/conditional logic, type picker
- [ ] Build item sub-form for photo_required type (upload max 5 evidence files)
- [ ] Register admin CRUD routes

## 6. Sync Queue Replay and Conflict Resolution

### Task 12: Implement SyncQueueReplayService with exponential backoff
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Service/SyncQueueReplayService.php`, `src/services/syncReplayService.js`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN 23 SyncQueue operations pending WHEN network reconnects THEN replay initiates automatically
  - GIVEN operation #5 fails with 503 WHEN retrying THEN backoff sequence: 1s, 5s, 30s, 5min, 30min
  - GIVEN operation succeeds WHEN status updated THEN SyncQueue.status = synced, automatic deletion after 7 days
  - GIVEN 5 failed retries WHEN max attempts exceeded THEN operation moved to `failed` status, logged for manual review
- [ ] Implement SyncQueueReplayService.replayAll() fetching pending operations in queuedAt order
- [ ] Implement exponential backoff calculation with jitter
- [ ] Update SyncQueue records: attemptCount++, lastAttemptAt, lastError on failure
- [ ] Implement automatic cleanup of synced records after 7 days
- [ ] Register replay triggers: network-reconnection event, manual retry, periodic background sync (if P4)
- [ ] Display progress bar in UI: "Synchroniseren: 14/23"

### Task 13: Implement conflict detection and ConflictRecord creation
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `lib/Service/ConflictDetectionService.php`, `lib/Controller/ConflictController.php`
- **acceptance_criteria**:
  - GIVEN SyncQueue operation receives 409 Conflict from OR WHEN handling response THEN ConflictRecord created with: syncQueueRef, clientVersion, serverVersion, conflictType, initial resolution=null
  - GIVEN ConflictRecord persisted WHEN inspector views case THEN conflict badge shows ("1 conflict")
  - GIVEN 409 received AND inspectorRef lost permission WHEN handling response THEN ConflictRecord.conflictType = permission_lost (not retryable)
- [ ] Implement 409 response handler in SyncQueueReplayService
- [ ] Query OR to fetch serverVersion of conflicted entity
- [ ] Create ConflictRecord in both local IndexedDB (for UI) and OpenRegister (for audit trail)
- [ ] Tag SyncQueue operation with ConflictRecord reference
- [ ] Implement permission-lost detection (403 response)

### Task 14: Build conflict resolution merge UI
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `src/components/ConflictResolver.vue`, `src/services/conflictResolutionService.js`
- **acceptance_criteria**:
  - GIVEN ConflictRecord exists WHEN inspector opens case or views Pending Sync THEN ConflictResolver dialog shown
  - GIVEN side-by-side diff WHEN inspector reviews THEN both versions clearly labeled (Mijn versie / Serverversie) with timestamps and actor names
  - GIVEN inspector chooses "Mijn versie" WHEN submitting THEN POST /api/conflicts/{id}/resolve with resolution=client_wins; retry operation with force-update flag
- [ ] Build ConflictResolver modal showing field-level differences (diff view or text comparison)
- [ ] Add three resolution buttons: "Mijn versie", "Serverversie", "Handmatig samenvoegen"
- [ ] Implement resolution submission: POST /api/conflicts/{id}/resolve with choice
- [ ] On client_wins: retry SyncQueue operation with force-override flag
- [ ] On server_wins: discard local changes, mark SyncQueue as synced, update local IndexedDB with server version
- [ ] On manual_merge: show three-way merge editor (local, server, manual) with field selection

### Task 15: Implement conflict resolution audit logging (AVG compliance)
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-resolution-logging-and-avg-compliance`
- **files**: `lib/Service/AuditService.php`, `src/components/DataProcessingNotice.vue`
- **acceptance_criteria**:
  - GIVEN conflict resolution submitted WHEN recorded THEN immutable audit entry created with: timestamp, actor, action=conflict_resolution, details (JSON snapshot of both versions), resolution choice, justification
  - GIVEN app first launch WHEN opening THEN data-processing notice displayed; require consent before sync
  - GIVEN consent recorded WHEN stored THEN audit entry captures user timestamp and acceptance
- [ ] Extend AuditService to log conflict_resolution actions with version snapshots
- [ ] Implement immutability: audit entries never updated/deleted, only appended
- [ ] Build DataProcessingNotice component with PIA text, encryption option, explicit opt-in
- [ ] Store consent timestamp in audit trail on first app initialization

## 7. Map Drawing and Annotations

### Task 16: Implement map drawing tools for sketch annotations
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `src/components/MapView.vue`, `src/services/mapDrawingService.js`, `src/components/DrawingToolbar.vue`
- **acceptance_criteria**:
  - GIVEN offline map displayed WHEN inspector taps "Annotatie toevoegen" THEN drawing toolbar appears (polygon, point, line tools)
  - GIVEN inspector draws polygon WHEN completed THEN shape captured as GeoJSON, stored as FieldEvidence with type=sketch, queued for sync
  - GIVEN 3 sketches drawn offline WHEN sync completes THEN all linked to case's FieldEvidence collection
- [ ] Build DrawingToolbar component with tools: point, line, polygon, eraser
- [ ] Implement Leaflet/MapLibre drawing integration (use existing library or implement)
- [ ] Store drawn shapes as GeoJSON in FieldEvidence records
- [ ] Capture center coordinates and timestamp
- [ ] Queue sketch upload operations for sync

## 8. Integration and Testing

### Task 17: Integrate sync endpoints with OpenConnector routing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Controller/SyncController.php`, `lib/Service/SyncQueueReplayService.php`, `openconnector/routes.php`
- **acceptance_criteria**:
  - GIVEN sync replay WHEN SyncQueue operation targets a case THEN OpenConnector routes the update to the correct OR register and schema
  - GIVEN bulk sync (100 operations) WHEN replaying THEN no timeout (use async/job-queue if needed)
  - GIVEN sync operation WHEN result = success THEN webhook notification sent to Pipelinq (trigger downstream actions)
- [ ] Register SyncQueueReplayService routes in procest app: POST /api/sync-queue/{id}/replay, GET /api/sync-queue, POST /api/conflicts/{id}/resolve
- [ ] Implement routing to OpenConnector for entity-type routing (ChecklistResult → procest register, FieldEvidence → procest register, etc.)
- [ ] Wire sync-completion webhook to Pipelinq: send event "inspectie_afgerond" on ChecklistResult completion
- [ ] Implement timeout handling for large-batch syncs (consider background job queue)

### Task 18: End-to-end functional testing of offline workflow
- **spec_ref**: All requirements
- **files**: `tests/Functional/OfflineWorkflowTest.php`, `tests/Integration/SyncQueueTest.php`
- **acceptance_criteria**:
  - TEST: Inspector syncs 5 cases offline → goes offline → answers checklists (3), adds photos (2), records memos (1) → reconnects → all 6 operations replay successfully → case data updated
  - TEST: Conflict scenario: colleague edits case while inspector offline → inspector's update receives 409 → ConflictRecord created → inspector resolves → operation retries with force-flag
  - TEST: Permission loss: inspector loses read access while offline → 403 response → operation marked failed (not retried) → error logged
- [ ] Write integration test suite for offline scenarios (using in-memory OR, fake network simulation)
- [ ] Test SyncQueue replay with various failure modes (503, timeout, connection drop mid-replay)
- [ ] Test conflict resolution with multiple resolution choices (client_wins, server_wins, manual_merge)
- [ ] Test permission-lost and permission-regained scenarios
- [ ] Test compass/geolocation fallback when GPS unavailable
- [ ] Test map tile pre-download and offline rendering
- [ ] Test checklist required-field validation and photo-evidence linking

### Task 19: Performance optimization and load testing
- **spec_ref**: All requirements
- **files**: `tests/Performance/SyncPerformanceTest.php`
- **acceptance_criteria**:
  - TEST: 100 pending operations replay in <2min (average 1.2s per operation)
  - TEST: Photo compression 4MB→1.8MB in <3s on mid-range device
  - TEST: IndexedDB queries (FieldInspection by caseRef) return in <100ms with 1000 records
  - TEST: Service Worker install and cache-building complete in <10s on 3G connection
- [ ] Benchmark SyncQueueReplayService with 100+ operations
- [ ] Profile photo compression (test on actual device, not just browser)
- [ ] Load-test IndexedDB query performance with realistic data volume
- [ ] Test cache-building performance on slow connections (throttle to 1Mbps)
- [ ] Optimize bottlenecks identified (e.g., parallelized photo uploads, chunked sync batches)
