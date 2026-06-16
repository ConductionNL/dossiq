# Design: Mobiel Inspectie Offline

## Architecture

The mobiel-inspectie-offline PWA is a client-first application that mirrors a subset of case data to the device, queues mutations locally, and syncs when reconnected. It does NOT fork case-management; it operates as a read-optimized, write-queuing consumer of OpenRegister.

### Service Layout

- **OfflineSyncService** (Service Worker) — intercepts all API requests; if network is unavailable, stores mutations in SyncQueue (IndexedDB). On reconnection, triggers SyncQueueReplayService.
- **SyncQueueReplayService** — reads queued operations in order, replays them to OpenRegister API with exponential backoff (1s, 5s, 30s, 5m, 30m). Detects 409-Conflict responses, creates ConflictRecord, shows merge UI to inspector.
- **GeolocationService** — fetches device GPS (Geolocation API); validates accuracy; falls back to case address if signal is poor (>50m) or unavailable.
- **EvidenceService** — compresses photo (JPEG 80%, max 1920px), records voice memo (Opus, max 5min), stores blobs locally, queues upload operations.
- **ChecklistSyncService** — pre-downloads checklist templates + versions at shift start, stores in IndexedDB, validates required fields on submission, queues result records.
- **MapTileService** — pre-downloads PDOK BRT tiles (zoom 10-18) around case addresses, stores in IndexedDB cache, displays offline-first with inspector drawing overlay.
- **ConflictResolutionService** — compares server version with client version on conflict, renders merge-UI, persists resolution choice with audit timestamp.

### Data Model (OpenRegister Schemas, added to procest_register.json)

**FieldInspection** (extends Procest case)
- `id`, `caseRef`, `inspectorRef`, `scheduledAt`, `startedAt`, `completedAt`
- `gpsLocation` (lat, lon, accuracy, timestamp)
- `status` (planned/in_progress/synced/conflict)
- `offlineCreatedAt`, `syncedAt`, `deviceId`

**ChecklistResult**
- `id`, `inspectionRef`, `checklistTemplateRef`, `items[]` (questionId, answer, evidenceRefs[], notes, answeredAt, gpsAtAnswer)

**ChecklistTemplate** (enhanced from Procest)
- `id`, `name`, `domain` (vth/sociaal/bouw/horeca)
- `version`, `items[]` (questionId, text, type, required, conditionalOn, helpText)

**FieldEvidence**
- `id`, `inspectionRef`, `type` (photo/voice_memo/document/sketch)
- `localBlobRef`, `cloudUrl` (nullable), `gpsLocation`, `capturedAt`
- `transcription` (for voice_memo), `transcriptionStatus`, `tags[]`, `sensitivityLevel`

**SyncQueue**
- `id`, `deviceId`, `operationType` (create/update/upload/delete)
- `targetEntity`, `targetId`, `payload`, `queuedAt`
- `attemptCount`, `lastAttemptAt`, `lastError`, `status` (pending/syncing/synced/conflict/failed)

**ConflictRecord**
- `id`, `syncQueueRef`, `serverVersion`, `clientVersion`
- `conflictType` (concurrent_edit/deleted_remote/permission_lost)
- `resolution` (client_wins/server_wins/manual_merge), `resolvedBy`, `resolvedAt`

### API Surface (V1)

- **GET /api/vth/inspections/sync** — returns daily schedule with checklists + maps to pre-download.
- **POST /api/vth/sync-queue/{id}/replay** — replay single queued operation; returns 409 if conflict.
- **GET /api/vth/sync-queue** — list pending + failed operations.
- **POST /api/vth/conflicts/{id}/resolve** — submit conflict resolution choice.
- **POST /api/vth/evidence/{type}/upload** — chunked upload for large evidence blobs (fallback to direct if small).

## Dependencies

- **OpenRegister** for all schema storage, conflict detection via ETag/versioning, bulk-query for daily sync.
- **OpenConnector** for sync-queue dead-letter-queue (operations that fail 5 times get moved to DLQ for manual review).
- **Web Crypto API** for local encryption of sensitive blobs (article 25, 32 GDPR).
- **IndexedDB + Dexie.js** for structured local storage with reliable indexing.
- **Workbox** for service-worker patterns (network-first, cache-first, stale-while-revalidate).
- **PDOK** for map tile endpoints (BRT background, cadaster).
- **Pipelinq** for automatic action on inspection-completion (notify handhaving, schedule follow-up, etc.).

## Out of Scope (V2+)

- Offline form-builder (checklists are pre-configured, not user-created in field).
- Automatic speech-to-text transcription in the field (voice memos sent to backend qwen LLM on sync).
- Real-time collaborative editing (conflicts are detected post-sync, not during).
- Cross-organization sync (single-tenant initially).
- Sensor integration beyond GPS (pressure, temperature, humidity).

## Seed Data

Example inspector preparing for shift on 2026-05-22:

**FieldInspection** (scheduled case)
```json
{
  "id": "inspect-20260522-001",
  "caseRef": "ZAAK-2026-000147",
  "inspectorRef": "user:anja.bakker",
  "scheduledAt": "2026-05-22T09:00:00Z",
  "startedAt": null,
  "completedAt": null,
  "gpsLocation": null,
  "status": "planned",
  "deviceId": "tablet-anja-001"
}
```

**ChecklistTemplate** (VTH domain)
```json
{
  "id": "checklist-bouwtoezicht-fase1",
  "name": "Bouwtoezicht Fase 1 - Fundering",
  "domain": "vth",
  "version": 2,
  "items": [
    {"questionId": "q001", "text": "Funderingskuil geschoord?", "type": "yes_no", "required": true},
    {"questionId": "q002", "text": "Foto funderingskuil", "type": "photo_required", "required": true},
    {"questionId": "q003", "text": "Bodemonderzoeksrapport aanwezig?", "type": "yes_no", "required": false},
    {"questionId": "q004", "text": "Aantekeningen inspecteur", "type": "text", "required": false}
  ]
}
```

**FieldEvidence** (captured during inspection)
```json
{
  "id": "evidence-20260522-photo-001",
  "inspectionRef": "inspect-20260522-001",
  "type": "photo",
  "localBlobRef": "blob:indexeddb://photo-001",
  "cloudUrl": null,
  "gpsLocation": {"lat": 52.1601, "lon": 5.3878, "accuracy": 8, "timestamp": "2026-05-22T09:15:32Z"},
  "capturedAt": "2026-05-22T09:15:32Z",
  "transcription": null,
  "transcriptionStatus": "pending",
  "tags": ["fundering", "kuil"],
  "sensitivityLevel": "public"
}
```

**SyncQueue** (pending operations)
```json
{
  "id": "sync-queue-20260522-001",
  "deviceId": "tablet-anja-001",
  "operationType": "create",
  "targetEntity": "ChecklistResult",
  "targetId": "result-20260522-001",
  "payload": {
    "inspectionRef": "inspect-20260522-001",
    "checklistTemplateRef": "checklist-bouwtoezicht-fase1",
    "items": [
      {"questionId": "q001", "answer": "ja", "evidenceRefs": [], "answeredAt": "2026-05-22T09:16:00Z", "gpsAtAnswer": {"lat": 52.1601, "lon": 5.3878}}
    ]
  },
  "queuedAt": "2026-05-22T09:16:05Z",
  "status": "pending",
  "attemptCount": 0
}
```
