# n8n workflows — consultation-management

> Companion to `openspec/changes/consultation-management/specs/consultation-management/spec.md`.
> Three workflows ship under `n8n/` and cover Awb 3:5-3:9 adviesrecht obligations: deadline monitoring, external-body email fan-out, and coordinator bottleneck alerts.

Drie n8n-workflows automatiseren het levenscyclusbeheer van adviesaanvragen (`consultation`-objecten). Deze pagina beschrijft de webhook-contracten, triggers en verwachte effecten, zodat beheerders de workflows kunnen installeren, monitoren en aanpassen.

## Overzicht / At a glance

| Workflow | Trigger | Frequentie | Doel |
|----------|---------|------------|------|
| `consultation-deadline-monitor` | Schedule | dagelijks 07:00 Europe/Amsterdam | T-5 waarschuwingen en overdue-escalaties |
| `consultation-email-fanout` | Webhook van Procest | direct bij creatie | externe adviesinstantie informeren met secure link |
| `consultation-bottleneck-detection` | Schedule | dagelijks 08:00 Europe/Amsterdam | knelpunt-melding wanneer overdue-rate > 20 % |

All workflows authenticate to procest via HTTP Basic auth (the n8n credential must be created as type "HTTP Basic Auth" and referenced from each HTTP request node). The `OCS-APIRequest: true` header is set so Nextcloud accepts JSON without the web login redirect. Inkomende webhooks van Procest zijn beveiligd met een HMAC-SHA256 signature in `X-Procest-Signature`.

## 1. `consultation-deadline-monitor.json`

Cron-job die dagelijks alle `consultation`-objecten met status `open`, `uitgevraagd`, `ontvangen` of `in_behandeling` doorloopt.

### Stappen

1. **Schedule trigger** — `n8n-nodes-base.scheduleTrigger`, daily at 07:00 (24 h interval).
2. **GET** `/api/consultations?deadlineWithin=P5D&status=uitgevraagd,in_behandeling` — consultations whose `uiterlijkeReactiedatum` falls in the next 5 days.
3. **GET** `/api/consultations/overdue` — consultations past the deadline (delegates to `ConsultationService::getOverdueConsultations`).
4. **Splits** op basis van `uiterlijkeReactiedatum`:
    - `today + 5d == uiterlijkeReactiedatum` → T-5 waarschuwing.
    - `today > uiterlijkeReactiedatum` → overdue-escalatie.
5. **POST** `/api/notifications/send` per consultation met `template: consultation-deadline-warning` (priority `warning`) of `consultation-deadline-overdue` (priority `overdue`).
6. Markeer `consultation.lastWarningAt` bij T-5 en `consultation.escalatedAt` bij overdue, zodat Procest dezelfde dag niet dubbel waarschuwt.

### Recipient resolution

- **Internal bodies** → NC group resolved via `adviesinstantieId`.
- **External bodies** → the configured email is dispatched by the procest NotificationService side (n8n only enqueues the notification request).

### Procest notification contract — `/api/consultations/{id}/notify`

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| `event` | enum | ja | `deadline_warning`, `deadline_overdue`, `extension_requested`, `extension_approved`, `acknowledged`, `advice_submitted` |
| `channel` | csv | ja | combinatie van `email`, `nextcloud_notification`, `slack` |
| `recipients` | array | ja | rollen die berichten ontvangen |
| `reason` | string | nee | optionele context die in de notificatie wordt opgenomen |

Response: `204 No Content` bij succes; `404` als de consultation niet bestaat; `409` als de notificatie voor dezelfde dag al verstuurd was (idempotent).

## 2. `consultation-email-fanout.json`

Wordt direct aangeroepen door Procest wanneer een consultation wordt aangemaakt voor een **externe** adviesinstantie (geen Nextcloud-account).

### Stappen

1. **Webhook trigger** — `POST /webhook/procest/consultation-created`, fired by `ConsultationService::createConsultation` when the resolved advisory body has `type === 'external'`.
2. Verifieer `X-Procest-Signature` met de gedeelde HMAC-key.
3. Bouw een e-mail op uit het template `external-consultation.mjml`.
4. **POST** naar de SMTP-node met:
    - Onderwerp: `Adviesaanvraag {{consultationNummer}} - {{onderwerp}}`.
    - Body: vraagstelling, deadline (datumformaat `d MMMM yyyy`), en een **secure response link** `{{responseUrl}}`.
    - Bijlagen: alle documenten met `attachments[].visibilityExternal == true`.
5. Verstuur de mail en log de message-id terug naar Procest via **POST** `/api/consultations/{id}/audit` met `event: external-email-sent` (BIO-compliant audit trail).

