# Design: termijnbewaking-dwangsom-engine

## Architecture

The termijn-engine is a domain service layer on top of procest base, consuming the zaak-engine and event-bus to provide deadline awareness, legal-ground-compliant pause/extension logic, pro-active escalation, and financial-system integration. It does not fork zaak-creation or case-document workflows; instead it:

1. Hooks zaak-creation to auto-spawn TermijnInstance
2. Provides imperative API for pause/extension/ingebrekestelling registration
3. Runs daily cronjob for deadline scanning, escalation alerts, and dwangsom accrual
4. Emits termijn-lifecycle events (naderend-deadline, overschreden, dwangsom-gestart, etc.) to the event-bus for consumer consumption (dashboard, portal, ERP)

```
Procest                          Termijn Engine
├─ zaak-engine                   ├─ TermijnService
│  ├─ zaak-creation trigger ────→├─ (auto-create TermijnInstance)
│  └─ case linking                ├─ PauseService (AWB 4:5/4:15)
├─ event-bus                      ├─ ExtensionService (AWB 4:14)
│  ├─ termijn-naderend-deadline   ├─ IngebrekestellingService
│  ├─ termijn-overschreden        ├─ DwangsomCalculationService
│  ├─ dwangsom-gestart            └─ NotificatieService + ReportingService
│  └─ dwangsom-gestopt
└─ notification-router           Consumers
                                 ├─ procest-dashboard
                                 ├─ nldesign-portal
                                 ├─ openconnector (ERP)
                                 └─ notification-router (e-mail, SMS)
```

## Data Model

### New Entity Schemas (OpenRegister)

#### TermijnDefinitie
**Purpose**: Zaaktype-level termijn configuration with legal grounding, standard duration, and regime variants

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | string | Yes | Reference to caseType (e.g., "Omgevingsvergunning") |
| wettelijkeGrondslag | string | Yes | Legal basis (AWB-artikel or sectorale wet e.g., "AWB 4:13", "Wabo artikel 4") |
| standaardDuurDagen | integer | Yes | Default processing deadline in days (e.g., 56 for 8 weeks) |
| standaardDuurWeken | integer | No | Alternative: duration in weeks |
| verlengingsRuimte | integer | No | Max extension days allowed per AWB 4:14 (e.g., 14, 42, 0=no extension) |
| aantalVerlengingen | integer | No | Max number of extensions allowed (default 1 per AWB 4:14) |
| pauzeeVerlengingsDuren | string | No | JSON: allowed pause/extension durations {hersteltermijn_days, max_pauses} |
| afwijkendDwangsomRegime | string | No | Alternative dwangsom regime if non-standard (e.g., for Woo: €15/day, max €500) |
| validFrom | string | Yes | Effective date (ISO 8601 date) |
| validUntil | string | No | Expiry date; null = indefinite |

#### TermijnInstance
**Purpose**: Per-zaak deadline instance with current status, calculated and actual deadlines, and lifecycle tracking

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaak | string | Yes | Reference to case |
| zaaktype | string | Yes | Cached zaaktype key (for performance) |
| termijnDefinitie | string | Yes | Reference to TermijnDefinitie |
| startDatum | string | Yes | Zaak-creation date (ISO 8601) |
| einddatumBerekend | string | Yes | Calculated deadline (start + standard duration, no pauses) |
| einddatumActueel | string | Yes | Current deadline after pauses and extensions |
| status | string | Yes | Enum: lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken |
| aantalVerlengingen | integer | No | Count of applied extensions (0–max) |
| aantaPauzeerPeriodes | integer | No | Count of pause periods |
| relevantIngbrekes | string | No | Reference to the one Ingebrekestelling that triggers dwangsom (the first valid post-overschrijding) |
| volumetraject | string | No | Enum for monitoring: onwichtigen (normal), belangrijk (elevated monitoring), kritisch (red flag) |
| notificatiesVerstuurd | string | No | JSON array of sent notification timestamps by threshold |
| beschikkingDatum | string | No | When decision was registered (marks completion) |
| description | string | No | Free-text notes (e.g., "Delayed due to incomplete dossier") |

