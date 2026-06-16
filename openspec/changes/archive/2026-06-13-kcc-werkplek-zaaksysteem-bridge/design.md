# Design: kcc-werkplek-zaaksysteem-bridge

## Architecture Overview

The KCC-werkplek integration adds a contact layer on top of the existing case management infrastructure. Inbound contacts (telefoon, email, chat, webformulier, social media) flow through `ContactMomentService` which auto-identifies the Burger via DigiD or identificatievragen, opens a case-voorblad (active zaken, contact history, sentiment), and offers quick-actions (status update, new case, klacht, doorverbinding). Each contact is logged as a `Contactmoment` object linked to Burger and gerelateerde zaken. Belplan-routering is data-driven on zaaktype + vaardigheid + real-time `SpecialistBeschikbaarheid`. Warm doorverbinding to specialists includes context-overdraft snapshot. All contacts feed into geconsolideerde klantreis timeline and sentiment analytics.

```
PipelinQ KCC-Werkplek
├── Phone/Chat/Email inbound handler
│   └── POST /api/contactmomenten → ContactMomentService
│       ├── BurgerIdentificationService (DigiD or identificatievragen)
│       │   └── Resolve/create Burger record
│       ├── CaseVoorbledService
│       │   ├── Fetch active zaken (max 10)
│       │   ├── Fetch recente contactmomenten (max 5)
│       │   ├── Fetch openstaande facturen
│       │   └── Suggest dialogue topic
│       └── SentimentService
│           ├── Detect trigger woorden
│           ├── Calculate sentiment score
│           └── Queue escalatie-aanbeveling if needed
│
└── Quick-actions executor
    ├── Status terugkoppelen
    │   └── QuickActionService.statusUpdate(case, template)
    ├── Nieuwe zaak
    │   └── QuickActionService.createCase(zaaktype, burger, contact-context)
    ├── Klacht registreren
    │   └── QuickActionService.createComplaint(case, contactmoment, reden)
    ├── Doorverbinden
    │   └── DoorverbindingService.transfer(specialist/wachtrij, context-snapshot)
    │       └── Return geaccepteerd flag + specialist details
    └── Andere quick-actions
        └── (bel-terug inplannen, email sturen, kopie document sturen)

Specialist Wachtrij (real-time polling)
└── SpecialistBeschikbaarheid polling
    ├── Fetch beschikbaarheid per vaardigheid
    ├── Calculate wachtrij lengte
    └── Match best beschikbare specialist
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/ContactMomentService.php` | Log contactmoment, auto-identify burger, fetch case-voorblad, sentiment detection, doorverbinding orchestration |
| `lib/Service/BurgerIdentificationService.php` | DigiD auth via openconnector, identificatievragen flow with threshold scoring, BRP person lookup, Burger record CRUD |
| `lib/Service/BelplanRoutingService.php` | Data-driven routing: zaaktype → vaardigheid → specialist pool matching; overflow logic to generalist |
| `lib/Service/QuickActionService.php` | Status terugkoppelen (template rendering), nieuwe zaak creation, klacht registration, bel-terug scheduling, email sending, document copying |
| `lib/Service/DoorverbindingService.php` | Warm transfer context snapshot, specialist acceptance/rejection, context-trail preservation |
| `lib/Service/SentimentService.php` | Trigger-word detection (Dutch wordlist), sentiment scoring (-1 to +1), escalatie-aanbeveling generation |
| `lib/Service/CaseVoorbledService.php` | Fetch active zaken by Burger, recente contactmomenten, openstaande facturen via leges-heffingen, dialogue topic suggestion |
| `lib/Controller/ContactMomentController.php` | Authenticated API: create contactmoment, list by Burger, get case-voorblad, quick-action routes (status/zaak/klacht/doorverbinding) |
| `lib/Controller/BelplanController.php` | Belplan CRUD, routing-rules editor, specialist beschikbaarheid polling endpoint |
| `lib/Controller/SpecialistBeschikbaarheidController.php` | Real-time poll endpoint for pipelinq, wachtrij-status updates |
| `lib/BackgroundJob/ContactSentimentAnalysisJob.php` | Batch sentiment re-scoring if transcription is added post-contact; escalatie flag updates |
| `lib/BackgroundJob/SpecialistBeschikbaarheidCacheJob.php` | Periodic refresh of specialist beschikbaarheid cache (default 30s) from pipelinq or HR system |

