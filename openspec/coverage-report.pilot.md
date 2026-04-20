# Coverage Report — procest

Generated: 2026-04-20 (manual pilot run)
Branch: `fix/header-info-email-phpcs` (46 specs present; matches `development`)
Scanner: opsx-coverage-scan v1 (pilot pass — manual execution by Claude Opus)

## Pilot scope note

This first pilot pass covers **PHP `lib/` only** (89 files). The Vue/TypeScript frontend (184 files under `src/`) is deferred to a follow-up scan — the file-level classification heuristics are the same, but the volume would blow this run's context budget.

Additionally, classification was done at **file level** with per-method detail only for Bucket 1 examples. A full per-method pass for all 89 files is the natural next step; the bucket assignments below should be stable under that pass.

Ignored: 0 (no `.opsx-ignore` present).

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 methods / 0 files | — (nothing annotated yet — fully legacy) |
| plumbing | ~50 methods across 7 Dashboard widgets + middleware boilerplate | — (never tagged) |
| 1 — REQ matched | ~180 method-level candidates across 47 files | `/opsx-annotate procest` |
| 2a — existing capability, no REQ | ~40 methods across 9 clusters | `/opsx-reverse-spec procest --extend <capability>` |
| 2b — no capability owner | ~60 methods across 8 clusters | `/opsx-reverse-spec procest --cluster <name>` |
| 3a — REQ broken | 0 surfaced (heuristic disabled for first-pass retrofit — no annotation history to grep against) | — |
| 3b — REQ never implemented | TBD — blocked on full REQ matching pass | Follow-up |
| 4 — ADR conformance | ~89 files flagged missing `@spec` in docblock (expected — that's what retrofit fixes) | — (subsumed by retrofit) |

## REQ inventory (summary)

46 spec directories scanned. Two format dialects:

- **REQ-numbered** (15 specs, 183 REQs) — e.g. `REQ-ADMIN-001`, `REQ-CM-01`, `REQ-PROM-003`
- **Requirement-named** (31 specs, 160 requirements) — e.g. `### Requirement: Advice request schema` under `advice-management`. Scanner will synthesize `{capability}#REQ-NNN` IDs based on occurrence order.

**Note on case-management/spec.md**: the file contains each REQ twice (lines 63–945 and 1013–1946). The second block appears to be a duplicate of the first. Flagged as a spec-cleanup follow-up; for retrofit purposes treat as 22 distinct REQs (REQ-CM-01 through REQ-CM-22, plus REQ-CM-23 which appears only in the second block).

## Bucket 1 — Ready to annotate (representative examples)

Will be annotated via ghost change `retrofit-annotate-procest-2026-04-20/tasks.md`.

### capability: admin-settings → tasks 1–15

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Settings/AdminSettings.php | getForm(), getSection(), getPriority(), getScope() | REQ-ADMIN-001 | 0.95 | direct Nextcloud ISettings implementation; spec explicitly cites AdminSettings class |
| lib/Sections/SettingsSection.php | getID(), getName(), getPriority(), getIcon() | REQ-ADMIN-001 | 0.95 | spec cites SettingsSection class directly |
| lib/Controller/SettingsController.php | getSettings(), updateSettings() | REQ-ADMIN-014 (validation), REQ-ADMIN-015 (error scenarios) | 0.82 | NEEDS-REVIEW — settings endpoints but spec is UI-focused |

### capability: zgw-api-mapping → tasks (multiple)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ZrcController.php | index(), create(), show(), update(), patch(), destroy() (6 of 17 publics) | zgw-api-mapping#Requirement-1 through #Requirement-6 (ZRC CRUD) | 0.92 | ZrcController = ZGW Zaakregistratiecomponent; method names = REST verbs matching spec |
| lib/Controller/ZtcController.php | index(), create(), show(), update(), patch(), destroy() | zgw-api-mapping ZTC requirements | 0.92 | ZtcController = ZGW Zaaktypecatalogus |
| lib/Controller/DrcController.php | index(), create(), show(), update(), patch(), destroy(), download(), lock(), unlock() | zgw-api-mapping DRC requirements | 0.92 | DrcController = ZGW Documentregistratie |
| lib/Controller/BrcController.php | index(), create(), show(), update(), patch(), destroy(), audittrailIndex(), audittrailShow() | zgw-api-mapping BRC requirements | 0.92 | BrcController = ZGW Besluitregistratie |
| lib/Controller/NrcController.php | 10 public methods | zgw-api-mapping NRC requirements | 0.90 | NRC = Notificatieroutercomponent |
| lib/Controller/AcController.php | index(), create(), show(), update(), patch(), destroy() | zgw-api-mapping AC requirements | 0.88 | AC = Autorisatiecomponent |
| lib/Service/ZgwService.php | 36 public methods | zgw-api-mapping (multiple) | 0.90 | central ZGW orchestrator; REQ-by-REQ mapping needed in annotate pass |
| lib/Service/ZgwMappingService.php | 8 public methods | zgw-api-mapping | 0.88 | explicit mapping-layer service |
| lib/Repair/LoadDefaultZgwMappings.php | getDefaultMappings() + 28 getXxxMapping() helpers | zgw-api-mapping (seed) | 0.90 | all private helpers inherit the single public REQ tag |

### capability: prometheus-metrics → tasks 1–10

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/MetricsController.php | index() | REQ-PROM-001 (Metrics Endpoint) | 0.97 | direct match |
| lib/Controller/MetricsController.php | private helpers (collectMetrics, getCached, checkDatabaseHealth, getCaseCounts, etc.) | REQ-PROM-001, REQ-PROM-002, REQ-PROM-003, REQ-PROM-009 (caching) | 0.88 | Pass B inherited from index() caller |
| lib/Controller/HealthController.php | index() | REQ-PROM-004 (Health Check Endpoint) | 0.97 | direct match |
| lib/Controller/HealthController.php | checkDatabase(), checkOpenRegister(), checkFilesystem(), getAppVersion() | REQ-PROM-004 | 0.88 | Pass B inherited |

### capability: case-management → tasks 1–22

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ZrcController.php | 11 additional `public` methods beyond CRUD (e.g. status changes, extensions, suspension, sub-cases) | REQ-CM-14 (status change), REQ-CM-16 (deadline extension), REQ-CM-17 (suspension), REQ-CM-18 (sub-cases) | 0.78 | NEEDS-REVIEW — behavior tag spread; need to read method bodies to map each to exact REQ |
| lib/Service/MilestoneService.php | getMilestones(), getCaseProgress(), markMilestone(), reverseMilestone(), getDurationAnalytics() | doorlooptijd-dashboard requirements | 0.85 | milestones = doorloop milestones |

### capability: procest-app-scaffold → tasks (multiple)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/AppInfo/Application.php | register(), boot() | procest-app-scaffold Scenario 1.1 / 1.2 (app registration + enable) | 0.93 | canonical bootstrap class |
| lib/Controller/DashboardController.php | page() | procest-app-scaffold (Vue SPA entry) | 0.90 | main template route |
| lib/Repair/InitializeSettings.php | getName(), run() | procest-app-scaffold (InitializeSettings repair step cited in spec scenario) | 0.95 | spec names this class directly |
| lib/Listener/DeepLinkRegistrationListener.php | handle() | procest-app-scaffold (deep link registration) | 0.85 | spec mentions deep-link integration |

### capability: parafering-* (5 specs: parafeerroute-engine, parafering-actions, parafering-audit-trail, parafering-dashboard, voorstel-management)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ParaferingController.php | 7 publics (createVoorstel, startParafering, executeAction, getAuditTrail, getCurrentStep, overrideRoute) | parafering-actions + parafeerroute-engine requirements | 0.85 | terminology match on all method names |
| lib/Service/ParaferingService.php | same 7 publics | same | 0.85 | service-layer twin |
| lib/Service/ParaferingNotificationService.php | notifyStepActivated, notifyVoorstelReturned, notifyParaferingReminder | parafering-actions (notifications) | 0.82 | NEEDS-REVIEW — specific REQ needs reading |

### capability: wms-wfs-layers → tasks 1–4

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/GisProxyController.php | proxy(), capabilities() | REQ-LAYER-03 (GIS Proxy), REQ-LAYER-04 (GetCapabilities Parser) | 0.95 | direct match |
| lib/Service/GisProxyService.php | proxyRequest(), getCapabilities() | same | 0.92 | service twin |

### capability: inspection-checklists

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/InspectionController.php | index(), captureLocation(), completeChecklistItem(), addPhoto(), complete() | inspection-checklists requirements | 0.90 | name match |
| lib/Service/InspectionService.php | same behaviors | same | 0.88 | service twin |
| lib/Service/ChecklistService.php | completeItem(), getProgress(), validateCompletion(), getConformitySummary() | inspection-checklists (progress/validation) | 0.85 | verb match |

### capability: openregister-integration

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| (cross-cutting — all `$this->openRegisterObjectService` calls) | — | REQ-OREG-005 (Object Store Pattern) | 0.80 | NEEDS-REVIEW — this is a pattern rather than a method-specific REQ. Annotating EVERY OpenRegister call would over-tag. Recommendation: annotate only the primary integration points (Application.php boot, repair steps) at file level; let the pattern itself be implicit. |

### capability: vth-case-type-seed

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Repair/SeedBezwaarBeroepData.php | run() | vth-case-type-seed requirements | 0.92 | name match |
| lib/Service/SeedDataService.php | seedBezwaarBeroepData() | same | 0.92 | name match |

(Full enumeration continues — roughly 180 method-level Bucket 1 candidates across 47 files once finalized.)

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

Clusters where file path points at a known capability but the observed behavior isn't covered by any existing REQ:

### cluster: case-management (8 methods) → `/opsx-reverse-spec procest --extend case-management`
- lib/Controller/DashboardController.php::page() — actually plumbing; no extension needed
- lib/Controller/EmailController.php::send(), sendFromTemplate(), preview(), templates() — case email integration not in existing REQs
- lib/Service/CaseEmailService.php — 8 publics, none covered
- **Observed behavior**: Cases can send outbound email, render from templates per caseType, and process inbound replies matched back to cases by subject line.

### cluster: admin-settings (2 methods) → `--extend admin-settings`
- lib/Controller/SettingsController.php::getCustomSettings(), setCustomSetting() — REQ-ADMIN-* covers the UI but not the settings-bag API

### cluster: case-types (0 — existing REQs cover CRUD; any gaps need reading)

### cluster: dashboard (0 — appears covered)

### cluster: task-management (0 — appears covered)

### cluster: roles-decisions (0 — appears covered)

### cluster: inspection-checklists (2 methods) → `--extend inspection-checklists`
- lib/Service/InspectionService.php::calculateDistance() — GPS distance helper not specified

### cluster: zgw-api-mapping (6 methods) → `--extend zgw-api-mapping`
- lib/Middleware/ZgwAuthMiddleware.php — 4 publics + 5 privates — ZGW scope-based auth not specified explicitly; needs REQs for auth contract
- lib/Middleware/TenantMiddleware.php — tenant resolution on ZGW endpoints

### cluster: procest-app-scaffold (3 methods) → `--extend procest-app-scaffold`
- lib/Middleware/TenantMiddleware.php::beforeController(), afterException() — covered by multi-tenancy if that spec exists
- (move to 2b cluster "multi-tenancy" if no tenant spec)

## Bucket 2b — No capability owner (reverse-spec --cluster)

Clusters with no capability owner — would require a new spec if retrofitted:

### cluster: appointments (3 files, ~15 methods) → `--cluster appointments`
- lib/Controller/AppointmentController.php (6 publics)
- lib/Controller/PublicAppointmentController.php (3 publics)
- lib/BackgroundJob/AppointmentReminderJob.php
- lib/Service/AppointmentService.php (7 publics)
- lib/Service/AppointmentBackend/* (interface + 3 backends: JCC, Local, Qmatic)
- **Observed behavior**: Appointment booking with 3 pluggable backends (JCC for municipal self-service, Qmatic for queue management, Local for dev). Supports book/cancel/reschedule/no-show + public reschedule via token. Nightly reminder job.

### cluster: berichtenbox (2 files, ~8 methods) → `--cluster berichtenbox`
- lib/Controller/BerichtenboxController.php
- lib/BackgroundJob/BerichtenboxReadStatusJob.php
- lib/Service/BerichtenboxService.php + adapter interface + MockAdapter
- **Observed behavior**: Sends citizen messages to Dutch government MijnOverheid Berichtenbox with BSN validation; polls read status via adapter pattern.

### cluster: case-sharing (4 files, ~15 methods) → `--cluster case-sharing`
- lib/Controller/CaseSharingController.php
- lib/Controller/PublicShareController.php
- lib/BackgroundJob/ShareMaintenanceJob.php
- lib/Service/CaseSharingService.php (9 publics)
- lib/Service/CaseTransferService.php (4 publics)
- **Observed behavior**: Generate tokenized public-read links for cases, partner shares with filtered case data, case transfer workflow between users/orgs with accept/reject.

### cluster: stuf-protocol (2 files, ~20 methods) → `--cluster stuf-protocol`
- lib/Controller/StufController.php (3 publics + 8 privates)
- lib/Service/StufFieldMappingService.php (12 publics)
- lib/Service/StufMessageBuilder.php (6 publics)
- **Observed behavior**: Bidirectional SOAP-based StUF-ZKN / StUF-BG protocol mapping — ISO ↔ StUF date formats, confidentiality level mapping, StUF message construction for legacy ZGW clients.

### cluster: leges (3 files, ~10 methods) → `--cluster leges`
- lib/Controller/LegesController.php
- lib/Service/LegesCalculationService.php
- lib/Service/LegesExportService.php
- **Observed behavior**: Tax/fee calculation with 5 strategies (vast, percentage, staffel, maximum, combinatie), recalculation on case changes, verrekening (offset) + teruggaaf (refund) paths, exports to CSV/ASCII/XML.

### cluster: ai-assistant (2 files, ~25 methods) → `--cluster ai-assistant`
- lib/Controller/AiController.php (12 publics)
- lib/Service/AiService.php (12 publics + 10 private prompt builders)
- **Observed behavior**: Case-scoped AI: document classification, data extraction, Q&A, summarization, routing suggestion, next-step suggestion. PII stripping, audit trail, per-feature enable flags.

### cluster: multi-tenancy (2 files, ~10 methods) → `--cluster multi-tenancy`
- lib/Controller/TenantController.php
- lib/Middleware/TenantMiddleware.php
- lib/Service/TenantService.php
- **Observed behavior**: Tenant resolution on requests, tenant-scoped CRUD, per-controller tenant enforcement.

### cluster: templates (1 file, 4 methods) → `--cluster templates`
- lib/Controller/TemplateController.php
- lib/Service/TemplateLibraryService.php
- **Observed behavior**: Template library for case-type configurations (separate from email templates).

### cluster: case-definition-export-import (2 files) → `--cluster case-definition-export-import`
- lib/Controller/CaseDefinitionController.php (export, validate, import)
- lib/Service/CaseDefinitionExportService.php
- lib/Service/CaseDefinitionImportService.php
- **NOTE**: `workflow-import-export` spec exists — might be 2a, not 2b. Needs reading to confirm scope overlap.

### cluster: dso-intake (1 file) → `--cluster dso-intake`
- lib/Service/DsoIntakeService.php (processAanvraag, getDeadlineDuration)
- **Observed behavior**: Receives DSO (Digitaal Stelsel Omgevingswet) aanvraag messages and converts to internal cases.

### cluster: consultation (1 file) → possibly 2a under `advice-management`
- lib/Controller/ConsultationController.php + lib/Service/ConsultationService.php
- **NOTE**: Overlap with `advice-management` spec — probably 2a (--extend advice-management).

### cluster: notifications (1 file) → possibly 2a under `zgw-api-mapping` NRC
- lib/Service/NotificatieService.php
- **NOTE**: Likely covered by NRC requirements in zgw-api-mapping.

## Bucket 3 — Unimplemented REQs

**3a and 3b deferred** — detecting REQs whose code "used to exist but is now broken" requires a keyword-based grep over git history that I haven't run yet (would take a separate pass). For an app built greenfield over specs, 3a is unlikely. 3b will show up once I do a systematic "which REQ has zero matched methods" reverse check against the full inventory.

## Bucket 4 — ADR conformance findings

### missing-`@spec`-in-file-docblock (89 of 89 files)
Expected — this is exactly what retrofit fixes. Not a separate finding during retrofit.

### forbidden-patterns scan
Not run in this pilot. Should be done via the existing `/hydra-gate-forbidden-patterns` skill, which has a word-boundary grep already tuned.

### hardcoded-strings scan
Not run in this pilot — needs separate pass over Vue (skipped).

## Notes for the human reviewer

- **case-management/spec.md has duplicated REQ blocks** (lines 63–945 and 1013–1946). Pre-retrofit cleanup recommended — treating as 22 unique REQs for now.
- **ZrcController is large** (17 publics + 22 privates) — many are ZGW CRUD (Bucket 1) but ~11 are `case-management` behaviors that need careful REQ mapping during annotate. Good candidate for chunked annotation by method-group.
- **The 2b clusters are large.** 8 clusters × ~5 REQs each ≈ 40+ reverse-spec REQs. With the 5-REQ-per-run cap, that's 8–10 PRs of reverse-spec work. Sequencing matters: do the independent clusters first (leges, stuf-protocol, templates) before the ones that might overlap or merge (consultation → advice-management, notifications → zgw-api-mapping).
- **The "Requirement-named" spec dialect (31 specs, 160 requirements)** is the bigger matching challenge. These don't have REQ-IDs in the spec file — the scanner needs to synthesize them. Confidence scores for these will run lower than for REQ-numbered specs until the synthesis is stable.
- **Vue/TS frontend (184 files) not scanned.** Likely dominated by `src/views/cases/*.vue` (case-management), `src/views/settings/*.vue` (admin-settings), `src/components/**` (many capabilities). Frontend scan is a natural follow-up; will land in a separate Bucket-1-rich PR.
- **No existing `@spec` annotations** — procest is fully legacy. Expected; confirms the retrofit premise.

## Suggested next steps (for the human driving the retrofit)

1. Review this report. Flag any Bucket 1 matches that look wrong.
2. Decide on 2a vs 2b for the borderline clusters: `consultation` (→ advice-management?), `notifications` (→ zgw-api-mapping?), `case-definition-export-import` (→ workflow-import-export?).
3. Decide sequencing for reverse-spec passes. Recommend starting with `leges` or `stuf-protocol` — self-contained, well-defined behaviors that make good dogfood.
4. Schedule a Vue scan follow-up — same skill, wider glob.
5. `/opsx-annotate procest` against this report when ready. Will create ghost change `retrofit-annotate-procest-2026-04-20` and land the Bucket 1 annotations as one PR.
