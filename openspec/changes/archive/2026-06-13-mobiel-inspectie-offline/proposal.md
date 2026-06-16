# Proposal: Mobiel Inspectie Offline

## Why

Field inspectors (VTH domain, social welfare, water management, municipal enforcement) frequently work in locations without reliable network coverage: basements, sheds, agricultural land, underserved neighborhoods, or rural areas lacking service entirely. Current practice requires paper forms and subsequent data entry, resulting in 15-20% error rates and 4-day average delays between fieldwork and case-system update. The legal and operational requirement to record evidence immediately (photos, GPS, checklists, voice memos, witness statements) is undermined by connectivity gaps.

The `mobiel-inspectie-offline` PWA extension delivers offline-first fieldwork: inspectors synchronize their daily schedule (cases, checklists, maps, historical documents) to local IndexedDB at the office, then work completely offline in the field. A Service Worker queues every action (photo, checklist response, voice memo) locally. When the device reconnects, a sync engine replays the queue against OpenRegister with automatic conflict resolution when colleagues have edited the same case.

For inspectors: the system feels like a seamless, always-available app — only a subtle sync indicator (green/yellow/red) shows connection status. For organizations: fieldwork evidence flows directly into the case without manual re-entry, enabling immediate downstream actions (enforcement notices, follow-up scheduling, partner notifications) without delay.

## What Changes

1. **PWA Infrastructure + Service Worker**: manifest, offline-cache strategy, network-first + fallback-to-cache routing, IndexedDB data layer with Dexie.js.
2. **Data Synchronization**: SyncQueue entity in OpenRegister, sync-replay engine with exponential backoff, conflict detection and resolution UI.
3. **GPS + Geolocation**: automatic tagging on all field actions, accuracy validation, fallback to case address with sensorless flag.
4. **Evidence Capture**: photo compression (client-side JPEG, EXIF metadata), voice-memo recording (Opus codec), offline blob storage with encrypted upload queue.
5. **Offline Checklists**: checklist-template pre-download, atomic local storage, visual progress tracking, required-field validation.
6. **Map Tiles + Sketches**: PDOK BRT background map pre-download (zoom 10-18), inspector annotation drawing, sketch storage as FieldEvidence.
7. **New OpenRegister Schemas**: `FieldInspection`, `ChecklistResult`, `FieldEvidence`, `SyncQueue`, `ConflictRecord`, plus enhancements to `ChecklistTemplate`.
8. **Conflict Resolution UI**: side-by-side merge view for concurrent edits, user selection (keep-mine / accept-server / manual-merge) with audit logging.

## Impact

- **Affected projects**: procest (primary), openregister (5 new schemas), openconnector (sync-queue routing), docudesk (PDF report generation on sync completion), pipelinq (workflow triggers on `inspectie_afgerond`).
- **Code surface**: new PWA app, Service Worker, sync service, conflict UI, map drawing component, evidence gallery, plus REST integration with OR and routing in openconnector.
- **Dependencies**: OpenRegister (data storage, conflict API), OpenConnector (sync-queue replay, retry policy, dead-letter handling), PDOK (map tiles), Web Crypto API (encryption), Workbox (service worker patterns).
- **Standards**: W3C PWA manifest, IndexedDB + Workbox sync strategies, Web Geolocation API, EXIF 2.32, OGC WMTS, AVG/GDPR article 5/25/32 (privacy-by-design with local encryption), NEN 7510 (audit logging), WCAG 2.1 AA (mobile touch accessibility), Common Ground (data layer / services layer), NL Design System tokens (touch targets ≥44px, high-contrast outdoor visibility).
