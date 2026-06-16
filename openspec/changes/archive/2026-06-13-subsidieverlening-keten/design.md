# Design: subsidieverlening-keten

## Architecture Overview

The subsidieverlening-keten capability extends procest's case-management foundation with domain-specific workflows for multi-year grant execution. The architecture is layered: `SubsidieRegeling` (policy-level definition) → `SubsidieAanvraag` (application, extends procest `Zaak`) → `SubsidieBeschikking` (formal grant decision, the pivot entity) → `SubsidieUitvoering` (ongoing execution phase) → optional `Tussenrapportage` (interim reports) and final `SubsidieVaststelling` (settlement). If the final settlement amount is less than disbursed voorschotten, an automatic `Terugvordering` (clawback) case is opened.

All entities are stored in OpenRegister with full audit trails. Status transitions and multi-year deadlines are enforced via the shared `termijnbewaking` engine. Bewijsstukken (evidence documents) attach to any phase and carry metadata (type, retention period, archive status). Notifications flow via the existing procest portal-channel with email fallback.

```
CaseDetail (SubsidieAanvraag)
├── AanvraagTab (project details, begroting, cofinanciering)
├── BeschikkingTab (verleend bedrag, looptijd, voorschot-schema, verplichtingen)
├── TussenrapportageTab (list of interim reports with status, bewijsstukken)
├── VaststellingTab (settlement form, accountant declaration, final bedrag)
├── TerugvorderingTab (if applicable; clawback tracking, betaalherinneringen)
└── BewijsstukkenTab (all supporting documents across all phases)

Backend
├── SubsidieService (CRUD, status machines, termijn-binding)
├── TussenrapportageService (create, link bewijsstukken, approve)
├── VaststellingService (settlement math, terugvordering trigger)
├── TerugvorderingService (inning tracking, betaalherinneringen, rente)
├── BewijsstukService (upload, type detection, retention, archive handover)
└── SubsidieRegisterExporter (Wet open overheid feed generation)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/SubsidieService.php` | CRUD, status transitions, multi-year budget tracking, termijn-binding, verplichting tracking |
| `lib/Service/TussenrapportageService.php` | Create interim report (typed sub-zaak), link bewijsstukken, approval workflow |
| `lib/Service/VaststellingService.php` | Settlement calculation, comparison with voorschotten, terugvordering trigger |
| `lib/Service/TerugvorderingService.php` | Clawback case management, betaalherinneringen, invorderingsrente per AWB 4:97 |
| `lib/Service/BewijsstukService.php` | Upload, type classification, bewaartermijn assignment, docudesk archive handover |
| `lib/Service/SubsidieRegisterExporter.php` | JSON feed per Wet open overheid and VNG subsidieregister standard |
| `lib/Service/CofinancieringValidator.php` | Cofinanciering amount and party validation |
| `lib/Service/StatesteunClassifier.php` | De-minimis, AGVV, DAEB classification; TAM-melding generation |
| `lib/Controller/SubsidieController.php` | REST routes under `/api/subsidies` |
| `lib/Migration/AddSubsidieSchemas.php` | Register nine new schemas with procest_register.json |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/subsidies/SubsidieAanvraagList.vue` | Handler inbox; filters by regeling, status, date; overdue items pinned |
| `src/views/subsidies/SubsidieAanvraagDetail.vue` | Header with aanvraagnummer and status; AanvraagTab, BeschikkingTab, TussenrapportageTab, VaststellingTab, BewijsstukkenTab |
| `src/views/subsidies/SubsidieBeschikkingForm.vue` | Beschikking editor: verleend bedrag, looptijd (start/end dates), voorschot-schema builder, verplichting-list editor |
| `src/views/subsidies/TussenrapportageDetail.vue` | Interim report form: inhoudelijke voortgang, financiële verantwoording, bewijsstukken uploader, approval form |
| `src/views/subsidies/VaststellingForm.vue` | Settlement form: werkelijke kosten, realisatie van verplichtingen, accountantsverklaring (mandatory for > €125k), final bedrag |
| `src/views/subsidies/SubsidieRegisterDashboard.vue` | Manager view: total verleend per regeling per year, openstaande voorschotten, active terugvorderingen, overdue reports |
| `src/components/VoorschotSchemaBuilder.vue` | Reusable component to define scheduled disbursement timeline |
| `src/components/VerplichtingenTracker.vue` | Reusable component to manage conditions and link bewijsstukken to each |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add nine new schemas: SubsidieRegeling, SubsidieAanvraag, SubsidieBeoordeling, SubsidieBeschikking, SubsidieUitvoering, Tussenrapportage, SubsidieVaststelling, Terugvordering, Bewijsstuk |
| `lib/Service/SettingsService.php` | Add `subsidie_*` config keys and SLUG_TO_CONFIG_KEY entries |
| `appinfo/routes.php` | Add subsidies module routes and register export endpoint |
| `src/router/index.js` | Add `/subsidies` navigation paths |

## Data Model

### SubsidieRegeling (Policy-level definition)
- `regeling_naam` (string, required) — Name of the grant program
- `juridische_grondslag` (string, required) — Legal basis (AWB reference, kaderwet reference)
- `plafond` (number) — Annual or total spending cap
- `looptijd_start` (date) — When the regeling becomes effective
- `looptijd_eind` (date, nullable) — When the regeling expires
- `doelgroep` (string) — Target group (gemeenten, kmo's, ngo's, etc.)
- `beoordelingscriteria_template` (string) — JSON schema defining evaluation criteria
- `tussenrapportage_frequentie` (enum) — `jaarlijks`, `halfjaarlijks`, `op_mijlpaal` — or null if no interim reports
- `accountantsverklaring_drempel` (number) — EUR amount above which accountant declaration is mandatory

### SubsidieAanvraag (extends procest `Zaak`)
- `aanvraagnummer` (string, auto) — Auto-generated identifier
- `subsidieregeling` (reference) → SubsidieRegeling
- `aangevraagd_bedrag` (number, required) — Requested grant amount
- `project_startdatum` (date, required)
- `project_einddatum` (date, required)
- `begroting` (string) — JSON array of cost items: `[{kostenpost, bedrag, eenheid}]`
- `cofinanciering_list` (string) — JSON array: `[{partij, bedrag, percentage}]`
- `aanvrager_kvk_ref` (string, optional) — KvK number for organizations
- `aanvrager_bsn_ref` (string, optional) — BSN for individuals (encrypted per GDPR)
- `status` (reference) → statusType

### SubsidieBeoordeling (Assessment record)
- `subsidieaanvraag` (reference, required) → SubsidieAanvraag
- `beoordelaar` (string) — User UID of assessor
- `inhoudelijke_toets_oordeel` (enum) — `voldoende`, `onvoldoende`, `nader_onderzoek`
- `financiele_toets_oordeel` (enum) — `acceptabel`, `onacceptabel_budget`, `onacceptabel_cofinanciering`
- `scorings` (string) — JSON map of criteria_id → score
- `advies` (string) — Assessment summary and recommendation
- `advies_onderbouwing` (text) — Detailed reasoning
- `beoordelingsdatum` (date)

### SubsidieBeschikking (Grant decision — pivot entity)
- `subsidieaanvraag` (reference, required) → SubsidieAanvraag
- `beschikkingnummer` (string, auto)
- `beschikkingtype` (enum) — `verleningsbeschikking`, `wijzigingsbeschikking`, `vaststellingsbeschikking`
- `verleend_bedrag` (number, required) — EUR amount granted (may differ from aangevraagd)
- `looptijd_start` (date, required)
- `looptijd_eind` (date, required)
- `voorschot_schema` (string) — JSON array: `[{datum, bedrag, voorwaarde}]` — must sum to verleend_bedrag
- `verplichtingen` (string) — JSON array of conditions: `[{beschrijving, status, bewijsstukken_vereist, deadline}]`
- `wettelijke_grondslag` (string) — Exact AWB/kaderwet article
- `bezwaartermijn_einde` (date, calculated) — 6 weeks from publication
- `trekt_in_besluit` (reference, optional) — Prior beschikking being superseded
- `beschikkingsdatum` (date)
- `publicatiedatum` (date)

### SubsidieUitvoering (Ongoing execution phase)
- `subsidieaanvraag` (reference, required)
- `subsidiebesch

