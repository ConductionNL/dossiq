# Coverage Report — procest

Generated: 2026-05-24 08:36 UTC
Branch: development
Scanner: opsx-coverage-scan v1

## Scan scope

- **PHP**: `lib/**/*.php` — 172 files, 1235 methods enumerated (no `lib/Migration/` or `lib/Db/` in this app).
- **Vue/JS/TS**: `src/**/*.{vue,js,ts}` — **NOT** bucketed in this pass. Many procest specs are frontend-only or frontend-primary (visual-workflow-editor, doorlooptijd-dashboard, my-work, parafering-dashboard, task-management, signalering-widgets, procest-object-store/store-migration). A v2 should enumerate `src/`.
- **Specs**: 47 directories under `openspec/specs/`, **359 REQs** extracted across 4 heading conventions (`REQ-XX-NN`, `Requirement: <title>`, `Requirement N: <title>`, `ZRC-NNN`).
- **Removed-lines cache**: `/tmp/removed-lines-procest.txt` (46749 lines, built in 2.6s — well under the 30s budget).
- **No `.opsx-ignore`** in repo; nothing suppressed.

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 58 files / ~30 methods explicitly tagged + ~278 inherited via file-level @spec | — (already tagged) |
| plumbing | 6 files + 151 `__construct` methods | — (never tagged) |
| 1 — REQ matched | (folded into 'annotated' — see notes) | — (confidence-scored per-method Bucket 1 deferred) |
| 2a — existing capability, no REQ | 76 files / 684 methods / **22 clusters** | `/opsx-reverse-spec procest --extend <cap>` |
| 2b — no capability owner | 32 files / 223 methods / **12 clusters** | `/opsx-reverse-spec procest --cluster <name>` |
| 3a — REQ broken (code removed) | 2 spec-clusters | Verify Vue impl still ships |
| 3b — REQ never implemented (or moved) | 7 spec-clusters | Mark deferred, moved, or build |
| 4 — ADR conformance | 40 files missing SPDX-FileCopyrightText; 114 files missing file-level @spec | Follow-up issue |

**Coverage headline**: 308/1235 = **~25%** of lib methods are in @spec-tagged files. The remaining 75% are real domain code with no spec linkage yet — the lift here is large but tractable because file→capability mapping is high-confidence (the codebase has strong naming conventions).

## Bucket 1 — Ready to annotate

> **Note**: this scan did not run a per-method confidence-scored Bucket 1 pass. The 58 already-annotated files cover ~308 methods (30 method-level + 278 inheriting from file-level `@spec`). The high-ROI Bucket 1 targets that are NOT yet annotated are flagged here as ready for `/opsx-annotate procest`:

### capability: zgw-api-mapping (5 controllers, ~120 methods)

`zgw-api-mapping` has 21 REQs and ZERO annotations across 5 large controllers — biggest single annotation opportunity.

| File | Methods | Notes |
|---|---|---|
| lib/Controller/ZrcController.php | 39 | ZRC endpoints — case/role/status/attachment |
| lib/Controller/DrcController.php | 32 | DRC endpoints — documents |
| lib/Controller/ZtcController.php | 21 | ZTC endpoints — case-type catalog |
| lib/Controller/BrcController.php | 18 | BRC endpoints — besluit |
| lib/Controller/NrcController.php | 10 | NRC endpoints — notificaties |
| lib/Controller/ZgwMappingController.php | 6 | Mapping CRUD |
| lib/Service/ZgwService.php | 37 | Base service |

### capability: prometheus-metrics

| File | Methods | Notes |
|---|---|---|
| lib/Controller/MetricsController.php | 13 | Implements REQ-PROM-001..010 — file docblock literally says "Prometheus text exposition format" yet has no @spec |

### capability: case-types

| File | Methods | Notes |
|---|---|---|
| lib/Controller/CaseDefinitionController.php | 4 | case-type export/import |
| lib/Service/CaseDefinitionExportService.php | 5 | |
| lib/Service/CaseDefinitionImportService.php | 5 | |

### capability: dashboard / signalering-widgets

7 Dashboard widget files (~49 methods) cleanly map to either `dashboard` (16 REQs) or `signalering-widgets` (6 REQs). One annotation per file plus the existing KpiAggregationService annotations would close the loop.

### Already annotated (no further work needed)

58 files cover:

- **status-transition-engine** (20 files, fully annotated)
- **role-based-step-routing** (11 files, fully annotated)
- **add-server-side-kpi-aggregation** (3 files, every method annotated)
- **parafering-actions / parafeerroute-engine / parafering-audit-trail** (12 files, file-level + selective method tags)
- **bezwaar-{advisory-committee,decision,hearing,lifecycle}** + **beroep-escalation** (8 files via file-level @spec)
- **enforcement-lhs / inspection-checklists** (5 files)
- **zgw-business-rules-compliance** (1/19 methods on ZgwZrcRulesService.php — others unannotated, see 2a)

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