#### TermijnGebeurtenis
**Purpose**: Immutable audit trail of all termijn lifecycle events, essential for legal defensibility and audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| termijnInstance | string | Yes | Reference to parent TermijnInstance |
| type | string | Yes | Enum: start, pauze, hervat, verleng, voltooi, overschreden, ingebrekestelling-ontvangen, dwangsom-gestart |
| tijdstip | string | Yes | Event timestamp (ISO 8601 datetime) |
| actor | string | No | Nextcloud user UID of the person who triggered the event (null for automated events) |
| grondslag | string | No | Legal basis or regulation triggering this event (e.g., "AWB 4:5", "Hersteltermijn-verzoek registratie") |
| motivering | string | No | Free-text explanation (required for verleng, pauze) |
| dagenImpact | integer | No | Days added/subtracted by this event (negative for pauze-end, positive for verleng) |
| documentLink | string | No | Reference to related document (e.g., hersteltermijn-brief, verlengingsbrief) |

#### Ingebrekestelling
**Purpose**: Formal notification record (required by AWB 4:17 before dwangsom can accrue) with validation and legal tracing

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| termijnInstance | string | Yes | Reference to the TermijnInstance (must be overschreden at registration) |
| zaak | string | Yes | Reference to the case |
| ontvangstDatum | string | Yes | Date burger received the notification (ISO 8601) |
| kanaal | string | Yes | Enum: post, email, portaal, persoonlijk |
| documentGescand | string | No | Reference to scanned/sent notification document |
| gevalideerd | boolean | Yes | System validation: true if termijn was actually overschreden on ontvangstDatum |
| geldigheidStatus | string | Yes | Enum: geldig, premaat (termijn niet overschreden), ingetrokken |
| beschikkingGeregistreerdDatum | string | No | Date the decision was registered (terminates dwangsom accrual) |
| notes | string | No | Treatment notes (e.g., "Verzonden per aangetekend post") |

#### DwangsomBerekening
**Purpose**: Stateful daily penalty counter per case, applying the statutory tariff and enforcing plafond

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ingebrekestelling | string | Yes | Reference to parent Ingebrekestelling (establishes start-date logic) |
| zaak | string | Yes | Reference to case |
| startDatum | string | Yes | Notification date + 14 days (grace period) = when daily accrual begins |
| huidigeDag | integer | No | Current day within the dwangsom schedule (for restart scenarios) |
| weekBinnenStaffel | integer | No | Which tariff tier: 1–2 (€23), 3–4 (€35), 5+ (€45) |
| dagtarief | number | No | Current daily rate (€23, €35, or €45) |
| dagLoop | integer | No | Days accrued within this tariff tier |
| cumulatievBedrag | number | No | Total dwangsom accumulated so far |
| plafondBerekend | number | No | Maximum allowed dwangsom (€1.442, or regime-specific) |
| plafondBereikt | boolean | No | True when cumulatievBedrag ≥ plafondBerekend |
| status | string | Yes | Enum: lopend, gestopt-wegens-beschikking, gestopt-wegens-intrekking, gestopt-wegens-bezwaar, betaald |
| beschikkingRegistratieDatum | string | No | When decision was registered (triggers stop) |
| definitievBedrag | number | No | Final dwangsom amount (locked on stop) |
| notes | string | No | Calculation notes |

#### DwangsomUitbetaling
**Purpose**: Financial system interface: triggers payment, tracks confirmation, records audit trail

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| dwangsomBerekening | string | Yes | Reference to parent DwangsomBerekening |
| zaak | string | Yes | Reference to case |
| bedrag | number | Yes | Amount to pay in EUR |
| rekeninghouderNaam | string | Yes | Payee name |
| iban | string | Yes | Bank account (validated IBAN format) |
| referentie | string | Yes | Payment reference (zaak-ID + ingebrekestelling-ID for traceability) |
| wettelijkeGrondslag | string | Yes | Legal basis for payment (e.g., "AWB 4:17 lid 2") |
| betaaldatumUiterlijk | string | Yes | Payment deadline (ingebrekestelling-date + 28 days per AWB) |
| status | string | Yes | Enum: voorbereid, in-betaling, betaald, gefaald |
| betalingsreferentie | string | No | ERP-side reference/confirmation number |
| werkelijkeBetaaldatum | string | No | Date payment cleared |
| notitieVan ERP | string | No | Error message if failed |

### Relations

- case → TermijnInstance (one-to-one, mandatory)
- TermijnInstance → TermijnDefinitie (many-to-one)
- TermijnInstance → TermijnGebeurtenis (one-to-many, immutable)
- TermijnInstance → Ingebrekestelling (one-to-many, but only one is "relevant" for dwangsom)
- Ingebrekestelling → DwangsomBerekening (one-to-one, created on ingebrekestelling registration)
- DwangsomBerekening → DwangsomUitbetaling (one-to-one, created on beschikking)

## Seed Data