### New Frontend Files

| File | Purpose |
|------|---------|
| N/A (pipelinq handles KCC-werkplek UI) | Procest exposes read-only API; pipelinq manages UI rendering, quick-action dialogs, sentiment display, specialist picker |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `contactmoment`, `burger`, `kccQuickAction`, `belplan`, `specialistBeschikbaarheid`, `doorverbinding`, `klantSentiment` schemas |
| `lib/Service/SettingsService.php` | Add config keys: `identification_method` (digid, bsn_questions, both), `identification_score_threshold` (0.6–1.0), `sentiment_polling_interval`, `specialist_availability_polling_interval`, `max_zaken_voorblad`, `max_contactmomenten_history`, `quick_action_templates` (JSON), `belplan_overflow_threshold` |
| `appinfo/routes.php` | Add /api/contactmomenten (POST create, GET list by burger), /api/cases/{caseId}/voorblad (GET case-voorblad), /api/quick-actions/{actionType} (POST), /api/doorverbindingen (POST, GET), /api/specialist-beschikbaarheid (GET), /api/belplannen (CRUD) |
| `lib/Listener/ActivitiesEventSubscriber.php` | Subscribe to `ContactMomentCreatedEvent` and log as case activity (`contact_inbound`, `contact_outbound` event types) |
| `lib/Listener/SentimentEventSubscriber.php` | Subscribe to `SentimentDetectedEvent` and append to case `activity` array with sentiment flag for dashboard alerting |

## Data Model

### contactmoment Schema
- `kanaal` (enum: telefoon, email, webformulier, chat, social_media, balie; required)
- `richting` (enum: inkomend, uitgaand; required)
- `startTijd` (datetime; required)
- `eindTijd` (datetime; nullable)
- `duurSeconden` (integer; calculated from start/end)
- `bellerIdentificatie` (string; phone/email/social handle)
- `geidentificeerdeBurgerId` (string/FK to burger; nullable if unidentified)
- `identificatieMethode` (enum: digid, bsn_verificatie, identificatievragen, niet_geidentificeerd; required)
- `identificatieScore` (0.0–1.0; nullable)
- `kccMedewerkerId` (string/Nextcloud UID; required)
- `gerelateerdeZaken` (array of case IDs; JSON)
- `nieuweZaakIds` (array of newly created case IDs; JSON)
- `aard` (enum: informatieverzoek, statusverzoek, klacht, melding, nieuwe_aanvraag, doorverbinding; required)
- `samenvatting` (text; required — medewerker or system summary of contact)
- `volgensIntent` (string; e.g., "Statusvraag omgevingsvergunning Z2026-00547")
- `firstTimeFix` (boolean; whether issue was resolved in one contact)
- `transcriptie` (text; optional, from voice-to-text)
- `transferNaar` (string/FK to specialist user ID or wachtrij name; nullable if no transfer)

**Relations:**
- → burger (many-to-one)
- → case (many-to-many via gerelateerdeZaken)

### burger Schema
- `bsn` (string/encrypted; nullable if unverified)
- `kvkNummer` (string; nullable for individuals)
- `naam` (string; required)
- `adres` (string; required)
- `telefoonnummers` (array of phone numbers; JSON)
- `emails` (array of email addresses; JSON)
- `bekendeIdentificaties` (map of phone/email → bsn; JSON — for phone caller lookup)
- `voorkeursKanaal` (enum: telefoon, email, chat, etc.; nullable)
- `voorkeursTaal` (enum: nl, en; default: nl)
- `contact_count` (integer; auto-incrementing on each contactmoment)
- `last_contact_date` (datetime; auto-updated)
- `first_contact_date` (datetime; auto-set on creation)