22 clusters. Top 5 by volume:

### cluster: zgw-api-mapping (13 files / ~213 methods)

The entire ZGW API surface (ZRC/ZTC/DRC/BRC/NRC controllers + ZGW services + middleware + mapping seeder). zgw-api-mapping has 21 REQs but no methods linked to them. Highest-ROI `--extend` target — clean per-controller mapping per ZGW component.

### cluster: zgw-business-rules-compliance (5 files / 64 methods)

- lib/Service/ZgwBrcRulesService.php (10) — BRC business rules
- lib/Service/ZgwBusinessRulesService.php (8) — cross-component
- lib/Service/ZgwDrcRulesService.php (13) — DRC rules
- lib/Service/ZgwRulesBase.php (17) — shared base
- lib/Service/ZgwZtcRulesService.php (16) — ZTC rules
- (ZgwZrcRulesService is partly annotated, see Bucket 1)

The spec has 13 REQs (ZRC-007, ZRC-007b, ZRC-007q, ZRC-008c, ZRC-010, ZRC-013a, ZRC-015, ZRC-016/018, ZRC-021, ZRC-002, ZRC-005b/023h, ZRC-009, ZRC-006, plus a performance REQ). Most rule files are about ZRC, but the spec also touches DRC/BRC — extend to cover all five components.

### cluster: automatic-actions (10 files / ~45 methods)

`lib/Service/Actions/*` — generic action-handler registry + 7 concrete handlers (Webhook, CreateDocument, MergeTemplate, NotifyRole, ScheduleReminder, SendEmail). The `automatic-actions` spec has 2 REQs which seems light vs the 7 handlers; reverse-spec should probably mint ~8 REQs (one per handler + the registry contract).

### cluster: case-management (7 files / 48 methods)

CaseSharing, CaseTransfer, CaseEmail, EmailController, PublicShareController, ShareMaintenanceJob. The case-management spec has 45 REQs (REQ-CM-01..45) but none cover sharing/transfer/email integration. Either extend case-management or split into new sister capability (`case-sharing` already exists as a controller name).

### cluster: signalering-widgets (4 files / 28 methods)

Dashboard/{DeadlineAlerts,OverdueCases,StalledCases,TaskReminders}Widget.php. The spec has 6 REQs ("Deadline Alerts Widget", "Task Due Reminders Widget", etc.) that cleanly map 1:1 to these files. **Looks more like Bucket 1 candidates than 2a** — pulled here only because no @spec tag exists yet.

### Smaller 2a clusters (sketched)

| Cluster | Files | Methods | Notes |
|---|---|---|---|
| dashboard | 4 | 23 | CasesOverview/MyTasks/StartCase widgets + DashboardController |
| workflow-definition-model | 3 | 33 | WorkflowDefinitionController + Service + MigrateWorkflowDefinitions repair |
| parafering-actions | 3 | 23 | ParaferingController/Service + Notification service (sibling to annotated ParafeerActieController) |
| inspection-checklists | 3 | 18 | InspectionController + InspectionService + top-level ChecklistService (verify vs annotated Inspection/ChecklistService) |
| case-types | 3 | 14 | CaseDefinition controller + export + import (Bucket 1 candidate, see above) |
| bezwaar-lifecycle | 3 | 14 | BezwaarLifecycleListener + 2 SeedBezwaar*Repair steps |
| advice-management | 3 | 18 | AdviceController/Service + AdviceDeadlineJob |
| bezwaar-advisory-committee | 1 | 16 | AcController (Adviescommissie — note the BAC abbreviation) |
| wms-wfs-layers | 2 | 12 | WmsWfsController + Service |
| pdok-integration | 2 | 22 | PdokBag + PdokLocatieserver services |
| map-component | 2 | 10 | GisProxyController + Service |
| admin-settings | 2 | 15 | SettingsController + SettingsService (also touches register-resolver, central to ADR-022) |
| procest-app-scaffold | 2 | 13 | InitializeSettings + SeedDataService |
| case-location | 1 | 7 | LocationService |
| prometheus-metrics | 1 | 13 | MetricsController (Bucket 1 candidate) |
| vth-workflow-templates | 1 | 12 | SeedVthWorkflowTemplates |
| process-step-configuration | 1 | 6 | StepConfigValidator |

## Bucket 2b — No capability owner (reverse-spec --cluster)

