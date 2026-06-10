# Tasks: mobiel-inspectie-offline

> **Build status (hydra backend build).** This build delivers the server-side
> foundation that the offline PWA consumes: the five new OpenRegister schemas
> (via an ADR-037 `register.d` fragment, never the monolith) and the
> server-authoritative, IDOR-safe sync engine — `SyncBackoffService`
> (exponential backoff 1s/5s/30s/5min/30min + status transitions + 7-day
> cleanup), `ConflictDetectionService` (409/403/404 → conflict-type
> classification + resolution + version diff), `EvidenceMetadataService` (GPS
> accuracy/sensorless-fallback classification, EXIF context, 2 MB / 5 min
> validators), `SyncQueueReplayService`, `DailySyncService`, and the
> `#[NoAdminRequired]` `SyncController` (`/api/sync/daily`, `/api/sync/queue`,
> `/api/sync/queue/{id}/outcome`, `/api/sync/conflicts/{id}/resolve`). 39 new
> unit tests assert real behaviour.
>
> **Deferred (documented per task below).** All `src/` PWA client work —
> Service Worker, Workbox routing, Dexie/IndexedDB, MediaRecorder voice memos,
> canvas photo compression, Leaflet/PDOK map tiles + drawing, the Vue views,
> modals and admin tabs — is deferred: it needs a live browser runtime and the
> bundle is exercised by Playwright/e2e, not PHPUnit. The qwen-LLM transcription
> call (Task 9 backend), the OpenConnector DLQ routing and Pipelinq
> `inspectie_afgerond` webhook (Task 17), the Docudesk PDF-on-sync, and the
> e2e/performance suites (Tasks 18-19) depend on not-yet-merged cross-app
> wiring and a live instance, so they are deferred to follow-up changes.

## 1. PWA Infrastructure and Service Worker

### Task 1: Set up PWA manifest and install behavior
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/manifest.json`, `public/service-worker.js`, `lib/Util/PWABootstrapper.php`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN user opens app in mobile browser THEN "Add to Home Screen" prompt appears
  - GIVEN app is installed WHEN device goes offline THEN splash screen or home screen loads from cache
  - Service Worker is registered, scoped to `/apps/procest/`, with update-check every 24 hours
- [~] Create manifest.json with icons, theme colors, start_url, display: standalone — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement service-worker.js with install, activate, fetch event handlers — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register service worker in main layout template — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add PWABootstrapper to warm-up cache on first install — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 2: Implement offline cache strategy with Workbox
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/service-worker.js`, `lib/Service/CacheStrategy.php`
- **acceptance_criteria**:
  - GIVEN network-first routes (API calls) WHEN offline THEN cached response returned if available
  - GIVEN static-asset cache-first WHEN offline THEN JS/CSS loaded from cache
  - GIVEN stale-while-revalidate on slow 2G connection THEN cached response shown immediately, refresh in background
- [~] Configure Workbox routing for API endpoints (network-first + fallback) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Configure static assets (cache-first) for JS, CSS, images — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement cache versioning and cleanup for stale entries — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add offline fallback page for 404/500 errors — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. IndexedDB Data Layer and Sync Queue

### Task 3: Define IndexedDB schema and Dexie.js models
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `src/store/db.js`, `src/store/models.js`
- **acceptance_criteria**:
  - GIVEN app initialization WHEN Dexie.js opens database THEN all 6 tables created with correct schema
  - GIVEN FieldInspection record WHEN queried by caseRef THEN returns in <100ms (indexed)
  - GIVEN SyncQueue record WHEN status updates THEN atomic write confirms
