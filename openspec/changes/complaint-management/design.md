# Design: complaint-management

## Architecture

Complaints are first-class entities stored in OpenRegister, distinct from `zaak`. The complaint lifecycle is enforced by a status machine layered on top of the existing status-record infrastructure; only the deadline math and a few specialized flows (hearing, disposition, escalation) require new code. n8n drives email intake, deadline monitoring, and notification fan-out.

```
                ┌─────────────────────────────────────────────────┐
                │ procest case-management (existing)              │
                │ case, caseType, statusType, role, decision,     │
                │ document, ActivityTimeline, DeadlinePanel        │
                └────────────────┬────────────────────────────────┘
                                 │ complaint module consumes
                ┌────────────────┼────────────────┐
                ▼                ▼                ▼
        ┌───────────────┐ ┌───────────────┐ ┌───────────────┐
        │  complaint    │ │    hearing    │ │  complaint-   │
        │  (core entity)│ │  (hoorgesprek)│ │  Disposition  │
        │  + deadlines  │ │  + calendar   │ │  (oordeel)    │
        └───────┬───────┘ └───────┬───────┘ └───────┬───────┘
                │                 │                 │
                ▼                 ▼                 ▼
        ┌───────────────┐ ┌───────────────┐ ┌───────────────┐
        │ complaintCat. │ │ Calendar/Talk │ │   Docudesk    │
        │ (per-tenant)  │ │  integrations │ │ letter render │
        └───────────────┘ └───────────────┘ └───────────────┘
                                 │
                ┌────────────────┴────────────────┐
                ▼                                 ▼
        ┌───────────────┐                ┌───────────────┐
        │ n8n workflows │                │  Analytics    │
        │ email-intake  │                │  dashboard    │
        │ deadline-mon. │                │  (frequency + │
        │ attach-match  │                │   KPI cards)  │
        └───────────────┘                └───────────────┘
```

## Data Model

Four new OpenRegister schemas in `lib/Settings/procest_register.json`. All schemas carry Schema.org annotations. OpenRegister built-in fields (`id`, `uuid`, `createdAt`, `updatedAt`, `auditTrail`, etc.) are NOT redefined.

### complaint
**Schema.org type:** `schema:Message`
**Purpose:** Core complaint entity with Awb-mandated lifecycle and deadline fields.

| Property | Type | Required | Description |
|---|---|---|---|
| klachtnummer | string | Yes | Auto-generated identifier (`KL-{year}-{sequence}`, e.g. `KL-2026-0042`) |
| klager | string | Yes | JSON object: name, email, phone, BSN (optional) |
| onderwerp | string | Yes | Short title of the complaint |
| omschrijving | string | Yes | Detailed description of the complaint |
| ontvangstdatum | string | Yes | Date the complaint was received (ISO 8601) |
| ontvangstkanaal | string | Yes | Intake channel enum: `balie`, `telefoon`, `email`, `brief`, `website`, `socialmedia` |
| categorie | string | No | Reference to a `complaintCategory` object UUID |
| betrokkenMedewerker | string | No | Nextcloud user UID of the employee the complaint is about (access-gated to `klachten-coordinator`) |
| betrokkenAfdeling | string | No | Department name or code the complaint concerns |
| behandelaar | string | No | Nextcloud user UID of the assigned complaint handler |
| prioriteit | string | No | Priority enum: `laag`, `normaal`, `hoog`, `urgent` |
| ontvangstbevestigingDeadline | string | No | Deadline for sending acknowledgment (5 working days from `ontvangstdatum`) |
| afhandelDeadline | string | No | Deadline for resolving the complaint (6 weeks from `ontvangstdatum`) |
| verdagingMogelijk | boolean | No | Whether a 4-week extension is still available (Awb art. 9:11 lid 2) |
| geescaleerdeZaak | string | No | Reference to the `case` UUID if complaint was escalated |

**Relations:**
- → complaintCategory (many-to-one via `categorie`)
- → case (many-to-one via `geescaleerdeZaak`)
- → hearing (one-to-many, reverse)
- → complaintDisposition (one-to-one, reverse)

---

### hearing
**Schema.org type:** `schema:Event`
**Purpose:** Hoorgesprek (hearing) linked to a complaint per Awb art. 9:10.

