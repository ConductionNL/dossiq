# n8n Consultation Workflows

This document describes the three n8n workflow templates that power consultation lifecycle automation in Procest, together with their webhook contracts.

## Overview

| Workflow | Trigger | Purpose |
|---|---|---|
| `consultation-deadline-monitor` | Cron (daily 07:00) | Warns advisors of approaching deadlines; escalates overdue consultations |
| `consultation-email-fanout` | Webhook (consultation created) | Sends the initial consultation request email to external advisory bodies |
| `consultation-bottleneck-detection` | Webhook (status changed) | Flags overdue mandatory consultations that are blocking case progression |

---

## 1. Deadline Monitor Workflow

**ID:** `consultation-deadline-monitor`

### Trigger
Scheduled cron — runs daily at `07:00` local time.

### Steps
1. `GET /api/consultations?status=open,ontvangen,in_behandeling` — fetch all active consultations
2. For each consultation compute `daysUntilDeadline = uiterlijkeReactiedatum - today`
3. If `daysUntilDeadline <= 3` and no `deadline_warned_at` field set → POST to `/api/consultations/{id}/status` with `status=in_behandeling` warning annotation, then call the Nextcloud notification endpoint
4. If `daysUntilDeadline < 0` → fetch assigned user, send overdue email, emit `consultation_overdue` notification

### Outbound webhook from Procest
Procest emits this payload to the n8n webhook URL configured in settings when a consultation transitions to `in_behandeling`:

```json
{
  "event": "consultation.status_changed",
  "consultationId": "uuid-v4",
  "consultationNumber": "ADV-2026-0042",
  "previousStatus": "ontvangen",
  "newStatus": "in_behandeling",
  "uiterlijkeReactiedatum": "2026-06-15",
  "advisoryBodyId": "uuid-v4",
  "requesterId": "nextcloud-uid",
  "timestamp": "2026-05-19T07:00:00Z"
}
```

### Inbound call from n8n → Procest
The monitor calls `/api/consultations/overdue` (GET, authenticated service account) to retrieve a list of overdue consultations. Response shape:

```json
{
  "results": [
    {
      "id": "uuid-v4",
      "consultationNumber": "ADV-2026-0040",
      "status": "in_behandeling",
      "uiterlijkeReactiedatum": "2026-05-10",
      "parentZaak": "uuid-v4",
      "advisoryBodyId": "uuid-v4"
    }
  ]
}
```

---

## 2. Email Fanout Workflow

**ID:** `consultation-email-fanout`

### Trigger
Webhook — Procest calls the n8n webhook URL immediately after a consultation is created for an **external** advisory body.

### Inbound webhook payload (Procest → n8n)

```json
{
  "event": "consultation.created",
  "consultationId": "uuid-v4",
  "consultationNumber": "ADV-2026-0043",
  "onderwerp": "Brandveiligheidsadvies nieuwbouw Spuistraat 12",
  "vraagstelling": "Is het bouwplan brandveilig conform Bouwbesluit 2012?",
  "uiterlijkeReactiedatum": "2026-06-30",
  "advisoryBody": {
    "id": "uuid-v4",
    "name": "Brandweer Amsterdam-Amstelland",
    "email": "advies@brandweer.amsterdam.nl",
    "type": "external"
  },
  "secureToken": "64-char-hex-token",
  "responseUrl": "https://your-nc.example/index.php/apps/procest/public/consultation/{token}",
  "timestamp": "2026-05-19T10:30:00Z"
}
```

### Steps
1. Validate `advisoryBody.type === 'external'` and `advisoryBody.email` is non-empty
2. Render the Dutch email template with `onderwerp`, `vraagstelling`, `uiterlijkeReactiedatum`, `responseUrl`
3. Send via the configured SMTP node
4. Log delivery status back to Procest via `POST /api/consultations/{consultationId}/status` comment field (optional audit trail)

### Email template (Dutch)

```
Geachte,

Er is een adviesaanvraag ingediend voor uw organisatie.

Adviesaanvraag: {consultationNumber}
Onderwerp: {onderwerp}
Vraagstelling: {vraagstelling}
Deadline: {uiterlijkeReactiedatum}

U kunt uw advies indienen via:
{responseUrl}

Met vriendelijke groet,
Procest
```

> **Security note:** The `secureToken` embedded in `responseUrl` is a 256-bit cryptographically random value (`bin2hex(random_bytes(32))`). Never log the full token; log only the first 8 characters.

---

## 3. Bottleneck Detection Workflow

**ID:** `consultation-bottleneck-detection`

### Trigger
Webhook — Procest calls this endpoint whenever a consultation status changes.

### Inbound webhook payload (Procest → n8n)

```json
{
  "event": "consultation.status_changed",
  "consultationId": "uuid-v4",
  "consultationNumber": "ADV-2026-0041",
  "previousStatus": "in_behandeling",
  "newStatus": "advies_uitgebracht",
  "obligation": "mandatory",
  "parentZaak": "uuid-v4",
  "timestamp": "2026-05-19T14:00:00Z"
}
```

### Steps
1. If `obligation !== 'mandatory'` → skip (no blocking risk)
2. Call `GET /api/consultations/blocking/{parentZaak}` to retrieve current blocking consultations
3. If `blocked === true`:
   a. Fetch zaak handler from `/api/zaken/{parentZaak}`
   b. Emit Nextcloud notification to handler: "Zaak {zaakNummer} is geblokkeerd door {n} openstaande verplichte adviezen"
   c. Log bottleneck event in case activity timeline via `POST /api/zaken/{parentZaak}/activity`
4. If `blocked === false` and this was the last blocker → emit "Alle verplichte adviezen ontvangen" notification to case handler

### `/api/consultations/blocking/{caseId}` response

```json
{
  "results": [
    {
      "id": "uuid-v4",
      "consultationNumber": "ADV-2026-0041",
      "status": "in_behandeling",
      "obligation": "mandatory",
      "advisoryBodyId": "uuid-v4",
      "uiterlijkeReactiedatum": "2026-06-01"
    }
  ],
  "blocked": true
}
```

---

## Authentication

All inbound calls from n8n to the Procest REST API must use a dedicated service-account Basic Auth credential configured in the n8n credentials vault. The account requires the `procest:api` role (standard logged-in Nextcloud user).

Outbound webhooks from Procest to n8n use the webhook URL stored in the Procest admin settings under **Settings → Procest → n8n webhook URL**.

---

## Configuration (admin settings)

| Setting key | Description |
|---|---|
| `n8n_deadline_monitor_url` | n8n webhook URL for the deadline monitor |
| `n8n_email_fanout_url` | n8n webhook URL for the email fanout |
| `n8n_bottleneck_url` | n8n webhook URL for bottleneck detection |

These are stored via `SettingsService::setConfigValue()` and read by `ConsultationService` when dispatching outbound events.
