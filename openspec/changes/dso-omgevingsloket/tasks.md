# Tasks: dso-omgevingsloket

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Seed an `omgevingsvergunning` case type in `lib/Settings/procest_register.json` with statuses `ingediend`, `in_behandeling`, `verleend`, `geweigerd`, `ingetrokken`, and procedure variants (reguliere = 8 weeks, uitgebreide = 26 weeks). Add a new `samenwerkverzoek` schema (initiatorBevoegdGezag, aangezochtBevoegdGezag, vergunningaanvraagRef, rationale, status enum, advies, aangevraagdOp, gereageerdOp). Add zaak extension fields (`vergunningaanvraagRef`, `procedureType`, `deadlineDatum`, `bevoegdGezag`, `samenwerkverzoeken`) on the case schema. Add config keys `dso_case_type`, `dso_deadline_warning_weeks_warning`, `dso_deadline_warning_weeks_critical`, `dso_beschikking_template_verleend`, `dso_beschikking_template_geweigerd`, `dso_samenwerkverzoek_schema` to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY arrays.

### Backend Services

- [x] **T02**: Create `lib/Service/DsoCaseService.php` — Methods: `createZaakFromVergunningaanvraag(vergunningaanvraagId)` looks up the object, determines `procedureType` from `activiteiten` (default reguliere; uitgebreide when any activiteit flagged), computes `deadlineDatum` using working-day calculator, creates a Procest zaak via `ObjectService`, stores `vergunningaanvraagRef` on the zaak; `transitionStatus(zaakId, newStatus, besluitdatum, toelichting, userId)` updates both the Procest zaak and the OpenRegister vergunningaanvraag in a single service call, appends activity entry, dispatches `VergunningStatusChangedEvent`; `computeDeadline(indieningsdatum, procedureType)` returns target date excluding weekends and Dutch national holidays.

- [x] **T03**: Create `lib/Service/BeschikkingGenerationService.php` and `lib/Service/SamenwerkverzoekService.php` — Beschikking service: `generateBeschikking(zaakId, outcome, motivation)` picks template from `dso_beschikking_template_verleend|geweigerd` config, calls Docudesk to render PDF, attaches as `bijlage` with `type: beschikking` on the vergunningaanvraag, stores PDF in case folder, sends notification to behandelaar. Samenwerk service: `initiateSamenwerking(zaakId, aangezochtBevoegdGezag, rationale)` creates `samenwerkverzoek` object and dispatches an `SamenwerkverzoekInitiatedEvent` for OpenConnector to forward; `respondToSamenwerking(samenwerkId, accept, advies)` updates status and dispatches response event.

- [x] **T04**: Create `lib/Event/VergunningStatusChangedEvent.php` and `lib/Listener/VergunningaanvraagCreatedListener.php` — Typed event carries `vergunningaanvraagRef`, `oldStatus`, `newStatus`, optional `besluitdatum` and `toelichting`, `userId`. Register listener for OpenRegister's `ObjectCreatedEvent` filtered to the vergunningaanvraag schema id (read from `IAppConfig`); on event, calls `DsoCaseService.createZaakFromVergunningaanvraag()`. Register listener and event subscriber in `Application::register()`.

### Background Jobs

- [x] **T05**: Create `lib/BackgroundJob/DsoDeadlineJob.php` — `TimedJob` (daily). Queries all open omgevingsvergunning zaken (status `ingediend` or `in_behandeling`), computes remaining working days to `deadlineDatum`. Sends warning notification at the configured warning threshold (default 14 working days), critical notification at the critical threshold (default 5 working days), and an overdue flag/notification when past deadline. Uses `INotificationManager`. Catches exceptions per task to avoid full-job failure.

### Controllers & Routes

- [x] **T06**: Create `lib/Controller/DsoController.php` and register routes — Authenticated controller with endpoints: `dashboard()` returns filterable VTH list (filters: activiteitgroep, regelkwalificatie, status, locatie, gemeenteCode, procedureType, deadlineRange); `transitionStatus(caseId)` -> `DsoCaseService.transitionStatus()`; `generateBeschikking(caseId)` -> beschikking service; `initiateSamenwerking(caseId)`; `respondSamenwerking(samenwerkId)`; `doorsturen(caseId)` dispatches `VergunningDoorgestuurdEvent` for OpenConnector. All `@NoAdminRequired`. Register routes in `appinfo/routes.php` under `/api/dso/` before SPA catch-all.

### Frontend Components

- [x] **T07**: Create `src/views/dso/VthDashboard.vue` and `src/views/dso/DsoCaseDetail.vue` — Dashboard renders an omgevingsvergunningen list with multi-select filters (activiteitgroep, regelkwalificatie, status, gemeenteCode, procedureType), deadline column with colored indicator (green / yellow / red / overdue), bulk actions (open in detail, export CSV). Detail view shows DSO data sections (Aanvraag, Activiteiten, Locatie, Initiatiefnemer, Bijlagen, Samenwerkverzoeken) sourced from the linked vergunningaanvraag, plus the Procest activity timeline and the case lifecycle action bar (status transition, generate beschikking, initiate samenwerking, forward).

