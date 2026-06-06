# Design: dso-omgevingsloket

status: pr-created

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