12 clusters. None of these labels are namespace-words — all are behavioral domain names (no warnings to flag).

### cluster: appointment-booking (8 files / ~40 methods)

`lib/Controller/Appointment*Controller.php` + `lib/Service/AppointmentService.php` + 4 backend adapters (Interface, Jcc, Local, Qmatic) + `AppointmentReminderJob`. No spec exists for the appointment-booking feature. Likely a Specter / market gap — this is a substantial slot-booking subsystem that should have its own spec.

### cluster: berichtenbox-integration (5 files / ~17 methods)

MijnOverheid Berichtenbox adapter + sync job + controller. No spec.

### cluster: stuf-integration (3 files / 32 methods)

StUF (Standaard Uitwisseling Formaat) integration — StufController, StufFieldMappingService, StufMessageBuilder. No spec; common Dutch government data interchange standard, separate from ZGW.

### cluster: leges-fees (3 files / 24 methods)

Fee calculation + export for leges (Dutch municipal fees). No spec.

### Smaller 2b clusters

| Cluster | Files | Methods | Notes |
|---|---|---|---|
| consultation-management | 2 | 12 | A change `consultation-management` is in flight; no archived spec |
| milestone-tracking | 2 | 11 | A change `milestone-tracking` is in flight |
| template-library | 2 | 8 | TemplateController + Service |
| multi-tenancy | 2 | 13 | TenantController + Service |
| ai-assistance | 2 | 35 | AiController (13) + AiService (22) — substantial AI surface |
| mcp-integration | 1 | 21 | ProcestToolProvider — fleet-wide MCP provider pattern (per Hydra ADR-019 / OpenRegister AI orchestrator) |
| dso-omgevingsloket | 1 | 3 | DsoIntakeService — `dso-omgevingsloket` change is open but no archived spec |
| ops-observability | 1 | 6 | HealthController |

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (code-removed evidence)

- **doorlooptijd-dashboard** — 9 REQs, 16+18 hits in removed-lines for `slaComplian`/`doorlooptijd`. Frontend lives at `src/views/DoorlooptijdDashboard.vue` + `src/utils/doorlooptijdHelpers.js`; backend KPI feed via `KpiAggregationService`. Likely Bucket 1 in the frontend half — verify SLA-breakdown still renders.
- **visual-workflow-editor** — 3 REQs, 9 hits for `workflowEditor` in removed-lines. Current Vue editor at `src/views/settings/WorkflowEditor.vue` + `src/components/workflow/`. PHP layer absent (frontend-only spec); v2 scan should claim it.

### 3b — never implemented (or moved)

- **zaaktype-versioning** (2 REQs) — 0 hits anywhere. Either deferred or part of `case-types` REQ-CT-* coverage.
- **workflow-import-export** (2 REQs) — 2 hits only; `CaseDefinitionExportService` exports case defs not workflow templates. Scope mismatch.
- **parafering-dashboard** (4 REQs) — 0 hits for `secretariaat`, `Inbox`. Frontend-only dashboard, not implemented in PHP. Confirm in `src/`.
- **process-step-configuration** (2 REQs) — `StepConfigValidator` exists (6 methods); scenarios may already be covered. Re-bucket from 2a to 1 after manual review.
- **voorstel-management** (5 REQs) — `voorstel` has 389 hits in removed-lines; the domain folded into the parafering-* services (a voorstel IS the object being paraffed). Mark spec moved into `parafering-actions` or reverse-spec a `voorstel` extension.

### Specs that look implemented but are entirely frontend (v2 scan needed)

`my-work` (11 REQs → `src/views/MyWork.vue`), `task-management` (13 REQs → `src/views/tasks/`), `case-dashboard-view` (12 REQs → Vue views), `case-map-overview` (4 REQs), `procest-object-store` (10 REQs → `src/store/`), `procest-store-migration` (3 REQs → `src/store/`), `procest-case-management` (13 REQs → mix), `case-types` REQ-CT-* (Vue settings tab partly).

## Bucket 4 — ADR conformance findings

### missing-spec-in-file-docblock (114 files)

66% of `lib/` has no `@spec openspec/changes/...` tag in file or method docblocks. Top affected groups:

- All 5 ZGW component controllers (ZRC/ZTC/DRC/BRC/NRC) and 7 ZGW services
- All 7 Dashboard widget files
- All 10 lib/Service/Actions/ handlers
- All 8 appointment-booking files
- All 5 berichtenbox-integration files
- AiController + AiService + ProcestToolProvider (MCP)

(See `bucket_2a` + `bucket_2b` in the JSON for the full per-file breakdown.)

