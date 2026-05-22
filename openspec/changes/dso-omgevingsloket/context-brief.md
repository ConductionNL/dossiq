# Proposal: dso-omgevingsloket

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Portalen › DSO/Omgevingsloket

**Rationale:** DSO-inbox.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Summary

Integrate Procest with the DSO (Digitaal Stelsel Omgevingswet) Omgevingsloket so that municipalities can receive vergunningaanvragen from the national one-stop portal, manage the VTH (Vergunningen, Toezicht, Handhaving) case lifecycle, and push status updates and decisions back to DSO-LV. The change adds Procest-side case management on top of the DSO data layer (`vergunningaanvraag`, `activiteit`, `locatie`, `omgevingsdocument`) already specified in OpenRegister: zaak conversion of inbound verzoeken, deadline tracking against the 8-week reguliere procedure, beschikking generation, samenwerkverzoek and doorstuur handling, and status pushback through OpenConnector's DSO adapter.

## Motivation

The Omgevingswet (effective January 2024) replaced 26 laws and obliges every Dutch bevoegd gezag to handle environmental permit applications through DSO-LV. 32% of analyzed tenders explicitly require DSO/VTH capabilities; municipalities (Zoetermeer 282155, Westerkwartier 264852) specify detailed VTH010-VTH019 requirements for triggerbericht reception, verzoek ophalen, samenwerkfunctionaliteit, doorstuur, beschikking, and status pushback. Procest needs to convert DSO verzoeken into managed zaken, enforce reguliere/uitgebreide procedure deadlines, and synchronize the resulting decisions back to DSO-LV.

## Affected Projects

- [ ] Project: `procest` — VTH case-type wiring, deadline service, beschikking generation, status pushback wiring, Vue components
- [ ] Project: `openconnector` (out-of-repo) — DSO-LV adapter handles triggerbericht / verzoek ophalen / samenwerken / doorsturen (existing spec)

## Scope

### In Scope

- **Verzoek-to-zaak conversion** — Convert inbound `vergunningaanvraag` objects into Procest zaken with the `omgevingsvergunning` case type
- **VTH case type** — Define `omgevingsvergunning` zaaktype with statuses (`ingediend` → `in_behandeling` → `verleend`/`geweigerd`/`ingetrokken`) and reguliere (8 wk) / uitgebreide (26 wk) procedure variants
- **Deadline tracking** — Background job warning at 6/2 weeks remaining; auto-flag overdue cases
- **Beschikking generation** — Trigger Docudesk template generation on `verleend` / `geweigerd`, attach as bijlage on the vergunningaanvraag
- **Status pushback** — Dispatch typed event on status change so OpenConnector pushes update to DSO-LV
- **Samenwerkverzoek & doorstuur** — Procest UI to initiate, accept, reject samenwerking and to forward to another bevoegd gezag
- **VTH dashboard** — Procest view of all omgevingsvergunningen with filters on activiteitgroep / regelkwalificatie / status / locatie
- **Notifications** — New verzoek arrival, approaching-deadline warning, samenwerkverzoek received

### Out of Scope

- DSO-LV protocol implementation (mTLS, PKIoverheid, koppelvlak handlers) — owned by OpenConnector
- STTR vergunningcheck rule execution — out of scope (referenced for context)
- 3D / BIM viewer for bouwtekeningen
- Bezwaar-beroep workflow (covered by `bezwaar-beroep-workflow` spec)

## Approach

1. Add `omgevingsvergunning` case type to seed data with reguliere/uitgebreide variants and the status enum from the spec
2. Create `DsoCaseService` that converts inbound `vergunningaanvraag` to a Procest zaak, mirrors status, and computes deadlines
3. Add `DsoDeadlineJob` `TimedJob` (daily) for deadline warnings and overdue flagging
4. Create `BeschikkingGenerationService` orchestrating Docudesk template + attachment as `bijlage`
5. Dispatch typed events (`VergunningStatusChangedEvent`) for OpenConnector to listen to
6. Vue: `DsoCaseDetail.vue`, `SamenwerkverzoekDialog.vue`, `DoorstuurDialog.vue`, `VthDashboard.vue`
7. Extend `SettingsService` with `dso_*` config keys (case type, deadline thresholds, beschikking templates)

## Risks

- Status drift between OpenRegister `vergunningaanvraag` and Procest zaak must be reconciled by a single owner (Procest writes both via service)
- Deadline calculation must use working days per Omgevingswet rules (excluding national holidays)
- Samenwerkverzoek coordination involves multiple bevoegd gezag — Procest must not race OpenConnector on status writes
- Beschikking templates differ per municipality; Docudesk template selection by case type and decision outcome



## Design

# Design: dso-omgevingsloket

## Architecture Overview

