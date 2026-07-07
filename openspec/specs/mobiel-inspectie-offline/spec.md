---
status: done
status-note: >-
  2026-07-07 (move-portals-to-portaliq, ADR-046, procest#162): the standalone
  in-app field-inspection nav page (/inspecties, manifest fragment
  70-mobiel-inspectie.json) is RETIRED — external field inspectors now use the
  shared Portaliq portal as the `inspector` audience of
  PortalContributionProvider (inspectieRapport / inspectionChecklistRun scoped
  by the additive assignedInspectorRef). The offline `field-inspection` OR
  integration leaf registration, the inspection schemas and the employee-side
  case-detail inspection panels remain.
---

# mobiel-inspectie-offline Specification

## Purpose
Lets field inspectors work fully offline: they synchronize the day's cases, checklists, historical documents, and map tiles to local storage, then complete checklists, capture GPS-tagged photos (client-side compressed with EXIF), record voice memos for later transcription, and draw map annotations without network connectivity. Changes are queued locally and automatically replayed in order with exponential backoff on reconnection, concurrent edits and lost permissions are surfaced as conflicts the inspector resolves, and all resolution decisions are recorded in an immutable, AVG-compliant audit trail.
## Requirements
### Requirement: Offline Daily Planning Synchronization

The system SHALL allow inspectors to synchronize their daily schedule to local storage before heading into the field, enabling access to all planned cases, checklist templates, historical documents, and map tiles without network connectivity.

#### Scenario: Download daily schedule with cases and checklists

- **GIVEN** an inspector opens the mobiel-inspectie PWA at the office with active network connection
- **WHEN** they tap "Dag synchroniseren" (Synchronize day)
- **THEN** the system SHALL download:
  - All case records (FieldInspection) scheduled for today, including case number, address, priority, and assigned checklist IDs
  - All referenced inspectieChecklist templates (including items, question text, types, required flags)
  - Historical case documents (previous inspection reports, decisions, permits) for each case
  - Map tiles (PDOK BRT background, cadaster overlays) for zoom levels 10-18 covering all case addresses to a 10km radius
- **AND** display a progress indicator showing "32 MB of 48 MB downloaded"
- **AND** mark the daily schedule as `ready_offline` with an expiry timestamp 24 hours in the future
- **AND** store all data in local IndexedDB with keys keyed by case ID and checklist template ID

#### Scenario: Sync updates incomplete sync on connection loss

@e2e exclude Resumable chunked download is a Service Worker / browser-network concern; not deterministically drivable headless. Replay-ordering logic is covered by tests/vitest/syncQueueEngine.spec.js.

- **GIVEN** a sync download is in progress (15% complete) when network connection drops
- **WHEN** the inspector taps "Dag synchroniseren" again and network is restored
- **THEN** the system SHALL resume the download from the last completed checkpoint (not restart from 0%)
- **AND** SHALL display progress as "Hervatten: 28 MB van 48 MB"

#### Scenario: Sync size warning for slow connections

@e2e exclude Connection-speed estimation depends on the Network Information API + a live throttled link; not headless-drivable. Size-manifest math is server-side (MapTileService) + unit-tested.

- **GIVEN** the inspector is on a 3G connection with bandwidth ~1 Mbps
- **WHEN** they initiate sync for a schedule that totals 48 MB
- **THEN** the system SHALL display a warning: "Download zal ca. 6 minuten duren. Aanbevolen: WiFi-verbinding"
- **AND** allow proceeding or cancelling before starting

### Requirement: Offline Checklist Completion and Storage

The system SHALL allow inspectors to answer checklist questions completely offline, storing answers atomically in local IndexedDB, and queuing sync operations without requiring network roundtrips.

#### Scenario: Answer checklist question offline and store locally

- **GIVEN** an inspector is in the field without network, viewing checklist "Bouwtoezicht Fase 1 - Fundering" for case ZAAK-2026-000147
- **WHEN** they answer question "Funderingskuil geschoord?" with "ja" and tap Next
- **THEN** the system SHALL:
  - Atomically write the ChecklistResult record to local IndexedDB with: questionId, answer, answeredAt timestamp, GPS coordinates at answer-time
  - Queue a SyncQueue operation of type "create" targeting the ChecklistResult entity
  - Display a subtle badge "1 wijziging wachten op sync" (1 change waiting for sync)
  - Remain fully responsive (no network roundtrip)

#### Scenario: Required field validation blocks save

- **GIVEN** question "q002 - Foto funderingskuil" is marked as `required: true` with `type: photo_required`
- **WHEN** the inspector attempts to mark the question answered without uploading a photo
- **THEN** the system SHALL display validation error: "Foto verplicht voor deze vraag"
- **AND** SHALL NOT create a ChecklistResult record or queue a sync operation
- **AND** the question SHALL remain unsaved locally

#### Scenario: Sync status badge counts all pending operations

- **GIVEN** an inspector has answered 12 checklist questions, added 3 photos, and recorded 2 voice memos offline
- **WHEN** viewing the case detail
- **THEN** the sync badge SHALL display "17 wijzigingen wachten op sync" (17 changes waiting)
- **AND** each pending operation SHALL be listed in a "Pending Sync" view with operation type and description

### Requirement: Automatic GPS Geolocation Tagging on All Fieldwork

The system SHALL automatically capture GPS coordinates (latitude, longitude, accuracy in meters) and timestamp with every field action (photo, checklist answer, voice memo), enabling location-based evidence trails and validation of work location.

#### Scenario: GPS coordinates captured with checklist answer

@e2e exclude Geolocation API requires a device sensor / fake-geo permission grant; not headless-deterministic. GPS classification logic is unit-tested (classifyGps).

- **GIVEN** an inspector is at case address (52.1601°N, 5.3878°E, estimated ±8 meters accuracy)
- **WHEN** they answer a checklist question offline
- **THEN** the system SHALL automatically append GPS coordinates (lat, lon, accuracy, timestamp) to the ChecklistResult record without user interaction
- **AND** the GPS metadata SHALL be stored in a `gpsAtAnswer` field

#### Scenario: GPS accuracy warning when poor signal

@e2e exclude Requires a controllable Geolocation sensor reading; not headless-deterministic. Poor-accuracy (>50m) warning copy is unit-tested (classifyGps).

- **GIVEN** GPS signal provides coordinates with accuracy worse than 50 meters (e.g., ±200 meters in a shielded basement)
- **WHEN** an inspector attempts to answer a checklist question or take a photo
- **THEN** the system SHALL display a warning: "Locatie onnauwkeurig (±200m) — wacht op beter signaal of voeg handmatig adres toe"
- **AND** allow proceeding with the action, but flag the record with `gpsAccuracy: "poor"`
- **AND** enable an optional manual address/location correction field

#### Scenario: GPS fallback to case address when signal is lost

@e2e exclude Sensor-failure fallback requires simulating Geolocation API denial; not headless-deterministic. Sensorless fallback is unit-tested (classifyGps) + server-side (EvidenceMetadataService).

- **GIVEN** GPS fails entirely (e.g., indoor in metal-framed building with no Geolocation API signal)
- **WHEN** an inspector captures evidence
- **THEN** the system SHALL silently fall back to the case's registered address from OpenRegister
- **AND** tag the record with `gpsSource: "sensorless"` flag for audit purposes
- **AND** NOT display an error to the inspector (graceful degradation)

### Requirement: Photo Capture with Client-Side Compression and EXIF Metadata

The system SHALL capture photos using the device camera, compress them client-side to reduce sync bandwidth (max 2MB), and embed EXIF metadata for location, time, and context linkage.

#### Scenario: Capture and compress photo

@e2e exclude Camera capture + canvas compression need a device camera / MediaDevices; not headless-deterministic. The 2MB target validator is unit-tested (isPhotoWithinTarget) + server-side.

- **GIVEN** an inspector taps "Foto toevoegen" on a checklist question
- **WHEN** they use the camera to capture a photo (native camera app or Web Camera API)
- **THEN** the system SHALL:
  - Compress the photo client-side to JPEG format with quality 80 and max width 1920 pixels
  - Verify the result is ≤2MB
  - Display "Comprimering voltooid: 1.8 MB"

#### Scenario: EXIF metadata embedded in captured photo

@e2e exclude EXIF embedding operates on a real captured image blob; not headless-deterministic. EXIF context builder is server-side + unit-tested (EvidenceMetadataService.buildExifContext).

- **GIVEN** a photo is captured during an inspection
- **WHEN** the photo is processed
- **THEN** the system SHALL embed EXIF metadata:
  - `Exif.GPSInfo` with current GPS coordinates (from Geolocation API)
  - `Exif.Image.DateTime` with capture timestamp
  - Custom tags: `Exif.UserComment` with inspectorId, caseRef, deviceId, checklistTemplateId
- **AND** store the blob locally in IndexedDB with reference in a FieldEvidence record

#### Scenario: Photo upload queue on reconnect

@e2e exclude Upload-on-reconnect is a Service Worker / IndexedDB replay concern; not headless-deterministic. Queue replay + status transitions are unit-tested (syncQueueEngine.nextState) and PHPUnit (SyncController).

- **GIVEN** inspectors have captured 5 photos during offline fieldwork (total 9 MB uncompressed, ~5 MB after compression)
- **WHEN** network connectivity returns
- **THEN** the system SHALL queue 5 upload operations in SyncQueue
- **AND** begin uploading compressed photos to OpenRegister's file-attachment service
- **AND** update SyncQueue operation status from `pending` to `synced` as each upload completes

### Requirement: Voice Memo Recording and Transcription Queueing

The system SHALL allow inspectors to record voice memos (audio notes) during inspections, store them locally, and queue transcription via the Procest LLM endpoint when connectivity returns.

#### Scenario: Record voice memo offline

@e2e exclude MediaRecorder (Opus) needs a real microphone stream; not headless-deterministic. The 5-min limit validator is unit-tested (isVoiceMemoWithinLimit) + server-side.

- **GIVEN** an inspector taps "Spraakmemo opnemen" on a checklist question
- **WHEN** they record a voice memo (e.g., verbal observation or note to self)
- **THEN** the system SHALL:
  - Use the MediaRecorder API to capture audio in Opus codec
  - Enforce max 5-minute recording limit
  - Display "Opname 2:34" (recording time)
  - Store the audio blob locally in IndexedDB as a FieldEvidence record with `type: "voice_memo"`

#### Scenario: Transcription queued on sync

@e2e exclude Transcription routing to a qwen LLM endpoint is cross-app (OpenConnector) + needs a live model; not headless-deterministic. Queue/process/fallback flow is covered by PHPUnit TranscriptionServiceTest.

- **GIVEN** an inspector recorded a voice memo offline: "De fundering is flink verzakt in de linkerkant. Dit ziet er ernstig uit, meer onderzoek nodig."
- **WHEN** the device reconnects and sync begins
- **THEN** the system SHALL:
  - Queue a "transcribe" operation in SyncQueue targeting the voice-memo FieldEvidence record
  - Send the audio blob to the Procest qwen-3.5 LLM endpoint (or equivalent)
  - Store the returned transcription text in the FieldEvidence record's `transcription` field
  - Set `transcriptionStatus: "synced"` when complete
- **AND** keep the original audio blob available for playback alongside the text

### Requirement: Automatic Sync Queue Replay on Network Reconnection

The system SHALL detect network reconnection and automatically replay all queued operations in order, with exponential backoff on failure, transparent progress reporting, and recovery from temporary failures.

#### Scenario: Auto-detect reconnection and start replay

@e2e exclude navigator.onLine reconnection detection + auto-replay are Service Worker concerns; not headless-deterministic. Ordering + drain logic is unit-tested (orderForReplay) and PHPUnit (SyncQueueReplayService).

- **GIVEN** the device was offline for 45 minutes, with 23 pending SyncQueue operations (checklist answers, photos, voice memos)
- **WHEN** network connectivity returns (navigator.onLine event + successful ping to OpenRegister API)
- **THEN** the system SHALL:
  - Automatically initiate SyncQueueReplayService without user action
  - Fetch all `pending` operations from local IndexedDB
  - Display a sync progress bar: "Synchroniseren: 1/23"

#### Scenario: Replay operations in order with exponential backoff

@e2e exclude Backoff timing (1s/5s/30s/5min/30min) is non-deterministic in a headless run. The schedule + transitions are exhaustively unit-tested (delayForAttempt, nextState) and PHPUnit (SyncBackoffService).

- **GIVEN** the sync queue contains 23 operations to replay
- **AND** operation #5 temporarily fails (e.g., 503 Service Unavailable)
- **WHEN** the replay engine encounters the failure
- **THEN** the system SHALL:
  - Retry operation #5 with exponential backoff: 1s, then 5s, then 30s, then 5min, then 30min
  - Continue processing operations #6-23 in parallel or after retry completes (depending on dependency)
  - Update SyncQueue record: `lastError: "503 Service Unavailable"`, `attemptCount: 2`, `lastAttemptAt: [timestamp]`
  - If operation #5 fails after 5 retries (max 30min elapsed), move it to `failed` status and log for manual review

#### Scenario: Progress bar and completion notification

@e2e exclude Live replay-progress requires queued offline operations + a reconnection event; not headless-deterministic. The drain tally (synced/conflict/failed counts) is in syncReplayService over the unit-tested engine.

- **GIVEN** sync is replaying 23 operations
- **WHEN** operations #1-14 have completed successfully
- **THEN** the system SHALL:
  - Display "Synchroniseren: 14/23" with a progress percentage (61%)
  - Update in real-time as each operation completes
  - On 100% completion, display a success notification: "Sync voltooid. 23 wijzigingen opgeslagen."
  - Automatically dismiss successful-operation SyncQueue records after 7 days

#### Scenario: Offline during sync attempt

@e2e exclude Mid-replay network-drop + resume is a Service Worker concern; not headless-deterministic. Resume-from-next-pending is guaranteed by orderForReplay filtering terminal ops (unit-tested).

- **GIVEN** sync is replaying operations and the network drops mid-replay (at operation #8 of 23)
- **WHEN** network reconnects again
- **THEN** the system SHALL:
  - Resume from operation #9 (not restart from #1)
  - Update the progress bar to reflect resumed state

### Requirement: Conflict Detection and Resolution for Concurrent Edits

The system SHALL detect when a colleague has edited the same case while the inspector was offline, surface the conflict clearly, and allow the inspector to choose a resolution (keep local, accept server, or manually merge).

#### Scenario: Detect conflict on sync replay

@e2e exclude Requires an offline-created result + a concurrent server edit to trigger a 409; not headless-deterministic. Conflict classification + record building is covered by PHPUnit (SyncControllerTest, ConflictDetectionServiceTest) and vitest (classifyConflict).

- **GIVEN** inspector Anja completed a checklist offline with answer "goedgekeurd" for "Keuring afgewerkt?"
- **AND** while she was offline, her colleague Piet (back at the office) changed the same case's inspection status to "afgekeurd"
- **WHEN** Anja's sync queue replays her ChecklistResult update
- **THEN** the system SHALL receive a 409 Conflict response from OpenRegister (ETag mismatch)
- **AND** create a ConflictRecord with:
  - `syncQueueRef`: reference to the failed operation
  - `clientVersion`: Anja's ChecklistResult record (including her answer)
  - `serverVersion`: Piet's updated record (including his status change)
  - `conflictType: "concurrent_edit"`

#### Scenario: Show conflict merge UI to inspector

@e2e exclude The merge modal only renders once a ConflictRecord exists in the local store (requires an offline-created conflict); not headless-deterministic. The side-by-side field diff is unit-tested (diffVersions) and the modal renders it via ConflictResolverModal.vue.

- **GIVEN** a ConflictRecord has been created
- **WHEN** the inspector views the case or sync detail
- **THEN** the system SHALL display a merge dialog with side-by-side comparison:
  - **Left side (My Version)**: "Keuring afgewerkt?: goedgekeurd" (Anja's local answer, timestamped 2026-05-22 09:15)
  - **Right side (Server Version)**: "Status: afgekeurd" (Piet's update, timestamped 2026-05-22 11:30)
  - Three buttons: "Mijn versie gebruiken" (keep mine), "Serverversie accepteren" (accept server), "Handmatig samenvoegen" (manual merge)

#### Scenario: Inspector resolves conflict

@e2e exclude Resolution flow needs a live ConflictRecord; not headless-deterministic. The resolve → re-queue / discard policy is covered by PHPUnit (SyncControllerTest client_wins/server_wins) and vitest (resolveConflictChoice).

- **GIVEN** the merge UI is displayed
- **WHEN** the inspector taps "Mijn versie gebruiken"
- **THEN** the system SHALL:
  - Mark the ConflictRecord with `resolution: "client_wins"`, `resolvedBy: "user:anja.bakker"`, `resolvedAt: [timestamp]`
  - Retry the sync operation with a force-update flag (instructing OR to accept the client version)
  - Log the resolution in the case's audit trail: "Anja Bakker: conflict resolution at 2026-05-22 12:00:00 — chose local version"
  - Display confirmation: "Jouw versie is opgeslagen."

#### Scenario: Permission lost during offline work

@e2e exclude Requires revoking case permission while offline to force a 403 on replay; not headless-deterministic. Terminal permission_lost handling is covered by PHPUnit (SyncControllerTest) and vitest (classifyConflict/isConflictRetryable).

- **GIVEN** an inspector worked offline on a sensitive case (e.g., social-welfare home visit)
- **AND** while she was offline, a manager revoked her read permission on that case
- **WHEN** sync replays her ChecklistResult update
- **THEN** the system SHALL receive a 403 Forbidden response
- **AND** create a ConflictRecord with `conflictType: "permission_lost"`
- **AND** display a warning to the inspector: "Geen toestemming meer voor deze zaak. Neem contact op met uw supervisor."
- **AND** move the operation to `failed` status (not retryable)

### Requirement: Offline Map Tiles and Inspector Annotations

The system SHALL pre-download relevant map tiles (PDOK BRT background, cadaster boundaries, historical overlays) to enable offline map viewing, and allow inspectors to draw annotations (polygons, points, lines) that are stored as evidence sketches.

#### Scenario: Pre-download map tiles for case addresses

@e2e exclude PDOK tile pre-download + IndexedDB tile cache is a Service Worker concern; not headless-deterministic. Tile enumeration / size estimate math is server-side + unit-tested (MapTileServiceTest).

- **GIVEN** the daily sync downloads cases in a radius around the city center (52.0692°N, 5.3039°E)
- **WHEN** the system calculates map tile coverage for zoom levels 10-18
- **THEN** the system SHALL:
  - Pre-download PDOK BRT background map tiles (all tiles covering the radius, max 100 MB)
  - Pre-download cadaster boundary overlays (BAG/BGT data) for the case addresses
  - Store tiles in IndexedDB using a standard tile-cache key scheme (z/x/y.pbf or .png)
  - Mark the tiles with the sync timestamp for cache expiry (re-sync if >30 days old)

#### Scenario: Display offline map and fall back to cached tiles

@e2e exclude Offline tile-cache fallback requires a populated IndexedDB tile store + offline network state; not headless-deterministic. The Service Worker serves tiles cache-first (public/service-worker.js).

- **GIVEN** an inspector opens a case map in the field with no network
- **WHEN** the map viewer initializes
- **THEN** the system SHALL:
  - Detect offline status (navigator.onLine = false)
  - Fetch map tiles from local IndexedDB instead of network endpoints
  - Display the PDOK BRT background with cadaster overlays
  - Show all case locations as pins on the map

#### Scenario: Inspector draws annotation on map

@e2e exclude Leaflet-draw sketching needs a rendered offline map with cached tiles + touch/pointer drawing; not headless-deterministic. Sketch is stored as a GeoJSON FieldEvidence and queued via the unit-tested engine.

- **GIVEN** the inspector is viewing a case map in the field (offline)
- **WHEN** they tap "Annotatie toevoegen" and draw a polygon around a property boundary or area of concern
- **THEN** the system SHALL:
  - Record the drawn shape (polygon, point, or line) with GeoJSON geometry
  - Capture a timestamp and GPS coordinates (center of drawn shape)
  - Store the sketch as a FieldEvidence record with `type: "sketch"` and the GeoJSON geometry
  - Queue a sync operation to upload the sketch to the case when online

#### Scenario: Sketches visible after sync

@e2e exclude Sketch upload-on-reconnect is a Service Worker / IndexedDB replay concern; not headless-deterministic. Upload queue replay is covered by the unit-tested engine + PHPUnit (SyncQueueReplayService).

- **GIVEN** an inspector drew 3 annotation sketches on the map during offline fieldwork
- **WHEN** the device reconnects and sync completes
- **THEN** the system SHALL:
  - Upload the 3 sketch records to OpenRegister
  - Link them to the FieldEvidence collection on the case
  - Display confirmation: "3 tekeningen opgeslagen"

### Requirement: Conflict Resolution Logging and AVG Compliance

The system SHALL record all conflict resolution decisions in an immutable audit trail that meets AVG article 5 (lawfulness, fairness, transparency) and 25/32 (privacy-by-design) requirements, demonstrating what data was modified and by whom, when, and why.

#### Scenario: Audit log entry for conflict resolution

@e2e exclude Immutable audit-trail persistence depends on a resolved live ConflictRecord + the OR audit log; not headless-deterministic. The conflict-resolution record (resolvedBy/resolvedAt/resolution) is built + asserted by PHPUnit (ConflictDetectionService.applyResolution, SyncControllerTest).

- **GIVEN** inspector Anja resolves a conflict by choosing her local version
- **WHEN** the resolution is processed
- **THEN** the system SHALL create an immutable audit-trail entry:
  - **Timestamp**: 2026-05-22 12:00:00
  - **Actor**: user:anja.bakker (with name "Anja Bakker")
  - **Action**: "conflict_resolution"
  - **ConflictRecordRef**: link to the ConflictRecord
  - **Details**: JSON snapshot of both versions (client and server) at time of resolution
  - **ResolutionChoice**: "client_wins"
  - **Justification**: Optional user comment (if chosen manual-merge)
- **AND** this entry SHALL be immutable (cannot be edited or deleted by inspector)
- **AND** SHALL be visible only to the inspector, their manager, and compliance staff (RBAC)

#### Scenario: Data processing agreement compliance

@e2e exclude First-launch PIA consent + local-encryption opt-in is a first-run client flow against IndexedDB state; not headless-deterministic and deferred (DataProcessingNotice + AuditService consent are follow-up — see tasks.md Task 15).

- **GIVEN** the PWA stores sensitive personal data locally (case details, inspector notes, GPS coordinates)
- **WHEN** the app initializes on first launch
- **THEN** the system SHALL:
  - Display a data-processing notice (PIA) explaining local storage, encryption, and sync behavior
  - Require explicit opt-in before sync can proceed
  - Document the user's consent timestamp in the audit trail
  - Offer encryption toggle for sensitive fields (with impact on sync performance)

---

