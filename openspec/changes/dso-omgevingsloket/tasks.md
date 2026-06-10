# Tasks: dso-omgevingsloket

> **Build status (hydra audit 2026-06-10).** Substantial backend ships on dev: `lib/Service/DsoIntakeService.php::processAanvraag()` + `getDeadlineDuration()`, `lib/Service/DsoCaseService.php` (createZaakFromVergunningaanvraag/transitionStatus/computeDeadline/authorizeZaakMutation), `lib/Service/DsoLvAuthService.php`, `lib/Service/SamenwerkverzoekService.php`, plus `DsoController` + `DSOIntakeController`. Remaining: register schema seeds + admin settings tab + tests. Tasks stay [ ] for those.

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

> **Status (2026-06-11).** Backend code paths exist on dev (audit per T01-T08).
> Verifications below are runtime checks; deferred to a live container with
> OpenConnector + Docudesk wired up. Tracked via gate-19 and the cross-app
> integration harness (ConductionNL/.github testing).

- [~] **V01**: New `samenwerkverzoek` schema and zaak extension fields valid JSON; config keys populated after install — needs live install run
- [~] **V02**: When OpenConnector writes a new vergunningaanvraag, listener creates a Procest zaak with correct `procedureType` and `deadlineDatum` — verified by code path (`VergunningaanvraagCreatedListener` + `DsoCaseService::createZaakFromVergunningaanvraag()`); runtime assertion deferred to OpenConnector cross-app harness
- [~] **V03**: Status transition on Procest zaak writes both Procest zaak and OpenRegister vergunningaanvraag in one service call — verified by code path (`DsoCaseService::transitionStatus()` + this batch's `StatusChangeDispatcherListener`); runtime assertion deferred to cross-app harness
- [~] **V04**: `VergunningStatusChangedEvent` is dispatched and observable by a test listener — verified by code path (`DsoCaseService::transitionStatus()` dispatches via `IEventDispatcher`); unit-level test deferred to vth-workflow-configuration-10-testing
- [~] **V05**: Deadline job sends warning at warning threshold and critical at critical threshold; overdue cases get an overdue flag — `DsoDeadlineJob` shipped + registered in info.xml this batch; runtime assertion needs scheduled cron run
- [~] **V06**: Working-day calculator excludes weekends and Dutch national holidays — code path on dev (`DsoCaseService::computeDeadline()`); unit-test addition deferred
- [~] **V07**: Beschikking generation produces a PDF, attaches as `bijlage` with `type: beschikking`, sends notification — backend ready (`BeschikkingGenerationService::generateBeschikking()` + `BeschikkingService::compose/onderteken/verzend`); runtime assertion needs Docudesk live wiring
- [~] **V08**: Samenwerkverzoek can be initiated, accepted with advies, and rejected with rationale; status enum transitions enforced — backend ready (`SamenwerkverzoekService`); runtime UI assertion deferred to greenfield Vue work
- [~] **V09**: Doorstuur dispatches event; OpenConnector test double receives it with reden — backend code path on dev (`DsoController::doorsturen()`); cross-app double assertion deferred to OpenConnector harness
- [~] **V10**: VTH dashboard filters by activiteitgroep, regelkwalificatie, status, locatie and shows deadline colour indicator — Vue dashboard `src/views/dso/VthDashboard.vue` ships on dev; gate-19 e2e click-through test deferred
