# Design: besluitvorming-workflow

## Architecture

The besluitvorming workflow sits as a vertical domain configuration on top of the generic Procest workflow engine. It does NOT introduce new OpenRegister schemas — all data is stored in existing ADR-000 entities (`caseType`, `case`, `workflowTemplate`, `statusType`, `voorstel`, `parafeerroute`, `parafeeractie`, `decision`, `role`, `task`, `document`). New code is limited to four service classes and three Vue components that orchestrate the existing primitives into decision-specific behaviour.

### Architectural Layers

```
┌─────────────────────────────────────────────────────────┐
│  Vue Layer                                              │
│  AgendaCompilerView  VergaderingDetailView  BesluitPublicatiePanel │
├─────────────────────────────────────────────────────────┤
│  Service Layer                                          │
│  BesluitvormingParafeerService  AgendaService           │
│  PublicationService  MandaatValidationService           │
├─────────────────────────────────────────────────────────┤
│  Workflow Engine (workflow-engine-enhancement)           │
│  WorkflowTemplate  StatusTransition  Guard  AutoAction  │
├─────────────────────────────────────────────────────────┤
│  OpenRegister Entities (ADR-000)                        │
│  case  caseType  voorstel  parafeerroute  decision ...  │
└─────────────────────────────────────────────────────────┘
```

### Service Layout

- **`BesluitvormingParafeerService`** — When a `voorstel` is submitted for parafering, activates the assigned `parafeerroute`, snapshots the route steps into `voorstel.routeSnapshot`, creates a `task` for the first parafeerder, and monitors completion. When all steps are parafeered (`parafeeractie.action = 'goedgekeurd'`), updates `voorstel.status = 'gereed_voor_agendering'` and triggers the case's `workflowTemplate` transition to "Gereed voor agendering".

- **`AgendaService`** — Compiles cases with status "Gereed voor agendering" into a `vergadering` case. Each item is classified as `hamerstuk` (consent agenda) or `bespreekstuk` (discussion item), stored in `caseProperty` on the agenda item. Produces an ordered agenda `document` via Docudesk. Supports drag-and-drop reordering via `caseProperty.order`.

- **`PublicationService`** — On the "Bekendmaking" workflow transition, assembles a DROP/LVBB publication payload from the `decision` object fields (`title`, `decisionDate`, `effectiveDate`, `governingBody`, `explanation`) plus linked `document` objects, and dispatches via OpenConnector to the configured endpoint. Stores the publication reference URI back on `decision.publicationDate` and `caseProperty`.

- **`MandaatValidationService`** — On the Mandaatbesluit "Besluit genomen" transition guard, queries the configured mandaatregister (URL stored in app settings) for the signing official's mandate level and compares it against the `caseType.subject` classification. Blocks the transition and surfaces an error if authority is insufficient.

### Data Model

All entities are from ADR-000. No new schemas are introduced.

**Key entity usage for besluitvorming:**

| Entity | Role in Besluitvorming |
|--------|------------------------|
| `caseType` | College-besluit, Raadsbesluit, Mandaatbesluit definitions |
| `workflowTemplate` | Eight-phase lifecycle with guards and automatic actions |
| `statusType` | Lifecycle phases per caseType |
| `case` | Individual besluitvorming dossiers |
| `voorstel` | Formal proposal document with type Collegeadvies or Raadsvoorstel |
| `parafeerroute` | Configured approval chain per zaaktype |
| `parafeeractie` | Immutable record of each paraaf action |
| `decision` | Formal besluit with stemuitslag, governingBody, effectiveDate |
| `decisionType` | Besluit classification (goedgekeurd, verworpen, aangehouden, ingetrokken) |
| `role` | Portefeuillehouder, Steller, Afdelingshoofd, Raadslid |
| `roleType` | Role type definitions per caseType |
| `task` | Paraaf tasks assigned to each parafeerder |
| `document` | Voorstelnotitie, advies, agenda, besluit, bekendmaking |
| `documentType` | Typed document requirements per caseType |
| `propertyDefinition` | vergadergremia, stemuitslag, portefeuillehouder metadata |
| `caseProperty` | Instance values for the above definitions |
| `statusType` | Phase definitions with order and isFinal flag |
| `resultType` | Besluit genomen, Niet-besloten, Aangehouden, Ingetrokken |

