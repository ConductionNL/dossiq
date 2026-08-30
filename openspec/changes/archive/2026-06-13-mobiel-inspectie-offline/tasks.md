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
> **PWA client layer (2026-06-14 follow-up build).** The previously-deferred
> `src/` work is now delivered: the Dexie/IndexedDB store
> (`src/store/offlineDb.js`), the PURE, exhaustively unit-tested sync-queue
> engine (`src/utils/syncQueueEngine.js` — ordering, backoff schedule, conflict
> classification, replay state-transitions, conflict-resolution mapping, version
> diff) and field helpers (`src/utils/fieldInspectionHelpers.js` — GPS
> classification, photo/voice limits, checklist validation, sync indicator),
> the offline checklist + daily-planning Vue views
> (`src/views/inspectie/InspectieList.vue`, `InspectieDetail.vue`), the
> conflict-resolution merge modal (`src/modals/ConflictResolverModal.vue`), the
> replay glue (`src/services/syncReplayService.js`), the PWA Service Worker +
> web manifest (`public/service-worker.js`, `public/manifest.webmanifest`,
> served at app scope by `DashboardController::serviceWorker/webManifest` and
> registered in `src/main.js`), and the manifest fragment / menu entry
> (`src/manifest.d/70-mobiel-inspectie.json`). 50 new vitest tests + 10 new
> PHPUnit SyncController tests + a Newman sync-API collection + a gate-19
> Playwright spec assert real behaviour.
>
> **Genuinely deferred (live-device / cross-app, marked `[~]` below).** The
> camera/canvas compression, MediaRecorder Opus capture, GPS sensor wiring, the
> Leaflet/PDOK map-tile cache + drawing surface, the AVG DataProcessingNotice +
> AuditService consent log, the qwen-LLM transcription routing, the OpenConnector
> DLQ + Pipelinq `inspectie_afgerond` webhook, the Docudesk PDF-on-sync, and the
> live functional/performance suites (Tasks 18-19) need device APIs or
> not-yet-merged cross-app wiring + a live instance. Their offline-independent
> logic is covered by the unit suites; the device/cross-app glue is a follow-up.
>
> **Audit note (2026-06-14).** A prior audit flagged the `syncQueue` /
> `fieldInspection` schemas as "absent from every register JSON". That claim was
> STALE: all five schemas are present and complete in
> `lib/Settings/register.d/40-mobiel-inspectie-offline.json` (verified; 59 backend
> unit tests green). No backend item was found falsely-claimed. The misleading
> entries were the `[x]`-with-`DEFERRED:`-note client items (Tasks 5-9, 12-14):
> they were checked but their client halves were never built. Those are now
> either genuinely built (re-stated honestly) or downgraded to `[~]`.

## 1. PWA Infrastructure and Service Worker