ikking` (reference, required)
- `status` (enum) — `verleend`, `in_uitvoering`, `tussenrapportage_ontvangen`, `tussenrapportage_beoordeeld`, `vaststelling_aangevraagd`, `vastgesteld`, `terugvordering_gestart`, `afgerond`
- `tussenrapportages` (string) — JSON array of Tussenrapportage references
- `betaalde_voorschotten` (string) — JSON array of disbursements: `[{datum, bedrag, betaal_id_erp}]`
- `openstaande_voorschotten` (string) — JSON array of pending scheduled disbursements
- `bewijsstukken_index` (string) — JSON map of verplichting_id → [bewijsstuk_references]

### Tussenrapportage (Interim report — typed sub-zaak)
- `subsidieuitvoering` (reference, required)
- `rapportagenummer` (string, auto)
- `rapportage_periode_start` (date, required)
- `rapportage_periode_eind` (date, required)
- `inhoudelijke_voortgang` (text) — Progress narrative
- `financiele_verantwoording` (string) — JSON: `{uitgaven_totaal, naar_begroting_vergeleken, afwijkingen}`
- `bewijsstukken` (string) — JSON array of Bewijsstuk references
- `status` (enum) — `verwacht`, `ingediend`, `in_beoordeling`, `goedgekeurd`, `afgekeurd`, `gedeeltelijk_goedgekeurd`
- `beoordelaar` (string, optional) — User UID when marked approved
- `beoordelingsdatum` (date, optional)
- `beoordelingsoordeel` (text, optional)
- `ingekeurde_bedrag` (number, optional) — EUR amount approved (may differ from claimed)

### SubsidieVaststelling (Final settlement)
- `subsidieuitvoering` (reference, required)
- `werkelijke_kosten_totaal` (number, required)
- `realisatie_verplichtingen` (string) — JSON map of verplichting_id → `{bewijzen, status, aantekening}`
- `accountantsverklaring_vereist` (boolean) — Determined by beschikking verleend_bedrag vs. drempel
- `accountantsverklaring_document` (reference, optional) — Bewijsstuk reference
- `vastgesteld_bedrag` (number, required) — Final EUR amount (may be lower due to unmet conditions)
- `vaststellingsdatum` (date)
- `vaststellingsbeschikking_generated` (boolean)
- `trigger_terugvordering` (boolean, auto) — True if vastgesteld_bedrag < totaal_voorschotten

### Terugvordering (Clawback case)
- `subsidieuitvoering` (reference, required)
- `terugvorderingsnummer` (string, auto)
- `bedrag` (number, required) — EUR amount to recover
- `wettelijke_grondslag` (string) — AWB 4:57 reference
- `bezwaartermijn_einde` (date, calculated) — 6 weeks from publication
- `betaaltermijn_einde` (date, calculated) — 4 weeks from publication
- `status` (enum) — `opgelegd`, `betaald`, `gedeeltelijk_betaald`, `in_invorderingsprocedure`, `invordering_afgerond`
- `betaalherinneringen_count` (integer)
- `invorderingsrente_berekend` (number, optional) — EUR rente per AWB 4:97
- `deurwaards_referentie` (string, optional) — Case ID if escalated to deurwaarder

### Bewijsstuk (Polymorphic evidence document)
- `titel` (string, required) — Document title
- `beschrijving` (text, optional)
- `bewijsstuk_type` (enum) — `aanvraagdocument`, `begroting`, `projectplan`, `cofinancieringsverklaring`, `voortgangsrapport`, `urenstaat`, `factuur`, `bankafschrift`, `accountantsverklaring`, `eindrapport`, `deelnemerslijst`, `ander`
- `gekoppeld_aan` (enum) — `aanvraag`, `tussenrapportage`, `vaststelling`, `verplichtingsbewijs`
- `gekoppeld_object_ref` (reference) — Actual Aanvraag/Tussenrapportage/Vaststelling ref
- `gekoppeld_verplichting_id` (string, optional) — If type = verplichtingsbewijs
- `bewaartermijn_jaren` (integer) — Retention years per Selectielijst
- `bewaartermijn_einde` (date, calculated)
- `archief_status` (enum) — `actief`, `gearchiveerd`, `verwijderd`
- `bestandid` (integer) — Nextcloud file ID
- `bestand_hash_sha256` (string)

## API Design

### Authenticated Endpoints (SubsidieController)

**Subsidie Aanvraag**
- `GET /api/subsidies` — List cases, filter by regeling, status, handler, date range
- `GET /api/subsidies/{id}` — Retrieve single aanvraag with all linked entities
- `POST /api/subsidies` — Create new aanvraag; auto-bind termijn-counter
- `PATCH /api/subsidies/{id}` — Update aanvraag properties
- `POST /api/subsidies/{id}/beoordelen` — Submit assessment (SubsidieBeoordeling)
- `POST /api/subsidies/{id}/beschikking/create` — Draft beschikking
- `POST /api/subsidies/{id}/beschikking/publish` — Publish beschikking (legal effect, termijn-counter starts)

**Subsidie Beschikking**
- `GET /api/subsidies/{id}/beschikking` — Retrieve beschikking
- `PATCH /api/subsidies/{id}/beschikking` — Update beschikking (voorschot-schema, verplichtingen, verleend_bedrag)
- `POST /api/subsidies/{id}/beschikking/validate` — Validate voorschot-schema sum equals verleend_bedrag
- `POST /api/subsidies/{id}/beschikking/sign` — Digitally sign beschikking document

**Tussenrapportage**
- `GET /api/subsidies/{id}/tussenrapportages` — List interim reports
- `POST /api/subsidies/{id}/tussenrapportages` — Create new interim report (auto status = "verwacht" if not yet due)
- `GET /api/subsidies/{id}/tussenrapportages/{reportId}` — Retrieve interim report
- `POST /api/subsidies/{id}/tussenrapportages/{reportId}/indiening` — Mark as "ingediend" (applicant submission)
- `POST /api/subsidies/{id}/tussenrapportages/{reportId}/beoordelen` — Submit assessment; update execution status

**Vaststelling**
- `POST /api/subsidies/{id}/vaststelling` — Create settlement form
- `PATCH /api/subsidies/{id}/vaststelling` — Update settlement form (werkelijke kosten, realisatie_verplichtingen)
- `POST /api/subsidies/{id}/vaststelling/vast` — Finalize settlement; auto-trigger terugvordering if needed

**Terugvordering**
- `GET /api/subsidies/{id}/terugvordering` — Retrieve clawback case
- `POST /api/subsidies/{id}/terugvordering/{tvId}/betaalherindering` — Send payment reminder
- `PATCH /api/subsidies/{id}/terugvordering/{tvId}` — Update status, record partial payment

**Bewijsstukken**
- `POST /api/subsidies/{id}/bewijsstukken` — Upload evidence document with metadata (type, gekoppeld_aan, gekoppeld_object_ref)
- `GET /api/subsidies/{id}/bewijsstukken` — List all evidence for this case
- `DELETE /api/subsidies/{id}/bewijsstukken/{docId}` — Unlink (preserves document for archival)

**Subsidieregister Feed**
- `GET /api/subsidies/register/export?status=verleend&status=vastgesteld` — JSON array per Wet open overheid standard, with optional status and year filters

## Risks & Mitigations

- **Multi-year deadline math**: Termijnbewaking must correctly handle tussenrapportage deadline schedules that span years. *Mitigation*: Centralize termijn calculation in a helper; test with sample regelingen (ASV, ZonMW, OCW).
- **Voorschot-schema validation**: If scheduled disbursements don't sum to verleend_bedrag, financial reconciliation breaks. *Mitigation*: Reject beschikking creation if sum validation fails; audit trail all edits to voorschot-schema.
- **Bewijsstukken retention and archival**: Documents must be archived per Selectielijst after bewaartermijn; premature deletion or late archival violates Archiefwet. *Mitigation*: Automation via docudesk integration; separate governance review before destroy action.
- **EU staatssteun classification**: Incorrect de-minimis/AGVV classification risks legal challenge. *Mitigation*: Statesteun classifier has escalation to legal reviewer; TAM-melding is async for auditable timestamp.
- **Terugvordering automation**: Auto-trigger on vaststelling may initiate clawback wrongly if math is incorrect. *Mitigation*: Separate review workflow; manager approval before terugvordering case goes to "opgelegd".

## Standards

AWB titel 4.2, VNG-modelverordening ASV, Kaderwet subsidies (sector-specific), Comptabiliteitswet 2016, Financiële-verhoudingswet, AGVV 651/2014, de-minimisverordening 1407/2013, Wet open overheid artikel 3.3, VNG-richtlijn subsidieregister, Selectielijst gemeenten (4.x), Archiefwet 1995, NBA-handreiking 1117 (accountant controls).