**Relations:**
- ← contactmoment (one-to-many)
- ← case (many-to-many via initiator/betrokken party)

### kccQuickAction Schema
- `naam` (string; required — e.g., "Status zaak doorgeven")
- `actieType` (enum: status_geven, nieuwe_zaak, klacht_registreren, doorverbinden, bel_terug_inplannen, email_sturen, kopie_document_sturen; required)
- `vereisteContext` (array: conditions like "has_open_case", "is_geidentificeerd"; JSON)
- `targetZaaktype` (string/FK; nullable if multi-zaaktype)
- `template` (string/FK to emailTemplate; nullable for non-email actions)
- `permissies` (array of allowed KCC rollen; JSON)
- `volgorde` (integer; display order in UI)
- `isActive` (boolean; default true)

### belplan Schema
- `naam` (string; required — e.g., "Algemeen gemeentenummer")
- `triggerNummer` (string/array; phone number(s) this plan applies to; JSON)
- `routeringStappen` (array of routing steps; JSON array with objects: `{type: "keuzemenu|vaardigheid_match|wachtrij_overflow", config: {...}}`)
  - Example: `[{type: "keuzemenu", options: ["Omgevingsvergunningen", "Bouwtoezicht", "Infocentrum"]}, {type: "vaardigheid_match", zaaktype_to_vaardigheid: {...}}, {type: "wachtrij_overflow", threshold_wachttijd_sec: 180, fallback_rol: "generalist"}]`
- `openingstijden` (string; e.g., "Mo-Fr 08:00-17:00"; nullable for 24/7)
- `terugvalActie` (enum: voicemail, sms_callback, email_callback; default: voicemail)
- `prioriteit` (integer; higher = higher priority in matching)
- `isActive` (boolean; default true)

### specialistBeschikbaarheid Schema
- `medewerkerId` (string/Nextcloud UID; required)
- `expertises` (array of zaaktype codes; JSON)
- `status` (enum: beschikbaar, in_gesprek, wrap_up, afwezig, niet_storen; required)
- `huidigeWachtrijLengte` (integer; number of calls in queue)
- `gemiddeldeBehandelduur` (integer; seconds; calculated rolling average)
- `gespreksInProgress` (integer; how many active calls)
- `laatsteUpdate` (datetime; timestamp of last status change)
- `poll_interval` (integer; seconds; default 30)