| Property | Type | Required | Description |
|---|---|---|---|
| complaint | string | Yes | Reference to the parent complaint UUID |
| datum | string | Yes | Scheduled date and time (ISO 8601) |
| locatie | string | No | Physical address or `Online` for video hearings |
| type | string | Yes | Hearing type enum: `fysiek`, `telefonisch`, `videogesprek` |
| deelnemers | string | No | JSON array of planned participants (name, role, email) |
| talkRoomUrl | string | No | Nextcloud Talk conversation URL (set for `videogesprek` type) |
| verslag | string | No | Summary of what was discussed (mandatory after hearing) |
| conclusie | string | No | Preliminary conclusion from the hearing |
| aanwezigen | string | No | JSON array of actual attendees (may differ from `deelnemers`) |
| datumAfgerond | string | No | Actual date the hearing was completed |

**Relations:**
- → complaint (many-to-one)

---

### complaintDisposition
**Schema.org type:** `schema:AssessAction`
**Purpose:** Formal disposition (oordeel) on a complaint per Awb art. 9:12.

| Property | Type | Required | Description |
|---|---|---|---|
| complaint | string | Yes | Reference to the parent complaint UUID |
| oordeel | string | Yes | Disposition enum: `gegrond`, `deels_gegrond`, `ongegrond`, `ingetrokken`, `niet_ontvankelijk` |
| toelichting | string | No | Explanation of the judgment (mandatory for `gegrond` and `deels_gegrond`) |
| maatregelen | string | No | JSON array of corrective actions: `{omschrijving, verantwoordelijke, deadline}` |
| afsluitdatum | string | No | Date the complaint was formally closed |
| afsluitbrief | string | No | Reference to the Docudesk-generated response letter document UUID |
| goedgekeurdDoor | string | No | Nextcloud user UID of the coordinator who approved (when approval gate is enabled) |

**Relations:**
- → complaint (one-to-one)

---

### complaintCategory
**Schema.org type:** `schema:DefinedTerm`
**Purpose:** Configurable complaint category with default-handler routing and SLA override per tenant.

| Property | Type | Required | Description |
|---|---|---|---|
| name | string | Yes | Category name (e.g. `Dienstverlening`, `Bejegening`) |
| description | string | No | Explanation of when to use this category |
| defaultHandler | string | No | Nextcloud user UID or group ID for automatic routing |
| slaOverride | integer | No | Custom resolution deadline in working days (overrides Awb default of 30) |
| actief | boolean | No | Whether this category is available for new complaints (default: true) |

---

## Complaint Lifecycle (Status Machine)

| Status | Description | Awb reference |
|---|---|---|
| `ontvangen` | Complaint received, not yet acknowledged | Art. 9:2 |
| `ontvangst_bevestigd` | Acknowledgment sent within 5 working days | Art. 9:6 |
| `in_behandeling` | Investigation underway | Art. 9:7 |
| `hoorgesprek_gepland` | Hearing scheduled | Art. 9:10 |
| `hoorgesprek_afgerond` | Hearing completed, awaiting disposition | Art. 9:10 |
| `wacht_op_goedkeuring` | Disposition submitted, awaiting coordinator approval | Tenant config |
| `afgehandeld` | Formally closed with written disposition | Art. 9:12 |
| `ingetrokken` | Complainant withdrew the complaint | Art. 9:3 |

Valid transitions:
- `ontvangen` → `ontvangst_bevestigd`
- `ontvangst_bevestigd` → `in_behandeling`
- `in_behandeling` → `hoorgesprek_gepland` (or skip hearing if waived)
- `in_behandeling` → `wacht_op_goedkeuring` (if approval gate enabled)
- `in_behandeling` → `afgehandeld` (if hearing waived + no approval gate)
- `hoorgesprek_gepland` → `hoorgesprek_afgerond`
- `hoorgesprek_afgerond` → `wacht_op_goedkeuring` / `afgehandeld`
- `wacht_op_goedkeuring` → `afgehandeld` (approved) or back to `hoorgesprek_afgerond` (rejected)
- Any non-final → `ingetrokken`

## Backend Services

