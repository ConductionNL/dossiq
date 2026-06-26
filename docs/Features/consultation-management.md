# Consultation Management (Adviesaanvraag)

Structured inter-departmental consultation (adviesaanvraag) as a first-class entity in Procest, implementing the legal framework from Awb articles 3:5-3:9.

## Overview

A consultation is a mini-case linked to a parent case, with its own lifecycle, assigned participants, documents, due dates, and formal response. This replaces informal email-based advice requests with tracked, auditable departmental coordination.

## Data Model

| Schema | Purpose |
|---|---|
| `consultation` | The consultation request entity (`ADV-{year}-{seq}`) |
| `adviceResponse` | Structured advice response with formal conclusion |
| `advisoryBody` | Registry of departments and external advisory bodies |

## Consultation Lifecycle

```
open → ontvangen → in_behandeling → advies_uitgebracht → afgesloten
                                    ↘
                              ingetrokken (side branch)
```

## API Endpoints

### Authenticated

| Method | URL | Description |
|---|---|---|
| GET | `/api/consultations/case/{caseId}` | List consultations for a case |
| POST | `/api/consultations` | Create consultation |
| GET | `/api/consultations/{id}` | Get single consultation |
| DELETE | `/api/consultations/{id}` | Delete consultation |
| POST | `/api/consultations/{id}/status` | Update status |
| POST | `/api/consultations/{id}/response` | Submit advice response |
| POST | `/api/consultations/{id}/extension` | Request extension |
| POST | `/api/consultations/{id}/extension/approve` | Approve extension |
| GET | `/api/consultations/overdue` | List overdue |
| GET | `/api/advisory-bodies` | List advisory bodies |
| GET | `/api/advisory-bodies/search?q={q}` | Search by specialization |

### Public (BIO-audited, token-based)

| Method | URL | Description |
|---|---|---|
| GET | `/api/public/consultations/{token}` | External body: view |
| POST | `/api/public/consultations/{token}` | External body: submit advice |

## n8n Workflows

Three n8n workflows support this feature:

1. **Deadline Monitor** — daily cron; sends T-5 warning and T+0 overdue escalation
2. **External Body Email Fanout** — triggered on consultation creation; sends secure response link to external bodies
3. **Bottleneck Detection** — weekly cron; alerts coordinators when a body's overdue rate exceeds 20%

Webhook contract for the email fanout (called by Procest on consultation create for external body):

```json
{
  "consultationId": "uuid",
  "consultationNumber": "ADV-2026-0015",
  "onderwerp": "Brandveiligheidsadvies",
  "vraagstelling": "Is het gebouw brandveilig?",
  "uiterlijkeReactiedatum": "2026-07-01",
  "secureResponseUrl": "https://gemeente.nl/apps/procest/api/public/consultations/{token}",
  "advisoryBodyEmail": "ggd@regioutrecht.nl"
}
```

## Security

- Secure tokens: 256-bit (32 random bytes), hex-encoded to 64 characters
- Token expires when consultation is closed or withdrawn
- All external access via `/api/public/consultations/{token}` is logged (BIO compliance)
- Document-scope isolation: consulted parties only see documents explicitly linked to their consultation

## Mandatory Gates

`ConsultationService::getBlockingConsultations(zaakId)` returns mandatory consultations not yet in `advies_uitgebracht` or `afgesloten`. The MilestoneController uses this to block case progression:

> "Verplicht advies '{subject}' is nog niet ontvangen"

## Existing Features

- **Consultation period management** -- Define start and end dates for consultation windows.
- **Stakeholder registration** -- Track who has submitted input.
- **Response collection** -- Collect and categorize consultation responses.
- **Response processing** -- Review, assess, and respond to each submission.
- **Consideration report** -- Generate the formal "nota van beantwoording" (response memorandum).
- **Publication** -- Publish consultation documents and outcomes.
- **Timeline tracking** -- Ensure compliance with legally mandated consultation periods.
- **Notification** -- Notify stakeholders of outcomes and decisions.

## Legal Context

Various Dutch laws require public consultation, including:
- Wet ruimtelijke ordening (Spatial Planning Act)
- Omgevingswet (Environment and Planning Act)
- Algemene wet bestuursrecht (General Administrative Law Act)

## Status

This feature is defined in the spec at `openspec/specs/consultation-management/spec.md` and is planned for future implementation.