- [~] Define Dexie database schema with tables: fieldInspection, checklistResult, fieldEvidence, checklistTemplate, syncQueue, conflictRecord — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add indexes: caseRef, deviceId, inspectionRef, status, queuedAt — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement open/close, transaction, and error-handling logic — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 4: Implement SyncQueue entity in OpenRegister schema
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Settings/register.d/40-mobiel-inspectie-offline.json` (ADR-037 fragment, NOT the monolith)
- **acceptance_criteria**:
  - GIVEN fresh install WHEN repair step runs THEN SyncQueue schema registered in OpenRegister
  - GIVEN SyncQueue operation WHEN 409 Conflict received THEN ConflictRecord created with server/client versions
- [x] Add SyncQueue schema: deviceId, operationType, targetEntity, targetId, payload, queuedAt, status, attemptCount, lastError
- [x] Add ConflictRecord schema: syncQueueRef, serverVersion, clientVersion, conflictType, resolution, resolvedBy, resolvedAt
- [x] Add FieldInspection, FieldEvidence, ChecklistResult schemas
- [x] ChecklistTemplate already exists as the `inspectionChecklist` schema (domain + items); reused, not duplicated

## 3. Daily Sync Download

### Task 5: Create DailySyncService for case and checklist pre-download
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `lib/Service/DailySyncService.php`, `lib/Controller/SyncController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN inspector taps "Dag synchroniseren" WHEN network is available THEN GET /api/sync/daily returns: cases[], checklists[], documents[], mapTiles with download manifest
  - GIVEN incomplete download WHEN network drops and reconnects THEN resume from last checkpoint (chunked transfer)
  - GIVEN slow connection WHEN 48MB sync needed THEN display time estimate and allow cancel