### Workflow Step Definition (College-besluit example)

```json
[
  { "id": "...", "title": "Voorstel opstellen",        "order": 1, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Ambtelijk advies",           "order": 2, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Parafering",                 "order": 3, "status": "<statusType-uuid>",
    "automaticActions": [{ "type": "webhook", "target": "BesluitvormingParafeerService.activate" }] },
  { "id": "...", "title": "Gereed voor agendering",     "order": 4, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Geagendeerd",                "order": 5, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Vergadering",                "order": 6, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Besluit genomen",            "order": 7, "status": "<statusType-uuid>" },
  { "id": "...", "title": "Bekendmaking",               "order": 8, "status": "<statusType-uuid>",
    "automaticActions": [{ "type": "webhook", "target": "PublicationService.dispatch" }] },
  { "id": "...", "title": "Gearchiveerd",               "order": 9, "status": "<statusType-uuid>", "isFinal": true }
]
```

### Guard Conditions per Transition

| Transition | Guard Type | Condition |
|------------|-----------|-----------|
| Parafering → Gereed voor agendering | `requiredField` | All `parafeeractie` for the `voorstel` have `action = 'goedgekeurd'` |
| Vergadering → Besluit genomen | `requiredField` | `decision.stemuitslag` and `decision.governingBody` are set |
| Besluit genomen → Bekendmaking | `requiredDocument` | Signed besluitdocument is attached |
| Mandaatbesluit Besluit genomen | `roleGuard` | MandaatValidationService confirms signing official has authority |

---

## Seed Data

Seed data is loaded by the repair step (`BesluitvormingTemplateService.seed()`) and is idempotent.

### catalogus (1 example)

```json
{
  "domein": "BVWF",
  "rsin": "123456789",
  "contactpersoonBeheerNaam": "Griffier Gemeente",
  "contactpersoonBeheerTelefoonnummer": "14070",
  "contactpersoonBeheerEmailadres": "griffier@gemeente.nl"
}
```

### caseType (3 examples)

```json
[
  {
    "title": "College-besluit",
    "description": "Formeel besluit van het college van burgemeester en wethouders",
    "purpose": "Vaststellen van collegebesluiten conform het collegeprogramma",
    "trigger": "Beleidsvoorstel of wettelijke verplichting",
    "subject": "Bestuurlijk besluit College B&W",
    "processingDeadline": "P30D",
    "publicationRequired": true,
    "internalOrExternal": "intern",
    "confidentiality": "openbaar"
  },
  {
    "title": "Raadsbesluit",
    "description": "Formeel besluit van de gemeenteraad, inclusief moties en amendementen",
    "purpose": "Vaststellen van raadsbesluiten en verordeningen",
    "trigger": "Raadsvoorstel ingediend door college of raadslid",
    "subject": "Bestuurlijk besluit Gemeenteraad",
    "processingDeadline": "P60D",
    "publicationRequired": true,
    "internalOrExternal": "intern",
    "confidentiality": "openbaar"
  },
  {
    "title": "Mandaatbesluit",
    "description": "Besluit genomen op basis van ambtelijk of politiek mandaat",
    "purpose": "Vastleggen van besluiten binnen gedelegeerde bevoegdheden",
    "trigger": "Aanvraag of beleidswijziging binnen mandaatgrens",
    "subject": "Mandaatbesluit",
    "processingDeadline": "P14D",
    "publicationRequired": false,
    "internalOrExternal": "intern",
    "confidentiality": "intern"
  }
]
```

### workflowTemplate (2 examples)

```json
[
  {
    "title": "College-besluit workflow v1",
    "description": "Standaard besluitvormingsworkflow voor het college van B&W: voorstel → advies → parafering → agendering → vergadering → besluit → bekendmaking → archivering",
    "version": 1,
    "isActive": true,
    "isDraft": false
  },
  {
    "title": "Raadsbesluit workflow v1",
    "description": "Besluitvormingsworkflow voor de gemeenteraad inclusief commissiebehandeling en plenaire vergadering",
    "version": 1,
    "isActive": true,
    "isDraft": false
  }
]
```