DSO integration is split cleanly across two apps. OpenConnector owns the DSO-LV protocol layer (mTLS / PKIoverheid, triggerbericht reception, verzoek ophalen, samenwerken, doorsturen) and writes inbound vergunningaanvragen into OpenRegister via the standard REST API. Procest sits on top of that data layer: it listens for new `vergunningaanvraag` objects, converts them into managed zaken using the `omgevingsvergunning` case type, drives the VTH lifecycle (status transitions, deadlines, beschikking), and emits status events back to OpenConnector for DSO-LV pushback. This change is the Procest side of the integration — the OpenConnector adapter is described in `openconnector/openspec/specs/dso-omgevingsloket/spec.md`.

```
DSO-LV ─── OpenConnector DSO Adapter ─── OpenRegister vergunningaanvraag
                                                  │
                                                  ▼ ObjectCreatedEvent
                                          Procest DsoCaseService
                                                  │
                                                  ▼ create zaak
                                          Procest zaak (case type = omgevingsvergunning)
                                                  │
                            ┌─────────────────────┼─────────────────────┐
                            ▼                     ▼                     ▼
                  VthDashboard.vue      DsoDeadlineJob       BeschikkingGenerationService
                  DsoCaseDetail.vue     (warn 6w/2w)         (Docudesk template → bijlage)
                            │                     │                     │
                            └─────────────────────┴─────────────────────┘
                                                  │
                                                  ▼ VergunningStatusChangedEvent
                                          OpenConnector → DSO-LV (status pushback)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/DsoCaseService.php` | Convert vergunningaanvraag → zaak, mirror status, calculate deadlines, coordinate forwarding |