- **`ComplaintService`** — CRUD, status-machine transitions, Awb working-day deadline calculation via `WorkingDayCalculator` helper, verdaging logic (one extension, 4 weeks), escalation linker to `case`.
- **`WorkingDayCalculator`** — centralized working-day math with Dutch public holiday lookup (Pasen, Koningsdag, Bevrijdingsdag, Hemelvaartsdag, Pinksterdag, Kerstmis, Nieuwjaarsdag). Covered by unit tests for boundary dates.
- **`HearingService`** — hearing CRUD, Calendar invitation via `OCP\Calendar\IManager`, Talk room creation via `OCP\Talk\IBroker` for `videogesprek` hearings.
- **`DispositionService`** — disposition CRUD, optional coordinator approval gate (configurable per tenant), Docudesk template render for the response letter (`afsluitbrief`).
- **`ComplaintAnalyticsService`** — frequency aggregation by category/department/channel, anonymized employee-threshold alerts (≥3 complaints naming the same `betrokkenMedewerker` within 6 months, notifies HR coordinator), systemic-issue detection (>50% QoQ increase triggers "Systeemmelding").
- **`ComplaintController`** — REST routes per ADR-002 under `/index.php/apps/procest/api/complaints` and `/index.php/apps/procest/api/complaints/{id}/...`.

### Settings Keys (added to `SettingsService::SLUG_TO_CONFIG_KEY`)

| Slug | Config key |
|---|---|
| `complaint_register` | `procest.complaint_register` |
| `complaint_schema` | `procest.complaint_schema` |
| `hearing_schema` | `procest.hearing_schema` |
| `disposition_schema` | `procest.disposition_schema` |
| `complaint_category_schema` | `procest.complaint_category_schema` |

## Vue Components

1. **`ComplaintList.vue`** — Handler inbox using `CnIndexPage` + `useListView`. Filters: status, category, handler, date range, priority. Overdue items pinned to top with red `CnStatusBadge`. Columns: klachtnummer, onderwerp, categorie, status, ontvangstdatum, deadline, days-remaining.

2. **`ComplaintDetail.vue`** — Detail view with `CnDetailPage`. Header: `klachtnummer` + `CnStatusBadge`. Tabs:
   - **Klacht** — `CnDetailCard` with complaint fields.
   - **Deadlines** — reuses `DeadlinePanel.vue` for Awb deadline visualization.
   - **Hoorgesprek** — hearing record via `CnDetailCard`, schedule/record actions.
   - **Afsluiting** — disposition form (`CnFormDialog`) and letter generation.
   - **Escalatie** — escalation action + linked case via `CnDetailCard`.
   - **Communicatie** — communication trail, `ActivityTimeline.vue`.
   - **Bijlagen / Audit** — `CnObjectSidebar`.

3. **`ComplaintDashboardWidget.vue`** — "Mijn klachten" dashboard widget via `CnWidgetWrapper`. Shows: open count, overdue count, next 5 working-day deadlines. Click navigates to filtered complaint list.

4. **`ComplaintAnalyticsDashboard.vue`** — Manager view via `CnDashboardPage`. KPI cards (`CnStatsBlock`): total complaints, average resolution time, Awb compliance rate, gegrond rate. Bar charts (`CnChartWidget`): complaints by category, by department, by channel. Trend line chart (monthly). Disposition pie chart. Employee-threshold and systemic-issue alert banners.

## n8n Workflows

| Workflow | Trigger | Action |
|---|---|---|
| **email-intake** | New message on klachten@gemeente.nl | Creates draft `complaint` object via ComplaintController, attaches email body as `omschrijving`, attachments as files, sender as `klager.email` |
| **deadline-monitor** | Daily scheduled job | Sends handler warning at T-3 working days (acknowledgment) and T-7 calendar days (resolution); escalates overdue complaints to `klachten-coordinator` via Nextcloud notification |
| **attachment-matcher** | New message on klachten@gemeente.nl with known klachtnummer in subject | Fetches existing complaint by `klachtnummer`, attaches files, notifies `behandelaar` |

## Reuse Analysis

Per ADR-012, the following OpenRegister platform services are leveraged rather than reimplemented:

