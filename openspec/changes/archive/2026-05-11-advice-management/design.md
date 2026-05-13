# Design: advice-management

## Architecture Overview

Advice requests are `adviesAanvraag` OpenRegister objects linked to cases. The `AdviceService` handles lifecycle transitions and notification dispatch. The frontend embeds the advice panel directly in the case detail view.

```
CaseDetail.vue
└── AdviesPanel.vue (list advice requests, "Advies aanvragen" button)
    └── AdviesAanvraagDialog.vue (create form: adviseur, type, deadline, questions)

WorkflowTransitionButton.vue
└── adviesGuard check (blocks if any adviesAanvraag has status="aangevraagd")

Background
└── AdviceDeadlineJob.php (daily — expire overdue, send reminders)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/AdviceService.php` | Advice CRUD, status transitions, notification dispatch, timeline recording |
| `lib/Controller/AdviceController.php` | Authenticated REST endpoints for advice management |
| `lib/BackgroundJob/AdviceDeadlineJob.php` | Daily job: sends reminders 3 days before deadline, expires overdue requests |
| `src/views/cases/components/AdviesPanel.vue` | Advice list on case detail: type badges, status badges, overdue highlight, quick actions |
| `src/views/cases/components/AdviesAanvraagDialog.vue` | Create advice request dialog: adviseur selector, type toggle, deadline picker, questions |
| `src/services/adviceApi.js` | Frontend API service for advice endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `adviesAanvraag` schema and seed objects |
| `lib/Service/SettingsService.php` | Add config keys: `advice_schema`, `advice_reminder_days` (default 3) |
| `appinfo/routes.php` | Add advice routes |
| `src/views/cases/CaseDetail.vue` | Embed `AdviesPanel` component |
| `src/views/workflow/WorkflowTransitionButton.vue` | Add adviesGuard check before triggering transition |

## Data Model

Uses the `adviesAanvraag` entity exactly as defined in ADR-000. OpenRegister built-in fields (`id`, `uuid`, `createdAt`, `updatedAt`, `status`, `tasks`, etc.) are available automatically.

### adviesAanvraag (from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case (UUID) |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | string | Yes | `intern` or `extern` |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | string | No | ISO 8601 date — deadline for receiving advice |
| status | string | No | `aangevraagd`, `ontvangen`, `verlopen` |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | string | No | ISO 8601 datetime when advice was requested |
| receivedAt | string | No | ISO 8601 datetime when advice was received |
| questions | string | No | Specific questions for the adviseur |

**Status lifecycle:**
```
aangevraagd → ontvangen   (adviseur uploads document and marks complete)
aangevraagd → verlopen    (deadline passes without response — set by AdviceDeadlineJob)
```

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/advice` | List advice requests (filter by `case`) |
| POST | `/api/advice` | Create advice request |
| GET | `/api/advice/{id}` | Get single advice request |
| PUT | `/api/advice/{id}` | Update advice request (mark ontvangen, upload doc) |
| DELETE | `/api/advice/{id}` | Delete advice request |
| POST | `/api/advice/{id}/remind` | Manually send reminder to adviseur |

## Seed Data

The following seed objects MUST be included in `procest_register.json` under `components.objects[]`. All slugs are unique for idempotent re-import.

### adviesAanvraag — 5 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "adviesAanvraag",
    "slug": "advies-welstand-2026-0042"
  },
  "case": "zaak-omgevingsvergunning-0042",
  "adviseur": "welstandscommissie",
  "type": "intern",
  "onderwerp": "Welstandstoets gevelwijziging Keizersgracht 123",
  "deadline": "2026-04-30",
  "status": "aangevraagd",
  "requestedAt": "2026-04-16T09:00:00+02:00",
  "questions": "Voldoet de voorgestelde gevelwijziging aan het welstandsbeleid voor de historische binnenstad?"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "adviesAanvraag",
    "slug": "advies-veiligheidsregio-2026-0038"
  },
  "case": "zaak-evenementenvergunning-0038",
  "adviseur": "Veiligheidsregio Amsterdam-Amstelland",
  "type": "extern",
  "onderwerp": "Veiligheidsadvies evenement Museumplein 500+ bezoekers",
  "deadline": "2026-04-25",
  "status": "aangevraagd",
  "requestedAt": "2026-04-10T14:30:00+02:00",
  "questions": "Is de nooduitgang-capaciteit voldoende voor 500 bezoekers? Zijn er aanvullende EHBO-posten vereist?"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "adviesAanvraag",
    "slug": "advies-rud-2026-0031"
  },
  "case": "zaak-milieumelding-0031",
  "adviseur": "Regionale Uitvoeringsdienst Noord-Holland",
  "type": "extern",
  "onderwerp": "Milieukundig advies lozing grondwater bouwproject",
  "deadline": "2026-03-28",
  "status": "verlopen",
  "requestedAt": "2026-03-07T10:00:00+01:00",
  "questions": "Is lozing van het opgepompte grondwater toelaatbaar gezien de nabijgelegen watergang?"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "adviesAanvraag",
    "slug": "advies-juridisch-2026-0055"
  },
  "case": "zaak-bezwaar-0055",
  "adviseur": "juridische-dienst",
  "type": "intern",
  "onderwerp": "Juridische toets ontvankelijkheid bezwaarschrift",
  "deadline": "2026-05-02",
  "status": "ontvangen",
  "requestedAt": "2026-04-11T08:45:00+02:00",
  "receivedAt": "2026-04-15T16:20:00+02:00",
  "questions": "Is het bezwaar tijdig ingediend en is de bezwaarmaker ontvankelijk?"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "adviesAanvraag",
    "slug": "advies-brandweer-2026-0047"
  },
  "case": "zaak-bouwvergunning-0047",
  "adviseur": "Brandweer Amsterdam-Amstelland",
  "type": "extern",
  "onderwerp": "Brandveiligheidsadvies transformatie kantoorpand naar woningen",
  "deadline": "2026-05-14",
  "status": "aangevraagd",
  "requestedAt": "2026-04-14T11:00:00+02:00",
  "questions": "Voldoet het vluchtrouteplan aan de eisen uit het Bouwbesluit 2012, art. 2.113 e.v.?"
}
```

## Reuse Analysis

The following existing OpenRegister and platform capabilities are reused — no custom implementations needed:

| Capability | Platform Component | Reuse |
|------------|-------------------|-------|
| CRUD REST for `adviesAanvraag` | `ObjectService.saveObject()` / `findObjects()` (3-arg API) | AdviceService delegates all persistence to ObjectService |
| Audit trail for advice lifecycle | Automatic per-object audit trail (OpenRegister built-in) | No custom audit logging needed |
| Task creation for adviseur/behandelaar | `TasksController` (platform) | `AdviceService` calls TasksController to create tasks |
| Nextcloud notifications | `NotificatieService` (platform) | Used for reminder and escalation notifications |
| File attachment for adviesDocument | `FileService` + `CnFilesTab` (platform) | Adviseur uploads via standard file tab on adviesAanvraag object |
| Store + CRUD in frontend | `createObjectStore('advies-aanvraag')` | Standard Pinia store with relationsPlugin |
| Relation to case | OpenRegister relations API | adviesAanvraag.case stores case UUID; `fetchUses` retrieves linked case |
| Form dialog | `CnFormDialog` | Not used here — custom dialog needed for intern/extern toggle and user picker |

**No overlap found** with existing services in `openregister/lib/Service/` or `openspec/specs/` for advice-specific logic. The custom `AdviceService` is justified because it must orchestrate: status transitions, notification dispatch, timeline recording, and task creation in a single transaction — this domain choreography is not provided by the platform.