### TermijnDefinitie (3 instances for demo)

1. **Omgevingsvergunning-regulier** — Wabo 2010 artikel 4 — 56 days (8 weeks) — Max 1 extension (14 days) — Standard dwangsom regime
2. **Wmo-aanvraag** — Wmo 2015 artikel 2.3.5 — 42 days (6 weeks assessment) — No extension — Standard dwangsom regime
3. **Woo-verzoek** — Wet open overheid artikel 4.4 — 28 days (4 weeks) — Max 1 extension (14 days) — Custom: €15/day, max €500

### TermijnInstance (4 test cases)

1. **Case 2026-001 (Omgevingsvergunning)** — startDatum: 2026-03-15 — status: lopend — einddatumActueel: 2026-05-10 (56 days)
2. **Case 2026-002 (Wmo)** — startDatum: 2026-03-01 — status: gepauzeerd — einddatumActueel: 2026-04-30 (42 days + 7 days pause extended)
3. **Case 2026-003 (Woo)** — startDatum: 2026-02-15 — status: overschreden — einddatumActueel: 2026-03-15 (28 days) — overschreden since: 2026-03-16
4. **Case 2026-004 (Omgevingsvergunning)** — startDatum: 2026-01-30 — status: voltooid — beschikkingDatum: 2026-04-20 — aantalVerlengingen: 1

### TermijnGebeurtenis (audit trail for Case 2026-001)

1. start — 2026-03-15 10:00 — Actor: M. Jansen — Grondslag: AWB 4:13 — Motivering: "Zaak aangemaakt"
2. pauze — 2026-04-01 14:30 — Actor: M. Jansen — Grondslag: AWB 4:5 — Motivering: "Hersteltermijn-verzoek verzonden (14 dagen)"
3. hervat — 2026-04-10 11:00 — Actor: M. Jansen — Grondslag: "Hersteltermijn respons ontvangen" — DagenImpact: +8

### Ingebrekestelling (1 instance for overschreden case 2026-003)

- termijnInstance: Case 2026-003
- ontvangstDatum: 2026-03-20 (5 days after overschrijding)
- kanaal: email
- gevalideerd: true
- geldigheidStatus: geldig
- beschikkingGeregistreerdDatum: null (still pending)

### DwangsomBerekening (for Case 2026-003)

- ingebrekestelling: (above)
- startDatum: 2026-04-03 (ontvangstDatum 2026-03-20 + 14 days grace)
- status: lopend
- huidigeDag: 14 (as of 2026-04-17)
- dagtarief: €35 (tier 3–4)
- cumulatievBedrag: €322 (14 × €23, grace ending; day 15 starts €35/day but only 0 days elapsed) — actually: day 1–14 = 14×€23 = €322
- plafondBereikt: false

### DwangsomUitbetaling (future, on beschikking registration)

- dwangsomBerekening: (above, when beschikking is registered)
- bedrag: €322 (final dwangsom when beschikking registered on day 15)
- rekeninghouderNaam: "Gemeente Amsterdam"
- iban: "NL91ABNA0417164300"
- referentie: "2026-003-20260320" (case-ID-ingebrekestelling-date)
- wettelijkeGrondslag: "AWB 4:17 lid 2"
- betaaldatumUiterlijk: 2026-04-18 (28 days after ingebrekestelling)
- status: voorbereid

## API Design

### Termijn Management Endpoints

**POST /api/procest/termijn/instance** — Create termijn instance (auto-called on zaak-creation; also available for manual creation)
- Payload: {zaakId, zaaktypeKey}
- Response: {termijnInstanceId, einddatumBerekend, einddatumActueel, status}

**GET /api/procest/termijn/instance/{zaakId}** — Retrieve termijn for a case
- Response: full TermijnInstance object with current status and next-action suggestions

**POST /api/procest/termijn/instance/{zaakId}/pauze** — Register pause
- Payload: {grondslag, motivering, duurDagen, documentLink}
- Response: TermijnGebeurtenis event record; einddatumActueel recalculated

**POST /api/procest/termijn/instance/{zaakId}/hervat** — Resume after pause
- Payload: {grondslag} (e.g., "Aanvulling ontvangen")
- Response: TermijnGebeurtenis event; status restored to lopend

**POST /api/procest/termijn/instance/{zaakId}/verleng** — Register extension
- Payload: {motivering, newEinddatum, documentLink}
- Validation: first extension only (unless override-grondslag provided)
- Response: TermijnGebeurtenis event; einddatumActueel updated; if new extension-version of TermijnDefinitie applies, use it