| Needed capability | Platform service used | Notes |
|---|---|---|
| Complaint CRUD REST API | `ObjectService` via OpenRegister generic API | No custom ComplaintMapper |
| Complaint list + pagination | `CnIndexPage` + `useListView` + `CnDataTable` | No custom list controller |
| Complaint form (create/edit) | `CnFormDialog` (schema-driven) | No custom form component |
| Deadline visualization | `DeadlinePanel.vue` (existing procest component) | Reused directly |
| Activity timeline | `ActivityTimeline.vue` (existing procest component) | Reused directly |
| File attachments | `FileService` + `CnObjectSidebar` → `CnFilesTab` | No custom file upload |
| Audit trail | `AuditTrailService` (automatic) + `CnObjectSidebar` → `CnAuditTrailTab` | No custom audit logging |
| Calendar invitations | `OCP\Calendar\IManager` (Nextcloud core) | `HearingService` wraps; no custom calendar logic |
| Talk room creation | `OCP\Talk\IBroker` (Nextcloud core) | `HearingService` wraps; no custom Talk service |
| Letter generation | Docudesk integration (existing procest pattern) | `DispositionService` follows same pattern as existing letter generation |
| Dashboard KPI cards | `CnStatsBlock` + `CnDashboardPage` | No custom chart components |
| Escalation link | OpenRegister relations (`geescaleerdeZaak` field + OR relation mechanism) | No custom relation table |
| i18n | `t(appName, 'text')` + `l10n/nl.json` per ADR-007 | Standard NC translation flow |

No overlap found with `ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`, or existing shared Vue components beyond the intentional reuse listed above.

## Seed Data

Per ADR-001, the following seed objects MUST be included in `lib/Settings/procest_register.json` under `components.objects[]` using the `@self` envelope. All seed data uses realistic Dutch values.

### complaintCategory (5 objects)

```json
{ "@self": { "register": "procest", "schema": "complaintCategory", "slug": "cat-dienstverlening" },
  "name": "Dienstverlening", "description": "Klachten over de kwaliteit van dienstverlening bij loketten en balies", "defaultHandler": "klachten@gemeente.nl", "slaOverride": null, "actief": true }

{ "@self": { "register": "procest", "schema": "complaintCategory", "slug": "cat-bejegening" },
  "name": "Bejegening", "description": "Klachten over de bejegening door medewerkers (toon, houding, gedrag)", "defaultHandler": "hr-klachten", "slaOverride": 20, "actief": true }

{ "@self": { "register": "procest", "schema": "complaintCategory", "slug": "cat-wachttijd" },
  "name": "Wachttijd", "description": "Klachten over te lange wachttijden bij balie, telefoon of digitale aanvragen", "defaultHandler": "kcc-klachten", "slaOverride": null, "actief": true }

{ "@self": { "register": "procest", "schema": "complaintCategory", "slug": "cat-informatievoorziening" },
  "name": "Informatievoorziening", "description": "Klachten over onjuiste, onduidelijke of ontbrekende informatie", "defaultHandler": "kcc-klachten", "slaOverride": null, "actief": true }

{ "@self": { "register": "procest", "schema": "complaintCategory", "slug": "cat-procedures" },
  "name": "Procedures", "description": "Klachten over gevolgde procedures en besluitvormingsprocessen", "defaultHandler": "kwaliteit-klachten", "slaOverride": null, "actief": true }
```

### complaint (4 objects)