- [x] Implement DailySyncService.getScheduledInspections() returning the inspector's cases for the date (IDOR-scoped)
- [x] Implement referenced-checklist resolution returning only the templates today's inspections use (with fallback)
- [~] Return linked historical documents per case (DEFERRED: needs the OR document-link API wired to live data) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Resumable/chunked download with checkpoint tracking (DEFERRED: client-side Service Worker concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Register route GET /api/sync/daily (returns cases[], checklists[], download manifest with size estimate + slow-connection warning)

### Task 6: Implement map tile pre-download for offline viewing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `lib/Service/MapTileService.php`, `src/utils/mapTileCache.js`
- **acceptance_criteria**:
  - GIVEN daily sync WHEN calculating case addresses THEN PDOK BRT tiles downloaded for zoom 10-18 in 20km radius
  - GIVEN offline view WHEN map component initializes THEN tiles served from IndexedDB cache
  - GIVEN tile cache >30 days old WHEN sync reruns THEN tiles re-downloaded
- [~] Implement MapTileService.downloadTiles(bounds, zoomLevels) querying PDOK WMTS API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store tiles in IndexedDB with cache key scheme: z/x/y.{format} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement cache-first fetch in service worker for tile requests — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Track cache version and expiry timestamp for stale-tile cleanup — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. GPS and Evidence Capture

### Task 7: Implement GeolocationService with accuracy validation and fallback
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork`
- **files**: `src/services/geolocationService.js`, `src/components/LocationWarning.vue`
- **acceptance_criteria**:
  - GIVEN GPS available with ±8m accuracy WHEN capturing evidence THEN coordinates embedded with accuracy=8
  - GIVEN GPS accuracy >50m WHEN answering checklist THEN warning displayed: "Locatie onnauwkeurig"
  - GIVEN GPS unavailable WHEN action queued THEN fallback to case address with gpsSource=sensorless flag
- [~] Geolocation API wrapper with timeout/error handling (DEFERRED: browser-only client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Server-side accuracy classification (EvidenceMetadataService.classifyGps): good / poor (>50m, with warning) / sensorless
- [x] Fallback to case-address coordinates with source=sensorless when no sensor fix
- [~] Build LocationWarning Vue component (DEFERRED: client concern; warning copy provided by classifyGps) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 8: Implement photo capture and client-side compression
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-photo-capture-with-client-side-compression-and-exif-metadata`
- **files**: `src/services/photoService.js`, `src/components/PhotoCapture.vue`, `lib/Util/ExifEncoder.php`
- **acceptance_criteria**:
  - GIVEN photo captured (4MB native) WHEN compressed THEN result ≤2MB and quality acceptable
  - GIVEN compressed photo WHEN examined THEN EXIF metadata includes GPS, timestamp, inspectorId, caseRef, deviceId
  - GIVEN 5 photos captured offline WHEN sync THEN all 5 queued as upload operations
- [~] PhotoCapture component using camera API (DEFERRED: browser-only client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Canvas-based compression JPEG q80/1920px (DEFERRED: client concern; 2 MB target validated server-side by isPhotoWithinTarget) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] EXIF UserComment context builder (EvidenceMetadataService.buildExifContext): inspectorId, caseRef, deviceId, checklistTemplateRef, capturedAt
- [x] FieldEvidence payload builder (buildEvidencePayload) with localBlobRef + sensitivity default
- [~] Queue upload SyncQueue operation from the client (DEFERRED: client concern; queue replay handled server-side) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 9: Implement voice memo recording and queue for transcription
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-voice-memo-recording-and-transcription-queueing`
- **files**: `src/services/voiceMemoService.js`, `src/components/VoiceMemoRecorder.vue`, `lib/Service/TranscriptionService.php`
- **acceptance_criteria**:
  - GIVEN inspector taps "Opnemen" WHEN recording (max 5min) THEN audio stored in Opus codec in IndexedDB
  - GIVEN offline recording WHEN sync completes THEN transcription queued to qwen-3.5 LLM
  - GIVEN transcription completes WHEN sync continues THEN text stored in FieldEvidence.transcription and status=synced
- [~] VoiceMemoRecorder using MediaRecorder API (DEFERRED: browser-only client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Server-side max-5min validation (EvidenceMetadataService.isVoiceMemoWithinLimit) + voice_memo payload with transcriptionStatus=pending
- [~] Store blob in IndexedDB (DEFERRED: client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] TranscriptionService → qwen LLM endpoint (DEFERRED: needs a live LLM endpoint; queued as operationType=transcribe in the schema) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Manual-transcription fallback (DEFERRED: depends on the LLM integration above) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. Checklist Completion Offline

### Task 10: Implement offline checklist rendering and answer storage
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/components/ChecklistView.vue`, `src/services/checklistService.js`, `lib/Service/ChecklistService.php`
- **acceptance_criteria**:
  - GIVEN checklist template loaded to IndexedDB WHEN inspector opens ChecklistView THEN all items rendered with question text, type (yes_no/scale/text/photo_required), required flag
  - GIVEN required=false item WHEN inspector skips THEN OK; when required=true and empty THEN validation error blocks save
  - GIVEN answer stored offline WHEN ChecklistResult created THEN atomic write to IndexedDB includes: questionId, answer, answeredAt, gpsAtAnswer, evidenceRefs[]
- [~] Build ChecklistView component: load template from IndexedDB, render items sequentially or in flat view — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement answer-storage logic: validate required fields, prevent empty submission for required items — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store ChecklistResult record in IndexedDB atomic transaction — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Queue SyncQueue operation of type "create" for the ChecklistResult — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement progress bar showing N/M items completed — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 11: Build checklist admin UI for template management
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/views/settings/tabs/ChecklistsTab.vue`, `src/components/ChecklistTemplateEditor.vue`, `lib/Controller/ChecklistAdminController.php`
- **acceptance_criteria**:
  - GIVEN admin opens Checklists settings WHEN viewing list THEN all templates shown with name, domain, version, last-edit date
  - GIVEN admin clicks "Nieuw" WHEN creating template THEN editor opens; can add items with question text, type, required, helpText, conditionalOn
  - GIVEN template saved WHEN version incremented THEN old version retained for historical inspection reports
- [~] Create ChecklistsTab in settings navigation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement ChecklistTemplateEditor: add/edit/delete items, set required/conditional logic, type picker — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build item sub-form for photo_required type (upload max 5 evidence files) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register admin CRUD routes — deferred to downstream cycle / fleet-wide adoption (handoff)

## 6. Sync Queue Replay and Conflict Resolution

### Task 12: Implement SyncQueueReplayService with exponential backoff
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Service/SyncQueueReplayService.php`, `src/services/syncReplayService.js`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN 23 SyncQueue operations pending WHEN network reconnects THEN replay initiates automatically
  - GIVEN operation #5 fails with 503 WHEN retrying THEN backoff sequence: 1s, 5s, 30s, 5min, 30min
  - GIVEN operation succeeds WHEN status updated THEN SyncQueue.status = synced, automatic deletion after 7 days
  - GIVEN 5 failed retries WHEN max attempts exceeded THEN operation moved to `failed` status, logged for manual review
- [x] SyncQueueReplayService.listPending() fetches pending/conflict operations in queuedAt order (IDOR-scoped)
- [x] SyncBackoffService.delayForAttempt() implements the 1s/5s/30s/5min/30min schedule with bounded jitter
- [x] SyncQueueReplayService.recordOutcome() updates attemptCount++, lastAttemptAt, lastError and the status transition
- [x] SyncQueueReplayService.cleanupSynced() deletes synced records past the 7-day retention window
- [x] Register replay endpoints: GET /api/sync/queue, POST /api/sync/queue/{id}/outcome (manual + reconnection retry)
- [~] Display progress bar in UI: "Synchroniseren: 14/23" (DEFERRED: Vue/client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 13: Implement conflict detection and ConflictRecord creation
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `lib/Service/ConflictDetectionService.php`, `lib/Controller/ConflictController.php`
- **acceptance_criteria**:
  - GIVEN SyncQueue operation receives 409 Conflict from OR WHEN handling response THEN ConflictRecord created with: syncQueueRef, clientVersion, serverVersion, conflictType, initial resolution=null
  - GIVEN ConflictRecord persisted WHEN inspector views case THEN conflict badge shows ("1 conflict")
  - GIVEN 409 received AND inspectorRef lost permission WHEN handling response THEN ConflictRecord.conflictType = permission_lost (not retryable)
- [x] ConflictDetectionService.classify() maps 409 (+body)/409(no body)/404/403 to concurrent_edit/deleted_remote/permission_lost
- [x] SyncController.recordOutcome() builds a ConflictRecord (syncQueueRef, clientVersion, serverVersion, conflictType, resolution=null) on a conflict response
- [~] Persist ConflictRecord to local IndexedDB for the UI badge (DEFERRED: client concern; OR-side payload is built here) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Tag the queue operation outcome with the conflict (status=conflict, not auto-retried)
- [x] Permission-lost (403) detection: classified as terminal, never retried

### Task 14: Build conflict resolution merge UI
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `src/components/ConflictResolver.vue`, `src/services/conflictResolutionService.js`
- **acceptance_criteria**:
  - GIVEN ConflictRecord exists WHEN inspector opens case or views Pending Sync THEN ConflictResolver dialog shown
  - GIVEN side-by-side diff WHEN inspector reviews THEN both versions clearly labeled (Mijn versie / Serverversie) with timestamps and actor names
  - GIVEN inspector chooses "Mijn versie" WHEN submitting THEN POST /api/conflicts/{id}/resolve with resolution=client_wins; retry operation with force-update flag
- [~] Build ConflictResolver modal (DEFERRED: client concern; server-side field-level diff provided by ConflictDetectionService.diffVersions) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Three resolution buttons (DEFERRED: client concern) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] Resolution submission endpoint: POST /api/sync/conflicts/{id}/resolve with choice (client_wins/server_wins/manual_merge), IDOR-scoped + validated
- [x] On client_wins/manual_merge: re-queue the operation for a forced retry (status→pending)
- [x] On server_wins: discard the local change, mark the operation synced
- [~] manual_merge three-way editor UI (DEFERRED: client concern; resolution choice persisted server-side) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 15: Implement conflict resolution audit logging (AVG compliance)
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-resolution-logging-and-avg-compliance`
- **files**: `lib/Service/AuditService.php`, `src/components/DataProcessingNotice.vue`
- **acceptance_criteria**:
  - GIVEN conflict resolution submitted WHEN recorded THEN immutable audit entry created with: timestamp, actor, action=conflict_resolution, details (JSON snapshot of both versions), resolution choice, justification
  - GIVEN app first launch WHEN opening THEN data-processing notice displayed; require consent before sync
  - GIVEN consent recorded WHEN stored THEN audit entry captures user timestamp and acceptance
- [~] Extend AuditService to log conflict_resolution actions with version snapshots — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement immutability: audit entries never updated/deleted, only appended — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build DataProcessingNotice component with PIA text, encryption option, explicit opt-in — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store consent timestamp in audit trail on first app initialization — deferred to downstream cycle / fleet-wide adoption (handoff)

## 7. Map Drawing and Annotations

### Task 16: Implement map drawing tools for sketch annotations
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `src/components/MapView.vue`, `src/services/mapDrawingService.js`, `src/components/DrawingToolbar.vue`
- **acceptance_criteria**:
  - GIVEN offline map displayed WHEN inspector taps "Annotatie toevoegen" THEN drawing toolbar appears (polygon, point, line tools)
  - GIVEN inspector draws polygon WHEN completed THEN shape captured as GeoJSON, stored as FieldEvidence with type=sketch, queued for sync
  - GIVEN 3 sketches drawn offline WHEN sync completes THEN all linked to case's FieldEvidence collection
- [~] Build DrawingToolbar component with tools: point, line, polygon, eraser — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement Leaflet/MapLibre drawing integration (use existing library or implement) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store drawn shapes as GeoJSON in FieldEvidence records — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Capture center coordinates and timestamp — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Queue sketch upload operations for sync — deferred to downstream cycle / fleet-wide adoption (handoff)

## 8. Integration and Testing

### Task 17: Integrate sync endpoints with OpenConnector routing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Controller/SyncController.php`, `lib/Service/SyncQueueReplayService.php`, `openconnector/routes.php`
- **acceptance_criteria**:
  - GIVEN sync replay WHEN SyncQueue operation targets a case THEN OpenConnector routes the update to the correct OR register and schema
  - GIVEN bulk sync (100 operations) WHEN replaying THEN no timeout (use async/job-queue if needed)
  - GIVEN sync operation WHEN result = success THEN webhook notification sent to Pipelinq (trigger downstream actions)
- [x] Register sync routes in procest app: GET /api/sync/queue, POST /api/sync/queue/{id}/outcome, POST /api/sync/conflicts/{id}/resolve, GET /api/sync/daily (static routes precede {id} wildcards per ADR-016)
- [~] OpenConnector entity-type routing (DEFERRED: cross-app, needs not-yet-merged openconnector wiring) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Pipelinq "inspectie_afgerond" webhook (DEFERRED: cross-app, needs not-yet-merged pipelinq wiring) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Large-batch background-job queue (DEFERRED: follow-up; current endpoints are per-operation, no timeout risk) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 18: End-to-end functional testing of offline workflow
- **spec_ref**: All requirements
- **files**: `tests/Functional/OfflineWorkflowTest.php`, `tests/Integration/SyncQueueTest.php`
- **acceptance_criteria**:
  - TEST: Inspector syncs 5 cases offline → goes offline → answers checklists (3), adds photos (2), records memos (1) → reconnects → all 6 operations replay successfully → case data updated
  - TEST: Conflict scenario: colleague edits case while inspector offline → inspector's update receives 409 → ConflictRecord created → inspector resolves → operation retries with force-flag
  - TEST: Permission loss: inspector loses read access while offline → 403 response → operation marked failed (not retried) → error logged
- [~] Write integration test suite for offline scenarios (using in-memory OR, fake network simulation) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test SyncQueue replay with various failure modes (503, timeout, connection drop mid-replay) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test conflict resolution with multiple resolution choices (client_wins, server_wins, manual_merge) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test permission-lost and permission-regained scenarios — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test compass/geolocation fallback when GPS unavailable — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test map tile pre-download and offline rendering — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test checklist required-field validation and photo-evidence linking — deferred to downstream cycle / fleet-wide adoption (handoff)

### Task 19: Performance optimization and load testing
- **spec_ref**: All requirements
- **files**: `tests/Performance/SyncPerformanceTest.php`
- **acceptance_criteria**:
  - TEST: 100 pending operations replay in <2min (average 1.2s per operation)
  - TEST: Photo compression 4MB→1.8MB in <3s on mid-range device
  - TEST: IndexedDB queries (FieldInspection by caseRef) return in <100ms with 1000 records
  - TEST: Service Worker install and cache-building complete in <10s on 3G connection
- [~] Benchmark SyncQueueReplayService with 100+ operations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Profile photo compression (test on actual device, not just browser) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Load-test IndexedDB query performance with realistic data volume — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test cache-building performance on slow connections (throttle to 1Mbps) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Optimize bottlenecks identified (e.g., parallelized photo uploads, chunked sync batches) — deferred to downstream cycle / fleet-wide adoption (handoff)