**POST /api/procest/termijn/instance/{zaakId}/voltooi** — Mark termijn complete (on beschikking)
- Payload: {beschikkingDatum}
- Response: status = voltooid; triggers stop of any DwangsomBerekening

### Ingebrekestelling & Dwangsom Endpoints

**POST /api/procest/ingebrekestelling** — Register formal notification
- Payload: {termijnInstanceId, ontvangstDatum, kanaal, documentLink}
- Validation: termijnInstance.status must be overschreden
- Response: Ingebrekestelling record + auto-created DwangsomBerekening starting 14 days post-notification

**GET /api/procest/dwangsom/{zaakId}** — Get current dwangsom state
- Response: {startDatum, huidigeDag, cumulatievBedrag, dagtarief, plafondBereikt, status, projectedFinalBedrag}

**POST /api/procest/dwangsom/{zaakId}/beschikking** — Register beschikking (stops dwangsom accrual)
- Payload: {beschikkingDatum, beschikkingDocument}
- Response: DwangsomBerekening.status = gestopt-wegens-beschikking; DwangsomUitbetaling prepared

**POST /api/procest/dwangsom/{zaakId}/bezwaar** — Register bezwaar against dwangsom
- Payload: {grondslag, motivering}
- Response: DwangsomBerekening paused; uitbetaling status = on-hold-bezwaar

**POST /api/procest/dwangsom/{zaakId}/bezwaar-heroverweging** — Register bezwaar decision
- Payload: {newBedrag, grondslag}
- Response: DwangsomBerekening.definitievBedrag updated; DwangsomUitbetaling resumed

### Reporting & Alerts

**GET /api/procest/termijn/dashboard** — KPI summary for management cockpit
- Response: {totalZaken, binnenTermijn %, gemiddeldeDuurDagen, aantalOverschrijdingen, aantalIngebrekestellingen, totaalDwangsomUitgekeerd}

**GET /api/procest/termijn/kwartaalrapport** — Detailed quarterly report
- Query params: zaaktype, afdeling, periode (yyyy-Qx)
- Response: Formatted report with per-type breakdown and trend analysis

**GET /api/procest/termijn/jaarrekening-dwangsommen** — Annual dwangsom listing (for financial audit)
- Query params: jaar
- Response: CSV/JSON of all dwangsom-betalingen with zaak-ref, bedrag, betaaldatum, payment-ref

### Daily Cronjob: /api/procest/termijn/scan

Runs nightly to:
1. Identify TermijnInstances approaching deadline (14d, 7d, 2d thresholds)
2. Emit pro-active escalation notifications (multi-level: handler → teamlead → manager on final warning)
3. Mark TermijnInstances as overschreden if deadline passed
4. Accrue DwangsomBerekening daily (apply tariff, check plafond)
5. Issue pause-expiry alerts if hersteltermijn deadline passed without response
6. Event-emit (termijn-naderend-deadline, termijn-overschreden, dwangsom-accrued, etc.) to consumers

## Integration Boundaries

- **Procest ↔ OpenRegister** — All termijn entities stored and queried via OpenRegister REST API; no direct DB access
- **Procest ↔ Event-Bus** — Termijn-lifecycle events emitted for consumption by dashboard, portal, ERP; zaak-creation event triggers TermijnInstance creation
- **Procest ↔ OpenConnector** — DwangsomUitbetaling triggers payment event to ERP systems (Coda, Centric, Unit4, AFAS, SAP); ERP callback updates DwangsomUitbetaling.status
- **Procest ↔ Notification-Router** — Sends termijn alerts, ingebrekestelling notifications, and payment confirmations via procest's standard notification system (Nextcloud notificatie, e-mail, portal message)

## Standards Alignment

- **AWB Hoofdstuk 4** — Title 4.1.3 (beslissing termijnen), Title 4.1.3a (dwangsom)
- **AWB 4:5, 4:14, 4:15** — Hersteltermijn, extension, pause grounds
- **AWB 4:13–4:18** — Complete termijn and dwangsom lifecycle
- **Wet dwangsom en beroep** (Stb. 2009, 383) — Statutory tariff (€23/14d, €35/14d, €45/14d, max €1.442)
- **Sectorale regelingen** — Wabo, Omgevingswet, Wmo 2015, Woo, VW 2000, etc. (termijn-definitie per sector)
- **ISO 9001** — Quality management; termijnbewaking as formeel quality process
- **Archiefwet** — Retention rules for TermijnGebeurtenis (append-only audit trail, 5+ year retention per administrative law standards)

