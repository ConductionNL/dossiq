# Consultation Management (Adviesaanvraag)

Structured inter-departmental consultation (adviesaanvraag) as a first-class entity in Dossiq, implementing the legal framework from Awb articles 3:5-3:9.

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

### Asking a body outside the organisation

| Method | URL | Description |
|---|---|---|
| POST | `/api/consultations/{id}/external-link` | Invite the advisory body over a case access link |
| POST | `/api/consultations/{id}/advice` | Collect the comment they wrote, as the response |

The body has no account. It receives an OpenRegister access link over the case
(openregister#3817) declaring reading and commenting, expiring on the
consultation deadline. Its advice is a comment written as the link, recorded on
the audit trail as `link:<uuid>`. The handler collects that comment and names
which of the four codified outcomes it carries.

The token page this replaced could never be entered: nothing minted the token
it read. It was deleted by `case-sharing-mints-access-links`.

## n8n Workflows

Three n8n workflows support this feature:

1. **Deadline Monitor** — daily cron; sends T-5 warning and T+0 overdue escalation
2. **External Body Email Fanout** — triggered on consultation creation; sends secure response link to external bodies
3. **Bottleneck Detection** — weekly cron; alerts coordinators when a body's overdue rate exceeds 20%

Webhook contract for the email fanout (called by Dossiq on consultation create for external body):

```json
{
  "consultationId": "uuid",
  "consultationNumber": "ADV-2026-0015",
  "onderwerp": "Brandveiligheidsadvies",
  "vraagstelling": "Is het gebouw brandveilig?",
  "uiterlijkeReactiedatum": "2026-07-01",
  "secureResponseUrl": "https://gemeente.nl/index.php/apps/openregister/link/{anchor}",
  "advisoryBodyEmail": "ggd@regioutrecht.nl"
}
```

## Security

- The link is OpenRegister's, not dossiq's: OpenRegister owns the address, the expiry, the optional password and the revoke
- Reading is always granted. Commenting is granted only when the link says so, and an undeclared capability answers 403
- Unknown, revoked, switched off and expired all answer the same 404, so a holder learns nothing from the answer
- Every use is written to the audit trail with the link as the actor, so four uses of one link are four entries naming it
- Document-scope isolation: consulted parties only see documents explicitly linked to their consultation

## Mandatory Gates

`ConsultationService::getBlockingConsultations(zaakId)` returns mandatory consultations not yet in `advies_uitgebracht` or `afgesloten`. The MilestoneController uses this to block case progression:

> "Verplicht advies '`{subject}`' is nog niet ontvangen"

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