```json
{ "@self": { "register": "procest", "schema": "complaint", "slug": "klacht-2026-0001" },
  "klachtnummer": "KL-2026-0001", "klager": "{\"naam\": \"J. de Vries\", \"email\": \"j.devries@example.nl\", \"telefoon\": \"0612345678\"}", "onderwerp": "Lange wachttijd bij rijbewijsaanvraag", "omschrijving": "Ik heb meer dan 2 uur moeten wachten bij de balie voor het afhalen van mijn rijbewijs, terwijl ik een afspraak had om 10:00 uur.", "ontvangstdatum": "2026-04-14", "ontvangstkanaal": "balie", "betrokkenAfdeling": "Burgerzaken", "behandelaar": "m.janssen", "prioriteit": "normaal", "ontvangstbevestigingDeadline": "2026-04-21", "afhandelDeadline": "2026-05-26", "verdagingMogelijk": true }

{ "@self": { "register": "procest", "schema": "complaint", "slug": "klacht-2026-0002" },
  "klachtnummer": "KL-2026-0002", "klager": "{\"naam\": \"P. Bakker\", \"email\": \"p.bakker@example.nl\", \"telefoon\": \"0687654321\"}", "onderwerp": "Onbeleefde behandeling bij paspoortaanvraag", "omschrijving": "De medewerker aan de balie was zeer kortaf en onbehulpzaam bij mijn paspoortaanvraag. Ondanks mijn vragen over de procedure werd ik afgekapt.", "ontvangstdatum": "2026-04-15", "ontvangstkanaal": "email", "betrokkenAfdeling": "Burgerzaken", "behandelaar": "s.peters", "prioriteit": "hoog", "ontvangstbevestigingDeadline": "2026-04-22", "afhandelDeadline": "2026-05-27", "verdagingMogelijk": true }

{ "@self": { "register": "procest", "schema": "complaint", "slug": "klacht-2026-0003" },
  "klachtnummer": "KL-2026-0003", "klager": "{\"naam\": \"A. van den Berg\", \"email\": \"a.vandenberg@example.nl\", \"telefoon\": \"0623456789\"}", "onderwerp": "Onjuiste informatie over subsidieaanvraag", "omschrijving": "Op de website stond dat ik in aanmerking kom voor de duurzaamheidssubsidie, maar bij de aanvraag bleek ik te vallen buiten de doelgroep. De informatieverstrekking is misleidend.", "ontvangstdatum": "2026-04-16", "ontvangstkanaal": "website", "betrokkenAfdeling": "Economische Zaken", "behandelaar": "m.janssen", "prioriteit": "normaal", "ontvangstbevestigingDeadline": "2026-04-23", "afhandelDeadline": "2026-05-28", "verdagingMogelijk": true }

{ "@self": { "register": "procest", "schema": "complaint", "slug": "klacht-2026-0004" },
  "klachtnummer": "KL-2026-0004", "klager": "{\"naam\": \"H. Visser\", \"email\": \"h.visser@example.nl\", \"telefoon\": \"0698765432\"}", "onderwerp": "Vertraging afhandeling omgevingsvergunning", "omschrijving": "Mijn aanvraag voor een omgevingsvergunning voor de uitbouw staat al 14 weken open zonder enige communicatie. De wettelijke termijn is al verstreken.", "ontvangstdatum": "2026-04-10", "ontvangstkanaal": "brief", "betrokkenAfdeling": "Ruimtelijke Ordening", "behandelaar": "s.peters", "prioriteit": "urgent", "ontvangstbevestigingDeadline": "2026-04-17", "afhandelDeadline": "2026-05-22", "verdagingMogelijk": false }
```

### hearing (3 objects)

```json
{ "@self": { "register": "procest", "schema": "hearing", "slug": "hoorgesprek-2026-0001" },
  "datum": "2026-05-05T10:00:00", "locatie": "Stadhuis Rotterdam, Coolsingel 40, kamer 3.12", "type": "fysiek", "deelnemers": "[{\"naam\": \"J. de Vries\", \"rol\": \"klager\", \"email\": \"j.devries@example.nl\"}, {\"naam\": \"M. Janssen\", \"rol\": \"behandelaar\", \"email\": \"m.janssen@gemeente.nl\"}]", "talkRoomUrl": null, "verslag": null, "conclusie": null, "aanwezigen": null, "datumAfgerond": null }

{ "@self": { "register": "procest", "schema": "hearing", "slug": "hoorgesprek-2026-0002" },
  "datum": "2026-05-08T14:00:00", "locatie": "Online", "type": "videogesprek", "deelnemers": "[{\"naam\": \"P. Bakker\", \"rol\": \"klager\", \"email\": \"p.bakker@example.nl\"}, {\"naam\": \"S. Peters\", \"rol\": \"behandelaar\", \"email\": \"s.peters@gemeente.nl\"}, {\"naam\": \"HR Klachten\", \"rol\": \"waarnemer\", \"email\": \"hr-klachten@gemeente.nl\"}]", "talkRoomUrl": "https://nextcloud.gemeente.nl/call/abc123def", "verslag": null, "conclusie": null, "aanwezigen": null, "datumAfgerond": null }

{ "@self": { "register": "procest", "schema": "hearing", "slug": "hoorgesprek-2026-0003-afgerond" },
  "datum": "2026-04-28T11:00:00", "locatie": "Telefonisch", "type": "telefonisch", "deelnemers": "[{\"naam\": \"A. van den Berg\", \"rol\": \"klager\", \"email\": \"a.vandenberg@example.nl\"}, {\"naam\": \"M. Janssen\", \"rol\": \"behandelaar\", \"email\": \"m.janssen@gemeente.nl\"}]", "talkRoomUrl": null, "verslag": "Mevrouw Van den Berg gaf aan dat zij de website-informatie op 12 april heeft gelezen en dat die duidelijk aangaf dat zij binnen de doelgroep viel. Na doorvragen bleek dat de pagina inmiddels geactualiseerd was maar de oude versie gecached was.", "conclusie": "De informatie op de website was ten tijde van de aanvraag inderdaad onjuist. Er is sprake van een gegronde klacht.", "aanwezigen": "[{\"naam\": \"A. van den Berg\", \"rol\": \"klager\"}, {\"naam\": \"M. Janssen\", \"rol\": \"behandelaar\"}]", "datumAfgerond": "2026-04-28" }
```