### Task 1: Set up PWA manifest and install behavior
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/manifest.json`, `public/service-worker.js`, `lib/Util/PWABootstrapper.php`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN user opens app in mobile browser THEN "Add to Home Screen" prompt appears
  - GIVEN app is installed WHEN device goes offline THEN splash screen or home screen loads from cache
  - Service Worker is registered, scoped to `/apps/procest/`, with update-check every 24 hours
- [x] Create `public/manifest.webmanifest` with icon, theme/background colors, start_url, scope, display: standalone (served by `DashboardController::webManifest`)
- [x] Implement `public/service-worker.js` with install (precache shell) / activate (stale-cache cleanup) / fetch (network-first data, cache-first assets+tiles) / message (drain-queue ping) handlers; served at app scope with `Service-Worker-Allowed` header
- [x] Register the service worker in `src/main.js` (fire-and-forget; graceful online-only fallback)
- [~] PWABootstrapper warm-up on first install — the SW `install` event precaches the shell directly; a dedicated PHP bootstrapper is unnecessary for this strategy

### Task 2: Implement offline cache strategy with Workbox
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `public/service-worker.js`, `lib/Service/CacheStrategy.php`
- **acceptance_criteria**:
  - GIVEN network-first routes (API calls) WHEN offline THEN cached response returned if available
  - GIVEN static-asset cache-first WHEN offline THEN JS/CSS loaded from cache
  - GIVEN stale-while-revalidate on slow 2G connection THEN cached response shown immediately, refresh in background
- [x] Network-first routing for the sync/data API (GET `/apps/procest/api/sync/*` and `/apps/openregister/api/objects`) with cache fallback
- [x] Cache-first for app-scoped static assets (JS/CSS/images) and PDOK map tiles
- [x] Cache versioning (`procest-mio-v1`) + stale-cache cleanup on `activate`
- [x] Offline fallback to the cached app shell when a data fetch fails offline

## 2. IndexedDB Data Layer and Sync Queue

### Task 3: Define IndexedDB schema and Dexie.js models
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization`
- **files**: `src/store/db.js`, `src/store/models.js`
- **acceptance_criteria**:
  - GIVEN app initialization WHEN Dexie.js opens database THEN all 6 tables created with correct schema
  - GIVEN FieldInspection record WHEN queried by caseRef THEN returns in <100ms (indexed)
  - GIVEN SyncQueue record WHEN status updates THEN atomic write confirms
- [x] Defined the Dexie schema in `src/store/offlineDb.js` with all six tables (fieldInspection, checklistResult, fieldEvidence, checklistTemplate, syncQueue, conflictRecord) + a `meta` singleton table for planning expiry
- [x] Indexes: caseRef, deviceId, inspectionRef, status, queuedAt, targetEntity, syncQueueRef
- [x] Lazy `getDb()` memoised open; atomic `storeDailyPlanning()` transaction; `countPending()` for the badge; `dexie@^4.0.8` added to package.json

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
- [~] Return linked historical documents per case — DEFERRED: needs the OR document-link API wired to live data
- [~] Resumable/chunked download with checkpoint tracking — DEFERRED: client-side Service Worker concern; the SW network-first/cache layer is in place but resumable byte-range download is a follow-up
- [x] Register route GET /api/sync/daily (returns cases[], checklists[], download manifest with size estimate + slow-connection warning)

### Task 6: Implement map tile pre-download for offline viewing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `lib/Service/MapTileService.php`, `src/utils/mapTileCache.js`
- **acceptance_criteria**:
  - GIVEN daily sync WHEN calculating case addresses THEN PDOK BRT tiles downloaded for zoom 10-18 in 20km radius
  - GIVEN offline view WHEN map component initializes THEN tiles served from IndexedDB cache
  - GIVEN tile cache >30 days old WHEN sync reruns THEN tiles re-downloaded
- [x] Implemented `lib/Service/MapTileService.php` — `buildManifest(bbox, zoomLevels, template?)` enumerates the (z, x, y) tiles + URLs the Service Worker should pre-fetch; `estimate(bbox, zoomLevels)` returns count + size estimate without enumerating; Web-Mercator math mirrored server-side. Defaults to the PDOK BRT achtergrondkaart WMTS template; callers can override. `MAX_TILES=50000` guard against accidental whole-NL requests at z=18. 10 unit tests cover small-bbox single-tile, multi-zoom coverage, estimate ↔ manifest equivalence, custom template override, invalid bbox/zoom rejection, the MAX_TILES safety net, and URL token substitution.
- [x] Cache-first fetch for PDOK map tiles in `public/service-worker.js` (TILE_CACHE)
- [~] Store tiles in IndexedDB with a z/x/y.{format} key scheme — DEFERRED: needs the live Leaflet/PDOK tile-pre-fetch loop driving the manifest; tile enumeration math is server-side + unit-tested (MapTileServiceTest)
- [~] Stale-tile cleanup (>30 days) — DEFERRED: the SW versions caches (`procest-mio-v1`); per-tile age expiry is part of the deferred tile-cache loop

## 4. GPS and Evidence Capture

### Task 7: Implement GeolocationService with accuracy validation and fallback
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork`
- **files**: `src/services/geolocationService.js`, `src/components/LocationWarning.vue`
- **acceptance_criteria**:
  - GIVEN GPS available with ±8m accuracy WHEN capturing evidence THEN coordinates embedded with accuracy=8
  - GIVEN GPS accuracy >50m WHEN answering checklist THEN warning displayed: "Locatie onnauwkeurig"
  - GIVEN GPS unavailable WHEN action queued THEN fallback to case address with gpsSource=sensorless flag
- [x] Geolocation API wrapper with timeout/error handling — `InspectieDetail.captureGps()` (8s timeout, resolves null on denial/failure)
- [x] Accuracy classification (`classifyGps`, both server `EvidenceMetadataService` and client `src/utils/fieldInspectionHelpers.js`): good / poor (>50m, with warning copy) / sensorless — unit-tested
- [x] Fallback to source=sensorless when no sensor fix (client tags `gpsAtAnswer.source`)
- [x] Poor-accuracy warning copy surfaced via `classifyGps().warning` (consumed inline by the detail view rather than a separate LocationWarning component)

### Task 8: Implement photo capture and client-side compression
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-photo-capture-with-client-side-compression-and-exif-metadata`
- **files**: `src/services/photoService.js`, `src/components/PhotoCapture.vue`, `lib/Util/ExifEncoder.php`
- **acceptance_criteria**:
  - GIVEN photo captured (4MB native) WHEN compressed THEN result ≤2MB and quality acceptable
  - GIVEN compressed photo WHEN examined THEN EXIF metadata includes GPS, timestamp, inspectorId, caseRef, deviceId
  - GIVEN 5 photos captured offline WHEN sync THEN all 5 queued as upload operations
- [x] Photo capture wired into the checklist (`InspectieDetail.onPhoto` via `<input type=file capture=environment>`) — registers a FieldEvidence record + evidenceRef on the answer
- [~] Canvas-based compression JPEG q80/1920px — DEFERRED: needs a real captured image + OffscreenCanvas; the 2 MB target validator (`isPhotoWithinTarget`) is unit-tested client + server side
- [x] EXIF UserComment context builder (`EvidenceMetadataService.buildExifContext`): inspectorId, caseRef, deviceId, checklistTemplateRef, capturedAt
- [x] FieldEvidence payload builder (`buildEvidencePayload`) with localBlobRef + sensitivity default
- [x] Queue upload SyncQueue operation from the client — handled by the Dexie syncQueue table + `syncReplayService.replayOperation` (`upload`/`create` → OR objects)

### Task 9: Implement voice memo recording and queue for transcription
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-voice-memo-recording-and-transcription-queueing`
- **files**: `src/services/voiceMemoService.js`, `src/components/VoiceMemoRecorder.vue`, `lib/Service/TranscriptionService.php`
- **acceptance_criteria**:
  - GIVEN inspector taps "Opnemen" WHEN recording (max 5min) THEN audio stored in Opus codec in IndexedDB
  - GIVEN offline recording WHEN sync completes THEN transcription queued to qwen-3.5 LLM
  - GIVEN transcription completes WHEN sync continues THEN text stored in FieldEvidence.transcription and status=synced
- [~] VoiceMemoRecorder using MediaRecorder API — DEFERRED: needs a real microphone stream + Opus encoder; the 5-min limit validator (`isVoiceMemoWithinLimit`) is unit-tested client + server side
- [x] Max-5min validation (`isVoiceMemoWithinLimit`, both server and client) + voice_memo payload with transcriptionStatus=pending
- [~] Store the audio blob in IndexedDB — DEFERRED: depends on the deferred MediaRecorder capture; the fieldEvidence table + queue path is in place
- [x] TranscriptionService → pluggable `TranscriberInterface` (`lib/Service/TranscriberInterface.php`) so production binds an OpenConnector-routed qwen-3.5 LLM endpoint and tests bind a deterministic stub. `lib/Service/TranscriptionService.php` orchestrates `queue(evidence)` → sets `transcriptionStatus=queued` + timestamp + 5-min duration cap; `process(evidence)` runs the transcriber with retry/backoff (success → done; recoverable error < `MAX_RETRIES` → re-queue + log last error; final → fallback to manual). 10 unit tests cover queue rejection of wrong type / too-long memo, successful transcription, fall-back when no transcriber, recoverable-error requeue, manual fallback after MAX_RETRIES, and the idempotent re-process path.
- [x] Manual-transcription fallback — `TranscriptionService::manualTranscribe(evidence, text)` marks the record done with `transcriptionNote='Manual transcription.'`

## 5. Checklist Completion Offline

### Task 10: Implement offline checklist rendering and answer storage
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/components/ChecklistView.vue`, `src/services/checklistService.js`, `lib/Service/ChecklistService.php`
- **acceptance_criteria**:
  - GIVEN checklist template loaded to IndexedDB WHEN inspector opens ChecklistView THEN all items rendered with question text, type (yes_no/scale/text/photo_required), required flag
  - GIVEN required=false item WHEN inspector skips THEN OK; when required=true and empty THEN validation error blocks save
  - GIVEN answer stored offline WHEN ChecklistResult created THEN atomic write to IndexedDB includes: questionId, answer, answeredAt, gpsAtAnswer, evidenceRefs[]
- [x] Built `src/views/inspectie/InspectieDetail.vue`: loads the inspection + checklist template from IndexedDB, renders items (yes_no/text/photo_required) in a flat touch-friendly form (≥44px targets)
- [x] Answer-storage validation via the pure `validateChecklistAnswers` helper — required + photo_required items block save with inline errors
- [x] Stores the ChecklistResult atomically in a Dexie `rw` transaction (checklistResult + syncQueue together)
- [x] Queues a SyncQueue `create` operation for the ChecklistResult in the same transaction
- [x] N/M progress indicator driven by the pure `checklistProgress` helper

### Task 11: Build checklist admin UI for template management
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage`
- **files**: `src/views/settings/tabs/ChecklistsTab.vue`, `src/components/ChecklistTemplateEditor.vue`, `lib/Controller/ChecklistAdminController.php`
- **acceptance_criteria**:
  - GIVEN admin opens Checklists settings WHEN viewing list THEN all templates shown with name, domain, version, last-edit date
  - GIVEN admin clicks "Nieuw" WHEN creating template THEN editor opens; can add items with question text, type, required, helpText, conditionalOn
  - GIVEN template saved WHEN version incremented THEN old version retained for historical inspection reports
- [~] Checklist admin template management — DEFERRED: an `inspectionChecklist` schema + `InspectionChecklistEditor.vue` + `InspectionChecklistController` already exist in procest (reused as the template source per Task 4). A dedicated mobiel-inspectie ChecklistsTab is a follow-up; the offline workflow consumes the existing templates, so it is not on the critical path for this change.
- [~] ChecklistTemplateEditor (add/edit/delete items, required/conditional, type picker) — DEFERRED: covered by the existing `InspectionChecklistEditor.vue`
- [~] photo_required item sub-form — DEFERRED with the admin tab above
- [~] Admin CRUD routes — DEFERRED: existing inspectionChecklist routes are reused

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
- [x] Client replay glue (`src/services/syncReplayService.js`): orders the queue via the pure `orderForReplay`, replays each op against OR, reports the outcome to the server (re-auth), patches the local row via `nextState`; `drainQueue` returns a synced/conflict/failed tally for the UI. Auto-drains on the `online` event in `InspectieList.vue`. The exhaustive ordering/backoff/transition logic is unit-tested (`tests/vitest/syncQueueEngine.spec.js`)

### Task 13: Implement conflict detection and ConflictRecord creation
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `lib/Service/ConflictDetectionService.php`, `lib/Controller/ConflictController.php`
- **acceptance_criteria**:
  - GIVEN SyncQueue operation receives 409 Conflict from OR WHEN handling response THEN ConflictRecord created with: syncQueueRef, clientVersion, serverVersion, conflictType, initial resolution=null
  - GIVEN ConflictRecord persisted WHEN inspector views case THEN conflict badge shows ("1 conflict")
  - GIVEN 409 received AND inspectorRef lost permission WHEN handling response THEN ConflictRecord.conflictType = permission_lost (not retryable)
- [x] ConflictDetectionService.classify() maps 409 (+body)/409(no body)/404/403 to concurrent_edit/deleted_remote/permission_lost
- [x] SyncController.recordOutcome() builds a ConflictRecord (syncQueueRef, clientVersion, serverVersion, conflictType, resolution=null) on a conflict response
- [x] Persist ConflictRecord to local IndexedDB for the UI badge — the `conflictRecord` Dexie table + `classifyConflict` client mirror (matches the server's 409-body→concurrent_edit / 409-empty→deleted_remote / 404→deleted_remote / 403→permission_lost semantics; unit-tested)
- [x] Tag the queue operation outcome with the conflict (status=conflict, not auto-retried)
- [x] Permission-lost (403) detection: classified as terminal, never retried

### Task 14: Build conflict resolution merge UI
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits`
- **files**: `src/components/ConflictResolver.vue`, `src/services/conflictResolutionService.js`
- **acceptance_criteria**:
  - GIVEN ConflictRecord exists WHEN inspector opens case or views Pending Sync THEN ConflictResolver dialog shown
  - GIVEN side-by-side diff WHEN inspector reviews THEN both versions clearly labeled (Mijn versie / Serverversie) with timestamps and actor names
  - GIVEN inspector chooses "Mijn versie" WHEN submitting THEN POST /api/conflicts/{id}/resolve with resolution=client_wins; retry operation with force-update flag
- [x] Built the ConflictResolver merge modal (`src/modals/ConflictResolverModal.vue`, isolated NcDialog per ADR-004) — renders the side-by-side field diff from the pure `diffVersions` helper
- [x] Three resolution buttons (use mine / accept server / merge manually) posting to `/api/sync/conflicts/{id}/resolve` then patching the local op via `resolveConflictChoice`
- [x] Resolution submission endpoint: POST /api/sync/conflicts/{id}/resolve with choice (client_wins/server_wins/manual_merge), IDOR-scoped + validated
- [x] On client_wins/manual_merge: re-queue the operation for a forced retry (status→pending)
- [x] On server_wins: discard the local change, mark the operation synced
- [~] manual_merge three-way field editor — DEFERRED: the modal offers the manual_merge choice and re-queues with the client payload via `resolveConflictChoice('manual_merge', merged)`; a per-field three-way merge editor surface is a follow-up. Resolution choice is persisted server-side.

### Task 15: Implement conflict resolution audit logging (AVG compliance)
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-resolution-logging-and-avg-compliance`
- **files**: `lib/Service/AuditService.php`, `src/components/DataProcessingNotice.vue`
- **acceptance_criteria**:
  - GIVEN conflict resolution submitted WHEN recorded THEN immutable audit entry created with: timestamp, actor, action=conflict_resolution, details (JSON snapshot of both versions), resolution choice, justification
  - GIVEN app first launch WHEN opening THEN data-processing notice displayed; require consent before sync
  - GIVEN consent recorded WHEN stored THEN audit entry captures user timestamp and acceptance
- [~] Conflict-resolution audit logging — PARTIAL: `ConflictDetectionService::applyResolution` builds the resolution record (resolution + resolvedBy + resolvedAt) and `SyncController::resolveConflict` persists it; a dedicated immutable AuditService entry with both-version JSON snapshots is DEFERRED to a follow-up (no `lib/Service/AuditService.php` in this app yet)
- [~] Audit-entry immutability — DEFERRED with the AuditService extension above
- [~] DataProcessingNotice (PIA + encryption opt-in) component — DEFERRED: first-run consent flow + Web-Crypto blob encryption is a privacy-hardening follow-up
- [~] Consent timestamp on first init — DEFERRED with DataProcessingNotice

## 7. Map Drawing and Annotations

### Task 16: Implement map drawing tools for sketch annotations
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-map-tiles-and-inspector-annotations`
- **files**: `src/components/MapView.vue`, `src/services/mapDrawingService.js`, `src/components/DrawingToolbar.vue`
- **acceptance_criteria**:
  - GIVEN offline map displayed WHEN inspector taps "Annotatie toevoegen" THEN drawing toolbar appears (polygon, point, line tools)
  - GIVEN inspector draws polygon WHEN completed THEN shape captured as GeoJSON, stored as FieldEvidence with type=sketch, queued for sync
  - GIVEN 3 sketches drawn offline WHEN sync completes THEN all linked to case's FieldEvidence collection
- [~] Map drawing toolbar (point/line/polygon/eraser) — DEFERRED: depends on the offline tile-cache surface (Task 6); `leaflet`/`leaflet-draw` are already app deps. Sketch storage shape (FieldEvidence type=sketch + GeoJSON) is defined in the schema fragment
- [~] Leaflet/leaflet-draw drawing integration — DEFERRED with the toolbar
- [~] Store drawn shapes as GeoJSON FieldEvidence — schema in place; capture UI DEFERRED
- [~] Capture center coordinates + timestamp — DEFERRED with the drawing surface
- [~] Queue sketch upload — the syncQueue path supports `upload` ops; the sketch-capture trigger is DEFERRED with the drawing surface

## 8. Integration and Testing

### Task 17: Integrate sync endpoints with OpenConnector routing
- **spec_ref**: `openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-sync-queue-replay-on-network-reconnection`
- **files**: `lib/Controller/SyncController.php`, `lib/Service/SyncQueueReplayService.php`, `openconnector/routes.php`
- **acceptance_criteria**:
  - GIVEN sync replay WHEN SyncQueue operation targets a case THEN OpenConnector routes the update to the correct OR register and schema
  - GIVEN bulk sync (100 operations) WHEN replaying THEN no timeout (use async/job-queue if needed)
  - GIVEN sync operation WHEN result = success THEN webhook notification sent to Pipelinq (trigger downstream actions)
- [x] Register sync routes in procest app: GET /api/sync/queue, POST /api/sync/queue/{id}/outcome, POST /api/sync/conflicts/{id}/resolve, GET /api/sync/daily (static routes precede {id} wildcards per ADR-016)
- [~] OpenConnector entity-type routing — DEFERRED: cross-app, needs not-yet-merged openconnector wiring
- [~] Pipelinq "inspectie_afgerond" webhook — DEFERRED: cross-app, needs not-yet-merged pipelinq wiring
- [~] Large-batch background-job queue — DEFERRED: follow-up; current endpoints are per-operation, no timeout risk

### Task 18: End-to-end functional testing of offline workflow
- **spec_ref**: All requirements
- **files**: `tests/Functional/OfflineWorkflowTest.php`, `tests/Integration/SyncQueueTest.php`
- **acceptance_criteria**:
  - TEST: Inspector syncs 5 cases offline → goes offline → answers checklists (3), adds photos (2), records memos (1) → reconnects → all 6 operations replay successfully → case data updated
  - TEST: Conflict scenario: colleague edits case while inspector offline → inspector's update receives 409 → ConflictRecord created → inspector resolves → operation retries with force-flag
  - TEST: Permission loss: inspector loses read access while offline → 403 response → operation marked failed (not retried) → error logged
- [x] SyncQueue replay failure modes — exhaustively unit-tested in `tests/vitest/syncQueueEngine.spec.js` (`nextState`: 2xx success, 409 conflict, 404 deleted, 403 permission_lost, 503/network retry-with-backoff, exhaustion→failed) and PHPUnit `SyncControllerTest` (success/conflict/permission_lost outcomes)
- [x] Conflict resolution choices (client_wins / server_wins / manual_merge) — unit-tested (`resolveConflictChoice`) + PHPUnit `SyncControllerTest` (client_wins re-queue, server_wins discard, invalid→400)
- [x] GPS-fallback-when-unavailable — unit-tested (`classifyGps` sensorless path)
- [x] Checklist required-field validation + photo-evidence linking — unit-tested (`validateChecklistAnswers`, photo_required path)
- [x] Sync API surface (queue/outcome/conflicts/daily) — Newman collection `tests/newman/mobiel-inspectie-sync-api.postman_collection.json` (400 on missing deviceId, 200 + results/total, 404 IDOR fail-closed) wired into `run-all.sh`
- [x] Renderable UI surface — gate-19 Playwright spec `tests/e2e/spec-coverage/mobiel-inspectie-offline.spec.ts` (list/indicator/detail/SW+manifest) with defensive deploy-drift skips; offline scenarios `@e2e exclude`d with reasons
- [~] Live in-memory-OR / fake-network end-to-end functional suite (`tests/Functional/OfflineWorkflowTest.php`) — DEFERRED: needs a live OR instance + a Service Worker runtime; the offline-independent logic is covered by the suites above
- [~] Permission-regained scenario — DEFERRED: needs a live RBAC round-trip

### Task 19: Performance optimization and load testing
- **spec_ref**: All requirements
- **files**: `tests/Performance/SyncPerformanceTest.php`
- **acceptance_criteria**:
  - TEST: 100 pending operations replay in <2min (average 1.2s per operation)
  - TEST: Photo compression 4MB→1.8MB in <3s on mid-range device
  - TEST: IndexedDB queries (FieldInspection by caseRef) return in <100ms with 1000 records
  - TEST: Service Worker install and cache-building complete in <10s on 3G connection
- [~] Performance / load testing (100+ op replay, photo-compression profiling, IndexedDB query load, 1Mbps cache-build, bottleneck optimisation) — DEFERRED: requires an actual device + a live instance under load; out of scope for this unit-/contract-tested build (follow-up `tests/Performance/SyncPerformanceTest.php`)
