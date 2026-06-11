# n8n-workflows voor consultatiebeheer

Drie n8n-workflows automatiseren het levenscyclusbeheer van adviesaanvragen (`consultation`-objecten): deadlinebewaking, e-mailfan-out naar externe adviesinstanties en het detecteren van knelpunten. Deze pagina beschrijft de webhook-contracten, triggers en verwachte effecten, zodat beheerders de workflows kunnen installeren, monitoren en aanpassen.

> **Specs:** `openspec/changes/consultation-management/specs/consultation-management/spec.md` (§Deadline warning, §Overdue escalation, §Bottleneck detection)
> **Bestanden:** `n8n/consultation-deadline-monitor.json`, `n8n/consultation-email-fanout.json`, `n8n/consultation-bottleneck-detection.json`
> **Status:** in opbouw — de JSON-export volgt zodra `consultation-management` is geland.

## Overzicht

| Workflow | Trigger | Frequentie | Doel |
|----------|---------|------------|------|
| `consultation-deadline-monitor` | Cron | dagelijks 06:00 Europe/Amsterdam | T-5 waarschuwingen en overdue-escalaties |
| `consultation-email-fanout` | Webhook van Procest | direct bij creatie | externe adviesinstantie informeren met secure link |
| `consultation-bottleneck-detection` | Cron | dagelijks 07:00 Europe/Amsterdam | knelpunt-melding wanneer overdue-rate > 20 % |

Alle workflows authenticeren tegen Procest met een dedicated technische gebruiker (`n8n-procest`) en een app password. Inkomende webhooks van Procest zijn beveiligd met een HMAC-SHA256 signature in `X-Procest-Signature`.

## Workflow 1 — `consultation-deadline-monitor`

Cron-job die dagelijks alle `consultation`-objecten met status `open`, `ontvangen` of `in_behandeling` doorloopt.

### Stappen

1. **Schedule trigger** — `0 6 * * *` in tijdzone `Europe/Amsterdam`.
2. **GET** `/index.php/apps/procest/api/consultations?status=open,ontvangen,in_behandeling&_limit=500`.
3. **Splits** in twee paden op basis van `uiterlijkeReactiedatum`:
    - `today + 5d == uiterlijkeReactiedatum` → T-5 waarschuwing.
    - `today > uiterlijkeReactiedatum` → overdue-escalatie.
4. Voor elk geval: **POST** `/index.php/apps/procest/api/consultations/{id}/notify` met body:
    ```json
    {
      "event": "deadline_warning" | "deadline_overdue",
      "channel": "email,nextcloud_notification",
      "recipients": ["case_worker", "consulted_party", "case_coordinator"]
    }
    ```
5. Markeer `consultation.lastWarningAt` bij T-5 en `consultation.escalatedAt` bij overdue, zodat Procest dezelfde dag niet dubbel waarschuwt.

### Procest webhook-contract — `/api/consultations/{id}/notify`

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| `event` | enum | ja | `deadline_warning`, `deadline_overdue`, `extension_requested`, `extension_approved`, `acknowledged`, `advice_submitted` |
| `channel` | csv | ja | combinatie van `email`, `nextcloud_notification`, `slack` |
| `recipients` | array | ja | rollen die berichten ontvangen |
| `reason` | string | nee | optionele context die in de notificatie wordt opgenomen |

De response is `204 No Content` bij succes; `404` als de consultation niet bestaat; `409` als de notificatie voor dezelfde dag al verstuurd was (idempotent).

## Workflow 2 — `consultation-email-fanout`

Wordt direct aangeroepen door Procest wanneer een consultation wordt aangemaakt voor een **externe** adviesinstantie (geen Nextcloud-account).

### Stappen

1. **Webhook trigger** — `POST /webhook/consultation-created`.
2. Verifieer `X-Procest-Signature` met de gedeelde HMAC-key.
3. Indien `advisoryBody.type == external`: bouw een e-mail op uit het template `external-consultation.mjml`.
4. **POST** naar de SMTP-node met:
    - Onderwerp: `Adviesaanvraag {{consultationNumber}} - {{onderwerp}}`.
    - Body: vraagstelling, deadline (datumformaat `d MMMM yyyy`), en een **secure response link** `https://<procest-host>/consultation/respond/{token}`.
    - Bijlagen: alle documenten met `consultation.attachments[].visibilityExternal == true`.
5. Verstuur de mail en log de message-id terug naar Procest via **POST** `/api/consultations/{id}/external-log`:
    ```json
    { "channel": "email", "messageId": "<...>", "sentAt": "2026-06-11T08:14:22Z" }
    ```

### Inkomend payload van Procest