### complaintDisposition (2 objects)

```json
{ "@self": { "register": "procest", "schema": "complaintDisposition", "slug": "oordeel-2026-0003" },
  "oordeel": "gegrond", "toelichting": "De gemeente heeft onjuiste informatie op de website gepubliceerd. Mevrouw Van den Berg heeft hierop mogen vertrouwen. De klacht is gegrond.", "maatregelen": "[{\"omschrijving\": \"Website-informatie subsidies gecontroleerd en gecorrigeerd\", \"verantwoordelijke\": \"Communicatieteam\", \"deadline\": \"2026-05-15\"}, {\"omschrijving\": \"Schriftelijke excuses aangeboden\", \"verantwoordelijke\": \"s.peters\", \"deadline\": \"2026-05-02\"}]", "afsluitdatum": "2026-05-01", "afsluitbrief": null, "goedgekeurdDoor": null }

{ "@self": { "register": "procest", "schema": "complaintDisposition", "slug": "oordeel-2026-0004" },
  "oordeel": "gegrond", "toelichting": "De gemeente heeft de wettelijke behandeltermijn voor de omgevingsvergunning overschreden en heeft de aanvrager niet geïnformeerd over de vertraging. Dit is in strijd met de beginselen van behoorlijk bestuur.", "maatregelen": "[{\"omschrijving\": \"Aanvraag met voorrang opgepakt en behandeld\", \"verantwoordelijke\": \"RO-afdeling\", \"deadline\": \"2026-05-10\"}, {\"omschrijving\": \"Procesbeschrijving communicatie vergunningstraject herzien\", \"verantwoordelijke\": \"Kwaliteitsmedewerker\", \"deadline\": \"2026-06-01\"}]", "afsluitdatum": "2026-05-03", "afsluitbrief": null, "goedgekeurdDoor": "k.coordinator" }
```

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Awb working-day math must respect Dutch public holidays | Centralize in `WorkingDayCalculator` with a configurable holiday lookup; unit tests for boundary dates (Koningsdag, variabele feestdagen) |
| Privacy of `betrokkenMedewerker` data | Field readable only by `klachten-coordinator` role; HR threshold alerts contain anonymized text only; raw data gated behind separate ACL |
| Frequency reports risk re-identification with small populations | Minimum threshold of 3 complaints per slice before showing employee-level data in analytics dashboard |
| Verdaging double-application | `verdagingMogelijk` boolean enforced server-side; second extension attempt rejected with error |
| Calendar invite failures (IManager unavailable) | `HearingService` logs warning and continues; hearing is created without calendar invitation; UI shows manual-invite banner |

## Standards

- **Awb chapter 9** — Algemene wet bestuursrecht: klachtrecht, deadline obligations, hoorgesprek right, written disposition requirement.
- **VNG Model Klachtenverordening** — Standard complaint ordinance template for Dutch municipalities.
- **ISO 10002:2018** — Quality management guidelines for complaints handling.
- **GEMMA klachtafhandeling** — Reference architecture for the standard klachtafhandeling process.
- **ZGW Zaken API** — Complaint escalation case uses the existing procest `case`/`caseType` infrastructure aligned with ZGW.