**Relations:**
- → role (many-to-one; specialist's Nextcloud user role)

### doorverbinding Schema
- `contactmomentId` (string/FK; required)
- `vanMedewerkerId` (string/Nextcloud UID; required)
- `naarMedewerkerId` (string/Nextcloud UID; nullable if naar wachtrij)
- `naarWachtrij` (string; nullable if naar medewerker)
- `doorverbindingsReden` (text; e.g., "Specialist nodig voor complexe omgevingsvergunning")
- `contextOverdracht` (text; snapshot of contact summary + zaak context)
- `contextSnapshot` (JSON; immutable snapshot: bellergegevens, gerelateerdeZaken, sentiment, contactmomentHistory)
- `geaccepteerd` (boolean; nullable until answered)
- `acceptatieTijd` (datetime; when specialist answered)
- `afgekeurdReden` (text; nullable if declined)
- `warmTransferStarted` (datetime; when pipelinq initiated transfer)

**Relations:**
- → contactmoment (many-to-one)
- → medewerker (many-to-one: vanMedewerker + naarMedewerker)

### klantSentiment Schema
- `contactmomentId` (string/FK; required)
- `sentimentScore` (-1.0 to +1.0; -1=very negative, 0=neutral, +1=very positive)
- `sentimentLabel` (enum: positief, neutraal, negatief, boos; required)
- `triggerWoorden` (array of detected trigger words; JSON)
- `transcriptieSnippet` (text; quote from transcription with trigger words highlighted)
- `escalatieAanbevolen` (boolean; true if sentiment <= -0.5 or trigger like "klacht", "advocaat", "media")
- `escalatieLevel` (enum: geen, geel, oranje, rood; recommended escalation level)
- `createdAt` (datetime; auto-set)

**Relations:**
- → contactmoment (one-to-one)

## API Design

### Authenticated Endpoints (ContactMomentController + BelplanController)

- `POST /api/contactmomenten` — Create contactmoment from KCC-werkplek with kanaal, bellerIdentificatie, aard, kccMedewerkerId; returns created Contactmoment + case-voorblad JSON
- `GET /api/contactmomenten?burgerId=X` — List contactmomenten for a burger (pagination, most recent first)
- `GET /api/cases/{caseId}/voorblad` — Fetch case-voorblad: NAW, open zaken (max 10), recente contactmomenten (max 5), openstaande facturen, suggested topic
- `POST /api/quick-actions/status-geven` — Execute "Status terugkoppelen" with case ID + sentiment confirmation
- `POST /api/quick-actions/nieuwe-zaak` — Execute "Nieuwe zaak" with zaaktype, burger ID, contact-context; returns new case ID
- `POST /api/quick-actions/klacht-registreren` — Execute "Klacht registreren" with case ID, klacht-inhoud; returns klacht case ID + klachtenfunctionaris notification
- `POST /api/quick-actions/doorverbinden` — Execute "Doorverbinden" with specialist ID or wachtrij name; returns transfer-initiated + context-snapshot; later polls for acceptance
- `POST /api/quick-actions/bel-terug-inplannen` — Schedule callback with burger + preferred time window
- `POST /api/doorverbindingen/{id}/accept` — Specialist accepts transfer (internal, from pipelinq)
- `POST /api/doorverbindingen/{id}/reject` — Specialist rejects transfer (internal, from pipelinq)
- `GET /api/specialist-beschikbaarheid?zaaktype=X` — Real-time poll of specialists with vaardigheid for zaaktype; returns array with status, wachtrij_lengte, gemiddelde_behandeltijd
- `GET /api/belplannen` — List all belplannen
- `POST/PUT /api/belplannen` — Create/edit belplan (admin)
- `POST /api/burger-identify/digid` — DigiD callback with auth code; returns burger ID or redirect to identificatievragen if unmatched

### Sentiment Analytics Endpoints (Dashboard)

- `GET /api/sentiments?date_range=week&metric=avg_score` — Average sentiment score for period
- `GET /api/sentiments?escalation_flag=true&limit=10` — Top 10 recent escalation-flagged contactmomenten
- `GET /api/sentiments/trigger-words?date_range=month` — Most common trigger words this month (for coaching)

## Security & Reliability

- **DigiD credentials**: obtained via openconnector; flow is secure OAuth2 + state validation
- **Identificatievragen**: SSN verification against BRP; threshold 0.8+ to link to Burger; below threshold shows openbare zaaksinfo only
- **Burger PII encryption**: BSN encrypted at-rest via Nextcloud `encryption_app`; stored in `config.php` with app-level key
- **Case-voorblad authorization**: Only return zaken if contactmoment Burger is case initiator/betrokken party; no cross-tenant leakage
- **Quick-action permissions**: Check `mandaat-matrix` before executing klacht/restitutie/status-change actions; enforce KCC-rol restrictions on `kccQuickAction.permissies`
- **Doorverbinding context snapshot**: Immutable; captures Burger, case references, sentiment at transfer-time; prevents later context-drift
- **Sentiment detection safety**: Trigger-word matching on simple substring; no ML models; manually curated Dutch wordlist with context awareness (e.g., "ongelooflijk mooi" vs "ongelooflijk slecht")
- **Performance SLA**: Case-voorblad fetch under 2s at 100 concurrent KCC-medewerkers; achieved via indexed queries on (burgerId, status), caching of open zaak lists, or background pre-computation
- **Background job resilience**: Contact sentiment analysis job catches exceptions; failures logged to syslog but don't deregister job (restart on next interval)
- **Audit trail**: Every contactmoment, quick-action, doorverbinding recorded in case `activity` array with timestamp + medewerker ID + result
