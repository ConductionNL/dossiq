# n8n workflows — consultation-management

> Companion to `openspec/changes/consultation-management/specs/consultation-management/spec.md`.
> Three workflows ship under `n8n/` and cover Awb 3:5-3:9 adviesrecht
> obligations: deadline monitoring, external-body email fan-out, and
> coordinator bottleneck alerts.

All workflows authenticate to procest via HTTP Basic auth (the n8n credential
must be created as type "HTTP Basic Auth" and referenced from each HTTP request
node). The `OCS-APIRequest: true` header is set so Nextcloud accepts JSON without
the web login redirect.

## 1. `consultation-deadline-monitor.json`

- **Trigger.** `n8n-nodes-base.scheduleTrigger`, daily at 07:00 (24h interval).
- **Inputs.**
  - `GET /api/consultations?deadlineWithin=P5D&status=uitgevraagd,in_behandeling` — consultations whose `uiterlijkeReactiedatum` falls in the next 5 days.
  - `GET /api/consultations/overdue` — consultations past the deadline (delegates to `ConsultationService::getOverdueConsultations`).
- **Output.** One `POST /api/notifications/send` per consultation with
  `template: consultation-deadline-warning` (priority `warning`) or
  `consultation-deadline-overdue` (priority `overdue`).
- **Recipient.** Internal bodies → NC group resolved via `adviesinstantieId`.
  External bodies → the configured email is dispatched by the procest
  NotificationService side (n8n only enqueues the notification request).

## 2. `consultation-email-fanout.json`

- **Trigger.** Webhook at `POST /webhook/procest/consultation-created`,
  fired by `ConsultationService::createConsultation` when the resolved advisory
  body has `type === 'external'`.
- **Expected payload.**

  ```json
  {
    "consultationId": "<uuid>",
    "consultationNummer": "ADV-2026-0001",
    "caseId": "<uuid>",
    "caseTitle": "Omgevingsvergunning Dorpsstraat 12",
    "adviesinstantie": {
      "id": "<uuid>",
      "naam": "GGD Regio Utrecht",
      "email": "advies@ggdru.nl",
      "type": "external"
    },
    "onderwerp": "Adviesaanvraag milieu",
    "vraag": "...",
    "uiterlijkeReactiedatum": "2026-07-08",
    "responseToken": "<plaintext-256-bit>",
    "responseUrl": "https://example.gemeente.nl/index.php/apps/procest/external/consultations/<token>",
    "attachments": [
      { "documentUuid": "<uuid>", "fileName": "tekening.pdf" }
    ]
  }
  ```

- **Security.**
  - The `responseToken` is delivered ONCE by procest (stored as SHA-256 hash);
    the workflow does NOT log the plaintext token anywhere.
  - The webhook itself is unauthenticated (n8n-side), but the validate node
    rejects payloads missing `responseToken`/`responseUrl` so a bad caller
    cannot trigger an empty email.
- **Side-effect.** After sending the email the workflow calls
  `POST /api/consultations/{id}/audit` with `event: external-email-sent` so the
  BIO-compliant audit trail records the delivery.

## 3. `consultation-bottleneck-detection.json`

- **Trigger.** `n8n-nodes-base.scheduleTrigger`, daily at 08:00 (24h interval).
- **Input.** `GET /api/consultations/analytics?groupBy=adviesinstantieId&window=P30D`
  ⇒ `{bodies: [...]}` with `totalLast30Days`, `overdueLast30Days`,
  `avgDoorlooptijdDagen`, `avgDoorlooptijdDagenPrev30` per body.
- **Rule.** Spec scenario "Consultation bottleneck detection" — when the
  30-day overdue rate exceeds **20%** the coordinator MUST be alerted.
- **Output.** One `POST /api/notifications/send` per offending body with
  `recipientGroup: consultation-coordinators` and a localised message of the
  form `"Welstandscommissie: 8 verlopen adviezen, gemiddelde doorlooptijd
  gestegen naar 25 dagen"`.

## Configuration

| Env var | Purpose | Default |
| --- | --- | --- |
| `PROCEST_BASE_URL` | Base URL of the procest Nextcloud instance | `http://nextcloud` |
| `PROCEST_FROM_EMAIL` | From-address for outbound mail | `consultations@gemeente.nl` |

The service account behind the HTTP Basic credential needs:

- Read access to `/api/consultations*` and `/api/consultations/analytics`.
- Write access to `/api/consultations/{id}/audit`.
- Write access to `/api/notifications/send`.

## Local verification

1. **Deadline monitor.** Seed two consultations with `uiterlijkeReactiedatum`
   `today + 2 days` and `today - 1 day`. Trigger the workflow; assert two
   notification fan-outs (one warning, one overdue) appear in the n8n run log.
2. **Email fan-out.** POST a stub payload (with a dummy `responseToken`) to the
   webhook URL; assert one outbound email and one audit-log entry.
3. **Bottleneck detection.** Seed an advisory body with 10 consultations of
   which 3 are overdue in the last 30 days; trigger the workflow; assert one
   coordinator notification with `overdueRatePct: 30`.