### statusType (5 examples for College-besluit)

```json
[
  { "name": "Voorstel opstellen",      "order": 1, "isFinal": false, "description": "Ambtenaar stelt het collegeadvies op" },
  { "name": "Ambtelijk advies",        "order": 2, "isFinal": false, "description": "Inhoudelijk advies door beleidsadviseur" },
  { "name": "Parafering",             "order": 3, "isFinal": false, "description": "Sequentiële goedkeuring via parafeerroute" },
  { "name": "Gereed voor agendering", "order": 4, "isFinal": false, "description": "Alle parafen verzameld, klaar voor planning vergadering" },
  { "name": "Geagendeerd",            "order": 5, "isFinal": false, "description": "Opgevoerd op de agenda van een vergadering" },
  { "name": "Vergadering",            "order": 6, "isFinal": false, "description": "Behandeling tijdens de vergadering" },
  { "name": "Besluit genomen",        "order": 7, "isFinal": false, "description": "Besluit geregistreerd inclusief stemuitslag" },
  { "name": "Bekendmaking",           "order": 8, "isFinal": false, "description": "Publicatie via DROP of LVBB" },
  { "name": "Gearchiveerd",           "order": 9, "isFinal": true,  "description": "Dossier gearchiveerd conform selectielijst" }
]
```

### voorstel (3 examples)

```json
[
  {
    "type": "Collegeadvies",
    "onderwerp": "Vaststelling Beleidsplan Duurzaamheid 2027-2031",
    "afdeling": "Afdeling Ruimte en Duurzaamheid",
    "status": "ingediend",
    "currentStep": 1,
    "behandeling": "hamerstuk"
  },
  {
    "type": "Raadsvoorstel",
    "onderwerp": "Wijziging Algemene Plaatselijke Verordening artikel 4.2 (evenementen)",
    "afdeling": "Afdeling Openbare Orde en Veiligheid",
    "status": "in_parafering",
    "currentStep": 2,
    "behandeling": "bespreekstuk"
  },
  {
    "type": "DT-advies",
    "onderwerp": "Mandaatbesluit vergunningverlening kleine bouwwerken",
    "afdeling": "Afdeling Vergunningen",
    "status": "gereed_voor_agendering",
    "currentStep": 0,
    "behandeling": "hamerstuk"
  }
]
```

### parafeerroute (3 examples)

```json
[
  {
    "name": "Collegeadvies standaard — 3 stappen",
    "voorstelType": "Collegeadvies",
    "isDefault": true,
    "description": "Standaard parafeerketen voor collegeadviezen: beleidsadviseur → afdelingshoofd → gemeentesecretaris",
    "steps": [
      { "order": 1, "role": "Beleidsadviseur", "type": "paraaf", "required": true },
      { "order": 2, "role": "Afdelingshoofd",  "type": "paraaf", "required": true },
      { "order": 3, "role": "Gemeentesecretaris", "type": "paraaf", "required": true }
    ]
  },
  {
    "name": "Raadsvoorstel — 4 stappen",
    "voorstelType": "Raadsvoorstel",
    "isDefault": true,
    "description": "Parafeerketen voor raadsvoorstellen met griffier als eindstap",
    "steps": [
      { "order": 1, "role": "Beleidsadviseur",   "type": "paraaf", "required": true },
      { "order": 2, "role": "Afdelingshoofd",     "type": "paraaf", "required": true },
      { "order": 3, "role": "Gemeentesecretaris", "type": "paraaf", "required": true },
      { "order": 4, "role": "Griffier",           "type": "paraaf", "required": true }
    ]
  },
  {
    "name": "Mandaatbesluit — verkorte route",
    "voorstelType": "DT-advies",
    "isDefault": true,
    "description": "Verkorte route voor mandaatbesluiten: directeur is eindparafeerder",
    "steps": [
      { "order": 1, "role": "Beleidsadviseur", "type": "paraaf",  "required": true },
      { "order": 2, "role": "Directeur",       "type": "paraaf",  "required": true }
    ]
  }
]
```

### decision (3 examples)