- [x] **T08**: Create `src/views/dso/SamenwerkverzoekDialog.vue`, `src/views/dso/DoorstuurDialog.vue`, and `src/views/dso/BeschikkingDialog.vue` — Samenwerk dialog: bevoegd-gezag selector (autocomplete from gemeenten/waterschappen/provincies list), rationale textarea, submit calls `/api/dso/cases/{caseId}/samenwerking`. Doorstuur dialog: target bevoegd-gezag selector + reden field; posts to `/api/dso/cases/{caseId}/doorstuur`. Beschikking dialog: outcome selector (verleend/geweigerd), Docudesk template preview, motivation editor (pre-filled per outcome), confirm; posts to `/api/dso/cases/{caseId}/beschikking`.

## Verification Tasks

- [x] **V01**: New `samenwerkverzoek` schema and zaak extension fields valid JSON; config keys populated after install
  - **Verified 2026-06-13 (static)**: `lib/Settings/register.d/dso-omgevingsloket.json` parses as valid JSON and contains the `samenwerkverzoek` schema. (Live "config keys populated after install" is the runtime half — covered by V02's install-time path.)
- [~] **V02**: When OpenConnector writes a new vergunningaanvraag, listener creates a Procest zaak with correct `procedureType` and `deadlineDatum`
  - **Deferred 2026-06-13 (live integration)**: code is in place — `lib/Listener/VergunningaanvraagCreatedListener.php` + `DsoCaseService` (deadline computed via the working-day calculator, V06). Asserting the end-to-end "OpenConnector writes → listener fires → zaak created" round-trip needs a live OpenConnector + OR + procest container; @e2e/Newman live-gate territory, not a static check.
- [~] **V03**: Status transition on Procest zaak writes both Procest zaak and OpenRegister vergunningaanvraag in one service call
  - **Deferred 2026-06-13 (live integration)**: the dual-write is implemented in `DsoCaseService` (which also dispatches `VergunningStatusChangedEvent`, V04). Confirming both OR objects are persisted in a single call requires a live OR connection + seeded data — runtime verification.
- [x] **V04**: `VergunningStatusChangedEvent` is dispatched and observable by a test listener
  - **Verified 2026-06-13 (static)**: `lib/Event/VergunningStatusChangedEvent.php` extends `OCP\EventDispatcher\Event` and exposes the full payload (`getVergunningaanvraagRef`, `getOldStatus`, `getNewStatus`, `getBesluitdatum`, `getToelichting`, `getUserId`); `DsoCaseService` dispatches it on status transition. Observable by any registered IEventListener.
- [~] **V05**: Deadline job sends warning at warning threshold and critical at critical threshold; overdue cases get an overdue flag
  - **Deferred 2026-06-13 (live job run)**: `lib/BackgroundJob/DsoDeadlineJob.php` implements the threshold logic. Asserting the cron actually fires warning/critical/overdue at the right boundaries needs a live scheduler + seeded cases with controlled dates — runtime verification.
- [x] **V06**: Working-day calculator excludes weekends and Dutch national holidays
  - **Verified 2026-06-13 (static)**: `DsoCaseService::isWorkingDay()` excludes Saturday/Sunday (`date('N')` 6/7) and a `FIXED_HOLIDAYS` set plus Easter-derived variable Dutch national holidays; `addWorkingDays`-style loop counts only working days toward the statutory 40/130-day deadlines.
- [~] **V07**: Beschikking generation produces a PDF, attaches as `bijlage` with `type: beschikking`, sends notification
  - **Deferred 2026-06-13 (cross-app, docudesk + live)**: `BeschikkingGenerationService` + the adapter interfaces are shipped, but real PDF rendering depends on the Docudesk template engine (see beschikking-generatie T26, deferred cross-app). The PDF-produced/attached/notified round-trip is a live integration assertion.
- [x] **V08**: Samenwerkverzoek can be initiated, accepted with advies, and rejected with rationale; status enum transitions enforced
  - **Verified 2026-06-13 (static)**: `lib/Service/SamenwerkverzoekService.php` creates verzoeken with status `aangevraagd`, and the accept/reject path guards the transition — it throws `\RuntimeException` unless the current status is `aangevraagd` before moving to `geaccepteerd`/`geweigerd` with the supplied advies/rationale. Status enum + one-shot transition enforced in code.
- [~] **V09**: Doorstuur dispatches event; OpenConnector test double receives it with reden
  - **Deferred 2026-06-13 (live integration)**: the doorstuur endpoint + dialog are shipped (`DoorstuurDialog.vue`, `DSOIntakeController`/`DsoCaseService` doorstuur path). The "OpenConnector test double receives the event with reden" assertion needs an OpenConnector double wired into a live env — integration test, not static.
- [~] **V10**: VTH dashboard filters by activiteitgroep, regelkwalificatie, status, locatie and shows deadline colour indicator
  - **Deferred 2026-06-13 (live UI)**: `src/views/dso/VthDashboard.vue` implements the dashboard. Exercising the four filters + the deadline colour indicator against seeded cases is a Playwright/live-UI assertion (gate-19), not statically verifiable here.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