### Inkomend payload van Procest

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
    { "documentUuid": "<uuid>", "fileName": "tekening.pdf", "visibilityExternal": true }
  ]
}
```

### Security

- The `responseToken` is delivered ONCE by procest (stored as SHA-256 hash); the workflow does NOT log the plaintext token anywhere.
- The webhook itself is unauthenticated (n8n-side), but the validate node rejects payloads missing `responseToken`/`responseUrl` so a bad caller cannot trigger an empty email.
- Token verloopt zodra `consultation.status == afgesloten` of na 90 dagen, wat eerst komt.
- Endpoint `POST /consultation/respond/{token}` accepteert `adviceDocument` (multipart) + `adviceOutcome` (`positief|voorwaarden|negatief`) + `notes` en zet status naar `advies_uitgebracht`.

## 3. `consultation-bottleneck-detection.json`

Cron-job die dagelijks per `adviesinstantie` de overdue-rate over de laatste 30 dagen berekent en bij > 20 % een coördinatornotificatie verstuurt.

### Stappen

1. **Schedule trigger** — `n8n-nodes-base.scheduleTrigger`, daily at 08:00 (24 h interval).
2. **GET** `/api/consultations/analytics?groupBy=adviesinstantieId&window=P30D` ⇒ `{bodies: [...]}` met `totalLast30Days`, `overdueLast30Days`, `avgDoorlooptijdDagen`, `avgDoorlooptijdDagenPrev30` per body.
3. **Rule** (spec scenario "Consultation bottleneck detection") — when the 30-day overdue rate exceeds **20 %** the coordinator MUST be alerted.
4. **Output.** One `POST /api/notifications/send` per offending body with `recipientGroup: consultation-coordinators` en een gelokaliseerd bericht in de vorm: `"Welstandscommissie: 8 verlopen adviezen, gemiddelde doorlooptijd gestegen naar 25 dagen"`.

### Analytics endpoint contract

`GET /api/consultations/analytics?groupBy=adviesinstantieId&window=P30D`

```json
{
  "bodies": [
    {
      "adviesinstantie": { "id": "<uuid>", "naam": "Welstandscommissie" },
      "totalLast30Days": 12,
      "overdueLast30Days": 4,
      "overdueRate": 0.33,
      "avgDoorlooptijdDagen": 22.5,
      "avgDoorlooptijdDagenPrev30": 11.0
    }
  ]
}
```

## Configuration

| Env var | Purpose | Default |
| --- | --- | --- |
| `PROCEST_BASE_URL` | Base URL of the procest Nextcloud instance | `http://nextcloud` |
| `PROCEST_FROM_EMAIL` | From-address for outbound mail | `consultations@gemeente.nl` |

The service account behind the HTTP Basic credential needs:

- Read access to `/api/consultations*` and `/api/consultations/analytics`.
- Write access to `/api/consultations/{id}/audit`.
- Write access to `/api/notifications/send`.

## Installatie

1. Importeer de drie JSON-bestanden uit `n8n/` in n8n (Workflows → Import from File).
2. Maak een credential **HTTP Basic Auth** met de service account die `n8n-procest` uid heeft, en koppel die aan alle Procest HTTP-nodes.
3. Maak een credential **SMTP** met de uitgaande mailserver van de gemeente (TLS, poort 587).
4. Stel de tijdzone in op `Europe/Amsterdam` (n8n → Settings → Timezone).
5. Activeer de workflows. De cron-jobs draaien vanaf de eerstvolgende geplande tijd.

## Monitoring

- **Executions** — controleer in n8n → Executions of er failed runs zijn. Stuur een Slack-melding bij ≥ 1 failed run per dag.
- **Procest dashboard** — onder **Beheer → Procest → Integraties → n8n** verschijnt de laatste succesvolle uitvoeringstijd van elke workflow.
- **Audit trail** — elke notificatie schrijft een event in `consultation.auditTrail`. Coördinatoren kunnen via de zaakdetail-tab "Audit" zien welke n8n-acties hebben gelopen.

## Local verification

1. **Deadline monitor.** Seed two consultations with `uiterlijkeReactiedatum` `today + 2 days` and `today - 1 day`. Trigger the workflow; assert two notification fan-outs (one warning, one overdue) appear in the n8n run log.
2. **Email fan-out.** POST a stub payload (with a dummy `responseToken`) to the webhook URL; assert one outbound email and one audit-log entry.
3. **Bottleneck detection.** Seed an advisory body with 10 consultations of which 3 are overdue in the last 30 days; trigger the workflow; assert one coordinator notification with `overdueRatePct: 30`.

## Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Geen mail verstuurd naar externe instantie | SMTP-credential ontbreekt of relay-IP geblokkeerd | Test SMTP-credential in n8n; voeg n8n-egress-IP toe aan SPF/relay van de gemeente. |
| Dubbele T-5 waarschuwingen | `lastWarningAt` is leeg / niet teruggeschreven | Controleer dat de `notify` POST status 204 teruggeeft; werk het object alleen bij in Procest, niet in n8n. |
| Bottleneck-melding blijft uit | Analytics-endpoint returned `403` | Service account credential is verlopen — genereer opnieuw en update de credential. |
| Secure response link werkt niet | Token verlopen of consultation afgesloten | Coördinator opent de consultation en kiest **Token regenereren**, daarna stuurt Procest een nieuwe e-mail. |