```json
[
  {
    "title": "Vaststelling Beleidsplan Duurzaamheid 2027-2031",
    "description": "Het college stelt het Beleidsplan Duurzaamheid 2027-2031 vast",
    "decisionDate": "2026-03-15",
    "effectiveDate": "2026-04-01",
    "governingBody": "College van Burgemeester en Wethouders",
    "explanation": "Besloten conform het coalitieakkoord, paragraaf Klimaat en Energie"
  },
  {
    "title": "Wijziging APV artikel 4.2 (evenementen)",
    "description": "De gemeenteraad wijzigt artikel 4.2 van de APV inzake evenementenvergunningen",
    "decisionDate": "2026-04-22",
    "effectiveDate": "2026-05-15",
    "publicationDate": "2026-04-23",
    "governingBody": "Gemeenteraad",
    "explanation": "Aangenomen met 23 stemmen voor, 8 stemmen tegen. Motie D66 aangenomen."
  },
  {
    "title": "Mandaatbesluit kleine bouwwerken 2026",
    "description": "Mandaatverlening aan afdelingshoofden voor vergunningverlening kleine bouwwerken tot EUR 250.000",
    "decisionDate": "2026-02-10",
    "effectiveDate": "2026-02-10",
    "governingBody": "College van Burgemeester en Wethouders",
    "explanation": "Conform het mandaatregister 2026, categorie VTH-M-04"
  }
]
```

### roleType (4 examples — for College-besluit caseType)

```json
[
  { "name": "Steller",            "description": "Ambtenaar die het voorstel opstelt" },
  { "name": "Portefeuillehouder", "description": "Wethouder of burgemeester verantwoordelijk voor het dossier" },
  { "name": "Beleidsadviseur",    "description": "Adviseur die inhoudelijk advies levert" },
  { "name": "Afdelingshoofd",     "description": "Hoofd van de betrokken afdeling" }
]
```

### propertyDefinition (5 examples — for College-besluit caseType)

```json
[
  { "name": "stemuitslag",         "propertyType": "string",  "isRequired": false, "description": "Uitkomst van de stemming (bijv. 'Unaniem', '15-8')" },
  { "name": "portefeuillehouder",  "propertyType": "string",  "isRequired": true,  "description": "Nextcloud-gebruiker van de verantwoordelijk wethouder" },
  { "name": "vergadergremium",     "propertyType": "string",  "isRequired": true,  "description": "Het besluitvormend orgaan (College B&W / Gemeenteraad / Commissie)" },
  { "name": "agendanummer",        "propertyType": "string",  "isRequired": false, "description": "Nummer op de vergaderagenda (bijv. '5.3')" },
  { "name": "publicatieReferentie","propertyType": "string",  "isRequired": false, "description": "Referentie-URI van de publicatie in DROP of LVBB" }
]
```

---

## API Surface (V1)

- `POST /api/besluitvorming/templates/{slug}/activate` — activate a bundled zaaktype template.
- `POST /api/besluitvorming/cases/{id}/agenda` — add case to an agenda (AgendaService.addItem).
- `PUT /api/besluitvorming/cases/{id}/agenda` — update hamerstuk/bespreekstuk classification and order.
- `POST /api/besluitvorming/cases/{id}/publish` — trigger DROP/LVBB publication (PublicationService.dispatch).
- `GET /api/besluitvorming/cases/{id}/mandaat-check` — validate signing official's mandate authority.

## Dependencies

- `workflow-engine-enhancement` — workflow engine with guard + auto-action support (REQUIRED).
- `bw-parafering` spec — parafeerroute, parafeeractie, voorstel primitives.
- `roles-decisions` spec — roleType and decisionType patterns.
- OpenRegister — data storage for all entities.
- Docudesk — agenda document and besluit document generation.
- Nextcloud Calendar — vergadering scheduling.
- OpenConnector — DROP/LVBB dispatch.

## Out of Scope

- Open Raadsinformatie API (covered by `openspec/changes/open-raadsinformatie/`).
- Document generation templates (Docudesk configuration, separate change).
- Financial impact tracking of decisions (ERP domain).
- Generic workflow engine functionality (covered by `workflow-engine-enhancement`).
- Citizen-facing besluit portal.