```json
{
  "consultationId": "uuid",
  "consultationNumber": "ADV-2026-0017",
  "parentZaak": "uuid",
  "advisoryBody": {
    "id": "uuid",
    "name": "GGD Regio Utrecht",
    "type": "external",
    "email": "advies@ggdru.nl"
  },
  "onderwerp": "Gezondheidskundige beoordeling",
  "vraagstelling": "Lange tekst…",
  "uiterlijkeReactiedatum": "2026-07-09",
  "secureResponseToken": "256-bit hex",
  "attachments": [
    { "fileId": 123, "name": "bouwtekening.pdf", "visibilityExternal": true }
  ]
}
```

### Secure response link

- Token is 256-bit (32 random bytes, hex-encoded) en eenmalig per consultation.
- Token verloopt zodra `consultation.status == afgesloten` of na 90 dagen, wat eerst komt.
- Endpoint `POST /consultation/respond/{token}` accepteert `adviceDocument` (multipart) + `adviceOutcome` (`positief|voorwaarden|negatief`) + `notes` en zet status naar `advies_uitgebracht`.

## Workflow 3 — `consultation-bottleneck-detection`

Cron-job die dagelijks per `advisoryBody` de overdue-rate over de laatste 30 dagen berekent en bij > 20 % een coördinatornotificatie verstuurt.

### Stappen

1. **Schedule trigger** — `0 7 * * *`.
2. **GET** `/index.php/apps/procest/api/consultations/analytics?windowDays=30&groupBy=advisoryBody`.
3. Voor elke `advisoryBody` waar `overdueRate > 0.20`:
    - **POST** `/index.php/apps/procest/api/notifications` met:
        ```json
        {
          "recipientGroup": "case-coordinator",
          "subject": "Knelpunt bij {{advisoryBody.name}}",
          "body": "{{advisoryBody.name}}: {{overdueCount}} verlopen adviezen, gemiddelde doorlooptijd {{avgDays}} dagen.",
          "ref": { "type": "advisoryBody", "id": "{{advisoryBody.id}}" }
        }
        ```
4. Logging: Workflow-run-id wordt opgeslagen in n8n-execution-log voor latere audit. Procest weet welke meldingen al gestuurd zijn via `notification.ref`.

### Analytics endpoint contract

`GET /api/consultations/analytics?windowDays=30&groupBy=advisoryBody`

```json
[
  {
    "advisoryBody": { "id": "uuid", "name": "Welstandscommissie" },
    "totalCount": 12,
    "overdueCount": 4,
    "overdueRate": 0.33,
    "avgResponseDays": 22.5
  }
]
```

## Installatie

1. Importeer de drie JSON-bestanden in n8n (Workflows → Import from File).
2. Maak een credential **HTTP Header Auth** met header `Authorization: Bearer <app-password>` en koppel die aan alle Procest HTTP-nodes.
3. Maak een credential **SMTP** met de uitgaande mailserver van de gemeente (TLS, poort 587).
4. Stel de tijdzone in op `Europe/Amsterdam` (n8n → Settings → Timezone).
5. Activeer de workflows. De cron-jobs draaien vanaf de eerstvolgende geplande tijd.

## Monitoring

- **Executions** — controleer in n8n → Executions of er failed runs zijn. Stuur een Slack-melding bij ≥ 1 failed run per dag.
- **Procest dashboard** — onder **Beheer → Procest → Integraties → n8n** verschijnt de laatste succesvolle uitvoeringstijd van elke workflow.
- **Audit trail** — elke notificatie schrijft een event in `consultation.auditTrail`. Coördinatoren kunnen via de zaakdetail-tab "Audit" zien welke n8n-acties hebben gelopen.

## Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Geen mail verstuurd naar externe instantie | SMTP-credential ontbreekt of relay-IP geblokkeerd | Test SMTP-credential in n8n; voeg n8n-egress-IP toe aan SPF/relay van de gemeente. |
| Dubbele T-5 waarschuwingen | `lastWarningAt` is leeg / niet teruggeschreven | Controleer dat de `notify` POST status 204 teruggeeft; werk het object alleen bij in Procest, niet in n8n. |
| Bottleneck-melding blijft uit | Analytics-endpoint returned `403` | App password van `n8n-procest` user is verlopen — genereer opnieuw en update de credential. |
| Secure response link werkt niet | Token verlopen of consultation afgesloten | Coördinator opent de consultation en kiest **Token regenereren**, daarna stuurt Procest een nieuwe e-mail. |

## Veiligheid

- HMAC-signature voorkomt spoofed webhooks vanaf het n8n-pad.
- Externe responseendpoint logt elke toegang (IP, user-agent, timestamp) in `consultation.auditTrail` per BIO 8.3.1.
- Bijlagen die niet expliciet `visibilityExternal: true` zijn, worden nooit naar externe instanties verstuurd.

## Specs

- `openspec/changes/consultation-management/specs/consultation-management/spec.md`
- `openspec/architecture/adr-000-data-model.md` — entries `consultation`, `adviceResponse`, `advisoryBody` (in te voegen door change `consultation-management`).