| `lib/Service/BeschikkingGenerationService.php` | Generate beschikking PDF via Docudesk and attach as bijlage |
| `lib/Service/SamenwerkverzoekService.php` | Initiate / accept / reject samenwerking; coordinate with OpenConnector |
| `lib/BackgroundJob/DsoDeadlineJob.php` | Daily TimedJob: warn at 6/2 weeks, flag overdue |
| `lib/Event/VergunningStatusChangedEvent.php` | Typed event consumed by OpenConnector for DSO-LV pushback |
| `lib/Listener/VergunningaanvraagCreatedListener.php` | Listens for OpenRegister `ObjectCreatedEvent` on vergunningaanvraag schema |
| `lib/Controller/DsoController.php` | REST endpoints for VTH dashboard, samenwerking, doorstuur, beschikking trigger |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/dso/VthDashboard.vue` | Filterable list of all omgevingsvergunningen (activiteitgroep, regelkwalificatie, status, locatie) |
| `src/views/dso/DsoCaseDetail.vue` | VTH case detail with DSO data (activiteiten, locatie, initiatiefnemer, bijlagen) and lifecycle actions |
| `src/views/dso/SamenwerkverzoekDialog.vue` | Initiate samenwerkverzoek with bevoegd gezag selector and rationale |
| `src/views/dso/DoorstuurDialog.vue` | Forward verzoek to another bevoegd gezag with reason |
| `src/views/dso/BeschikkingDialog.vue` | Choose template (verleend/geweigerd) and motivation before generation |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Seed `omgevingsvergunning` case type (statuses, reguliere/uitgebreide variants, default templates) |
| `lib/Service/SettingsService.php` | Add `dso_case_type`, `dso_deadline_warning_weeks`, `dso_beschikking_template_verleend`, `dso_beschikking_template_geweigerd` config keys |
| `appinfo/routes.php` | Add `/api/dso/` routes for dashboard, samenwerking, doorstuur, beschikking |
| `appinfo/info.xml` | Register listener and background job |

## Data Model

The structured DSO objects (`vergunningaanvraag`, `activiteit`, `locatie`, `omgevingsdocument`) live in OpenRegister and are defined in the DSO register spec. Procest adds a thin link layer:

### Procest zaak extension fields (on existing case schema)
- `vergunningaanvraagRef` (string, reference) — Reference to the `vergunningaanvraag` object
- `procedureType` (enum) — `reguliere` (8 wk) / `uitgebreide` (26 wk)
- `deadlineDatum` (date) — Computed from `indieningsdatum` + procedure length in working days
- `bevoegdGezag` (string) — OIN or organization name
- `samenwerkverzoeken` (array of references) — Samenwerkverzoek objects linked to this zaak

### samenwerkverzoek Schema (new in procest register)
- `initiatorBevoegdGezag` (string, required) — Initiating organization
- `aangezochtBevoegdGezag` (string, required) — Receiving organization
- `vergunningaanvraagRef` (string/reference, required) — Linked vergunningaanvraag
- `rationale` (string) — Reason for samenwerking
- `status` (enum) — `aangevraagd` / `geaccepteerd` / `geweigerd` / `afgerond`
- `advies` (string, optional) — Advice given by aangezochte bevoegd gezag
- `aangevraagdOp` / `gereageerdOp` (datetime)

## API Design

### Authenticated Endpoints (DsoController)
- `GET /api/dso/dashboard` — VTH dashboard data with filters (activiteitgroep, regelkwalificatie, status, locatie, gemeenteCode)
- `POST /api/dso/cases/{caseId}/transition` — Status transition with optional `besluitdatum` and `toelichting`
- `POST /api/dso/cases/{caseId}/beschikking` — Trigger beschikking generation (template + motivation)
- `POST /api/dso/cases/{caseId}/samenwerking` — Initiate samenwerkverzoek
- `PUT /api/dso/samenwerking/{id}` — Accept/reject samenwerkverzoek (with advice)
- `POST /api/dso/cases/{caseId}/doorstuur` — Forward to another bevoegd gezag

## Event Contracts

### VergunningStatusChangedEvent (dispatched by Procest, consumed by OpenConnector)
- `vergunningaanvraagRef` — Object reference
- `oldStatus` / `newStatus` — From DSO status enum
- `besluitdatum` (optional) — When `verleend` / `geweigerd`
- `toelichting` (optional) — Decision motivation
- `userId` — Initiating user (for audit)

## Deadline Calculation

- Reguliere procedure: 8 weeks (40 working days) from `indieningsdatum`
- Uitgebreide procedure: 26 weeks (130 working days) from `indieningsdatum`
- Working days exclude weekends and Dutch national holidays (computed via `nl-holidays` table or `IDateTimeZone`)
- Warning at 6 weeks remaining (reguliere) / 4 weeks remaining (uitgebreide)
- Critical warning at 2 weeks remaining
- Overdue flag when `deadlineDatum < today`

## Integration Boundary

- OpenConnector writes vergunningaanvragen into OpenRegister via REST API; no direct DB
- Procest listens to `ObjectCreatedEvent` for the `vergunningaanvraag` schema and creates zaak through its own service
- Procest writes status changes to both the OpenRegister vergunningaanvraag and the Procest zaak (single transaction via `DsoCaseService.transitionStatus`)
- OpenConnector listens to `VergunningStatusChangedEvent` and pushes to DSO-LV (no direct write back to Procest)
- All cross-app coordination is event-driven; no synchronous calls between apps



## Tasks

# Tasks: dso-omgevingsloket

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Seed an `omgevingsvergunning` case type in `lib/Settings/procest_register.json` with statuses `ingediend`, `in_behandeling`, `verleend`, `geweigerd`, `ingetrokken`, and procedure variants (reguliere = 8 weeks, uitgebreide = 26 weeks). Add a new `samenwerkverzoek` schema (initiatorBevoegdGezag, aangezochtBevoegdGezag, vergunningaanvraagRef, rationale, status enum, advies, aangevraagdOp, gereageerdOp). Add zaak extension fields (`vergunningaanvraagRef`, `procedureType`, `deadlineDatum`, `bevoegdGezag`, `samenwerkverzoeken`) on the case schema. Add config keys `dso_case_type`, `dso_deadline_warning_weeks_warning`, `dso_deadline_warning_weeks_critical`, `dso_beschikking_template_verleend`, `dso_beschikking_template_geweigerd`, `dso_samenwerkverzoek_schema` to `SettingsService.php` CONFIG_KEYS and SLUG_TO_CONFIG_KEY arrays.

### Backend Services

- [ ] **T02**: Create `lib/Service/DsoCaseService.php` — Methods: `createZaakFromVergunningaanvraag(vergunningaanvraagId)` looks up the object, determines `procedureType` from `activiteiten` (default reguliere; uitgebreide when any activiteit flagged), computes `deadlineDatum` using working-day calculator, creates a Procest zaak via `ObjectService`, stores `vergunningaanvraagRef` on the zaak; `transitionStatus(zaakId, newStatus, besluitdatum, toelichting, userId)` updates both the Procest zaak and the OpenRegister vergunningaanvraag in a single service call, appends activity entry, dispatches `VergunningStatusChangedEvent`; `computeDeadline(indieningsdatum, procedureType)` returns target date excluding weekends and Dutch national holidays.

- [ ] **T03**: Create `lib/Service/BeschikkingGenerationService.php` and `lib/Service/SamenwerkverzoekService.php` — Beschikking service: `generateBeschikking(zaakId, outcome, motivation)` picks template from `dso_beschikking_template_verleend|geweigerd` config, calls Docudesk to render PDF, attaches as `bijlage` with `type: beschikking` on the vergunningaanvraag, stores PDF in case folder, sends notification to behandelaar. Samenwerk service: `initiateSamenwerking(zaakId, aangezochtBevoegdGezag, rationale)` creates `samenwerkverzoek` object and dispatches an `SamenwerkverzoekInitiatedEvent` for OpenConnector to forward; `respondToSamenwerking(samenwerkId, accept, advies)` updates status and dispatches response event.

- [ ] **T04**: Create `lib/Event/VergunningStatusChangedEvent.php` and `lib/Listener/VergunningaanvraagCreatedListener.php` — Typed event carries `vergunningaanvraagRef`, `oldStatus`, `newStatus`, optional `besluitdatum` and `toelichting`, `userId`. Register listener for OpenRegister's `ObjectCreatedEvent` filtered to the vergunningaanvraag schema id (read from `IAppConfig`); on event, calls `DsoCaseService.createZaakFromVergunningaanvraag()`. Register listener and event subscriber in `Application::register()`.

### Background Jobs

- [ ] **T05**: Create `lib/BackgroundJob/DsoDeadlineJob.php` — `TimedJob` (daily). Queries all open omgevingsvergunning zaken (status `ingediend` or `in_behandeling`), computes remaining working days to `deadlineDatum`. Sends warning notification at the configured warning threshold (default 14 working days), critical notification at the critical threshold (default 5 working days), and an overdue flag/notification when past deadline. Uses `INotificationManager`. Catches exceptions per task to avoid full-job failure.

### Controllers & Routes

- [ ] **T06**: Create `lib/Controller/DsoController.php` and register routes — Authenticated controller with endpoints: `dashboard()` returns filterable VTH list (filters: activiteitgroep, regelkwalificatie, status, locatie, gemeenteCode, procedureType, deadlineRange); `transitionStatus(caseId)` -> `DsoCaseService.transitionStatus()`; `generateBeschikking(caseId)` -> beschikking service; `initiateSamenwerking(caseId)`; `respondSamenwerking(samenwerkId)`; `doorsturen(caseId)` dispatches `VergunningDoorgestuurdEvent` for OpenConnector. All `@NoAdminRequired`. Register routes in `appinfo/routes.php` under `/api/dso/` before SPA catch-all.

### Frontend Components

- [ ] **T07**: Create `src/views/dso/VthDashboard.vue` and `src/views/dso/DsoCaseDetail.vue` — Dashboard renders an omgevingsvergunningen list with multi-select filters (activiteitgroep, regelkwalificatie, status, gemeenteCode, procedureType), deadline column with colored indicator (green / yellow / red / overdue), bulk actions (open in detail, export CSV). Detail view shows DSO data sections (Aanvraag, Activiteiten, Locatie, Initiatiefnemer, Bijlagen, Samenwerkverzoeken) sourced from the linked vergunningaanvraag, plus the Procest activity timeline and the case lifecycle action bar (status transition, generate beschikking, initiate samenwerking, forward).

- [ ] **T08**: Create `src/views/dso/SamenwerkverzoekDialog.vue`, `src/views/dso/DoorstuurDialog.vue`, and `src/views/dso/BeschikkingDialog.vue` — Samenwerk dialog: bevoegd-gezag selector (autocomplete from gemeenten/waterschappen/provincies list), rationale textarea, submit calls `/api/dso/cases/{caseId}/samenwerking`. Doorstuur dialog: target bevoegd-gezag selector + reden field; posts to `/api/dso/cases/{caseId}/doorstuur`. Beschikking dialog: outcome selector (verleend/geweigerd), Docudesk template preview, motivation editor (pre-filled per outcome), confirm; posts to `/api/dso/cases/{caseId}/beschikking`.

## Verification Tasks

- [ ] **V01**: New `samenwerkverzoek` schema and zaak extension fields valid JSON; config keys populated after install
- [ ] **V02**: When OpenConnector writes a new vergunningaanvraag, listener creates a Procest zaak with correct `procedureType` and `deadlineDatum`
- [ ] **V03**: Status transition on Procest zaak writes both Procest zaak and OpenRegister vergunningaanvraag in one service call
- [ ] **V04**: `VergunningStatusChangedEvent` is dispatched and observable by a test listener
- [ ] **V05**: Deadline job sends warning at warning threshold and critical at critical threshold; overdue cases get an overdue flag
- [ ] **V06**: Working-day calculator excludes weekends and Dutch national holidays
- [ ] **V07**: Beschikking generation produces a PDF, attaches as `bijlage` with `type: beschikking`, sends notification
- [ ] **V08**: Samenwerkverzoek can be initiated, accepted with advies, and rejected with rationale; status enum transitions enforced
- [ ] **V09**: Doorstuur dispatches event; OpenConnector test double receives it with reden
- [ ] **V10**: VTH dashboard filters by activiteitgroep, regelkwalificatie, status, locatie and shows deadline colour indicator