### missing-SPDX-FileCopyrightText (40 files)

Each carries `@copyright` and `@license` in the docblock but is missing the dedicated `SPDX-FileCopyrightText:` line. ADR-014 + hydra-gate-spdx require both. Pattern is regression-prone — these were likely scaffolded before the SPDX rule landed.

Affected files include: every Zgw* service in lib/Service/ (12), every appointment-booking file (7), every berichtenbox file (4), every pdok service (2), several misc services. Full list in JSON.

### forbidden-patterns: 0 hits (clean — no var_dump/die/print_r/dd/error_log/dump)

### Direct SQL: 0 hits (clean — no `$this->db->query(`/`prepare(` outside Db boilerplate)

### Missing `@license` / `@copyright`: 0 hits

## Notes for the human reviewer

1. **Vue/JS/TS not scanned.** Procest is roughly half frontend; many specs are Vue-only and look "unimplemented" only because the PHP scan missed them. Schedule a v2 to enumerate `src/`. Specs primarily landing in Bucket 3 because of this: `visual-workflow-editor`, `doorlooptijd-dashboard`, `my-work`, `task-management`, `parafering-dashboard`, `procest-object-store`, `procest-store-migration`, parts of `case-map-overview`, `case-dashboard-view`.

2. **Coverage is bimodal.** The codebase has TWO well-annotated subsystems (status-transition-engine + role-based-step-routing + parafering family + KPI aggregation, ~58 files at 100% file-level @spec) and one COMPLETELY un-annotated subsystem (ZGW API + ZGW rules + dashboards + actions + AI + 2b clusters). The annotated half was clearly built spec-first with `/opsx-apply`; the unannotated half predates that workflow. `/opsx-annotate procest` should be cheap because the file→capability mapping is unambiguous.

3. **Duplicate-handler watch.** `lib/Service/Actions/SendEmailHandler.php` and `lib/Service/Transitions/SendEmailHandler.php` are likely parallel implementations of the same action (one for the older Actions registry, one for the new transitions engine). Same pattern for `lib/Service/ChecklistService.php` (top-level, unannotated) vs `lib/Service/Inspection/ChecklistService.php` (16 methods, file-level @spec to inspection-checklists). Verify which is canonical before annotating — annotating both will pollute the bucket.

4. **AcController is the obvious bezwaar gap.** `AcController` (16 unannotated methods) is the controller for the bezwaaradviescommissie (BAC); its sibling `AdvisoryCommitteeService` is fully annotated to bezwaar-advisory-committee. Add file-level @spec to AcController.

5. **Reverse-spec priority order** (highest impact first):
   1. `--extend zgw-api-mapping` — 13 files, 21 REQs, biggest single capability
   2. `--extend zgw-business-rules-compliance` — 5 files, 13 REQs, completes the ZGW story
   3. `--extend automatic-actions` — 10 files, only 2 REQs (under-specified)
   4. `--cluster appointment-booking` — 8 files, no spec at all
   5. `--cluster berichtenbox-integration` — 5 files, no spec
   6. `--cluster stuf-integration` — 3 files, no spec
   7. `--extend case-management` — 7 files in the share/transfer/email subsystem need REQ coverage (or split into `case-sharing` sister capability)

6. **REQ-style heterogeneity.** 4 different REQ-heading conventions in use across the 47 specs. Final REQ count = 359 (not the 47-spec average of ~8). Specs added post-ADR-008 use `REQ-XX-NN`; older specs use `Requirement: <title>`; the zgw-business-rules-compliance delta uses raw `ZRC-NNN`. Consider a normalisation pass before `/opsx-annotate`.

7. **Plumbing is light** — only 6 files + 151 constructors. No empty BackgroundJob `run()` bodies, no dispatch-only listener handles (all 10 listeners have real domain logic).

## Coverage Scan Complete — procest

Buckets: annotated=58 | plumbing=6 | 1=N (folded into annotated; see notes) | 2a=76/22 clusters | 2b=32/12 clusters | 3a=2 | 3b=7 | 4=2 rules (114 missing-spec files + 40 missing-SPDX files)

Next:
1. Read this report — confirm Bucket 1 / cluster assignments before annotating
2. `/opsx-annotate procest` — minimum: tag the 5 ZGW controllers + MetricsController + 7 Dashboard widgets + AcController = ~25 file-level tags closing ~70% of the visible gap
3. `/opsx-reverse-spec procest --extend zgw-api-mapping` — highest-ROI Bucket 2a target
4. `/opsx-reverse-spec procest --cluster appointment-booking` — pilot the cluster path
5. Schedule a v2 scan that covers `src/` for the frontend-only specs in Bucket 3
