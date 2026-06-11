# Tasks: avg-verwerkingenlogging

## Deduplication Check

- [ ] **DC01**: Confirm no existing verwerkingenlogging/processing-log spec, change, or service — `grep -ri 'verwerking\|processing.activit\|avg' openspec/specs openspec/changes lib/`. The object audit trail (OR) and `parafering-audit-trail` are different concerns; document findings.
- [ ] **DC02**: Check `zgw-autorisaties-api` client configuration shape before adding the per-client activity mapping — extend, don't duplicate.

## Schema & Configuration

- [ ] **T01**: Add `processingActivity` and `processingLogEntry` schemas to `lib/Settings/procest_register.json` per design (enum constraints on legalBasis/action/channel; processedObjects as array of {objectType, idType, idValue}). Use a dedicated register/schema for log entries (own magic table — never joined into case queries). Register `processing_activity_schema`, `processing_log_schema`, `processing_log_retention` config keys in `SettingsService`.
- [ ] **T02**: Seed the fallback activity "Niet-geclassificeerde verwerking" (flagged) via the existing register seed/repair path. Add an optional `processingActivityId` property to the `caseType` schema.

## Backend Services

- [ ] **T03**: Create `lib/Service/ProcessingActivityService.php` — CRUD with legal-basis validation (six AVG art. 6 grounds, mandatory), deactivation that preserves referencing history, resolution helpers (`resolveForCaseType`, `resolveForClient`, `fallback()`). Unit tests for validation + resolution fallback chain.
- [ ] **T04**: Create `lib/Service/ProcessingLogService.php` — `log(action, actionName, performedBy, channel, processedObjects, ?caseRef)` buffering entries in-request and flushing via `lib/BackgroundJob/ProcessingLogFlushJob.php` (queued; spool + retry on backend failure; persistent-failure admin warning; never throws into the primary action). Register the job correctly via `boot()` (NOT IRegistrationContext::registerJob — see fleet gotcha). Unit tests: buffering, flush failure spooling, non-blocking guarantee.
- [ ] **T05**: Instrument person-bearing read paths — case detail controller, ZGW ZRC zaak/rol retrieval, betrokkene lookups: emit `read` entries with BSN/KVK identifiers from the payload; list endpoints log per returned person-bearing case. Mutations and dossier export emit `update`/`create`/`delete`/`export` entries alongside (not instead of) the OR audit trail.
- [ ] **T06**: Retention — `lib/BackgroundJob/ProcessingLogRetentionJob.php` hard-deletes entries past the configured period (default P3Y) and logs its own purge run. RBAC: lock the log schema to service-write + FG/admin-read; no update/delete routes exist.

## Controllers & Routes

- [ ] **T07**: Create `lib/Controller/VerwerkingenController.php` — FG inquiry endpoints (filter by betrokkene idValue, period, activity, performer, channel; paginated), inzageverzoek export (CSV/JSON incl. activity purpose + legal basis; export itself logged), processing-activity CRUD (admin), per-caseType and per-ZGW-client mapping endpoints. FG-role and admin guards per method; register routes in `appinfo/routes.php`.
- [ ] **T08**: VNG Logging Verwerkingen API — bearer-gated routes (same token posture as `zgw-autorisaties-api`) exposing verwerkingsacties list/filter and activities in the standard's resource shape; confidential-activity entries excluded without the FG scope; 401 without token.

## Frontend

- [ ] **T09**: `src/views/settings/tabs/VerwerkingsactiviteitenTab.vue` — activities register CRUD + per-caseType activity mapping (NcSelect with inputLabel). Modals in `src/modals/` per ADR-004.
- [ ] **T10**: `src/views/admin/VerwerkingenlogInquiry.vue` — FG inquiry view: filters (BSN, period, activity, user, channel), result table, export button, unclassified-processing counter (fallback-activity gap surfacing).
- [ ] **T11**: Dutch + English i18n for all new UI strings (English source keys).

## Verification Tasks

- [ ] **V01**: Opening a BSN-bearing case as a handler produces a `read` log entry with correct activity, performer, channel `ui`, and BSN in processedObjects; the case loads with no added blocking latency when the flush backend is down.
- [ ] **V02**: Case update produces BOTH an unchanged OR audit-trail entry and an `update` verwerkingslog entry.
- [ ] **V03**: ZGW bearer-client zaak retrieval logs `channel = zgw-api` with the client identifier; unmapped case type attributes to the flagged fallback and increments the FG dashboard counter.
- [ ] **V04**: No endpoint can update/delete a log entry (admin included); retention job purges only past-retention entries and logs its run.
- [ ] **V05**: Confidential-activity entries appear for FG role only — absent from admin queries and betrokkene export.
- [ ] **V06**: BSN inquiry returns exactly the matching entries; export includes purpose + legal basis per entry and is itself logged; non-FG access denied.
- [ ] **V07**: VNG API endpoint returns standard-shaped, paginated results with bearer auth; 401 without token.
