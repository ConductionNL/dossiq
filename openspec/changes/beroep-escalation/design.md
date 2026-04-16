# Design: Beroep Escalation

## Architecture Overview

Beroep escalation extends the existing bezwaar/beroep module (Group 6 entities: `case`, `caseType`, `statusType`, `roleType`, `resultType`, `propertyDefinition`, `caseDocument`, `caseProperty`). No new OpenRegister schemas are introduced — this change adds seed data and wires new UI/backend logic around existing entities.

```
BezwaarCaseDetail.vue
├── [action] "Escaleren naar beroep"  (visible when status: Beslissing op bezwaar | Afgehandeld)
│   └── BeroepEscalatieDialog.vue     (pre-filled form, voorzieningRequested toggle)
│       └── POST /api/cases/{id}/escalate-to-beroep → BeroepEscalationController
│           └── BeroepEscalationService (create beroep case, set parentCase, copy appellant)
└── ActivityTimeline.vue              (shows link to beroep case after escalation)

BeroepCaseDetail.vue  (standard CaseDetail with case type = "Beroep")
├── [badge] "Voorlopige voorziening aangevraagd"  (when voorzieningRequested = true)
├── [action] "Verweerschrift uploaden"             (status: Verweerschrift in voorbereiding)
├── [action] "Uitspraak registreren"               (status: Zitting afgerond)
│   └── UitspraakDialog.vue                        (outcome selector + follow-up task option)
│       └── POST /api/cases/{id}/uitspraak → UitspraakController
└── HogerBeroepBanner.vue                          (visible when status: Uitspraak ontvangen | Afgehandeld)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Controller/BeroepEscalationController.php` | Endpoint: create beroep case from bezwaar case |
| `lib/Controller/UitspraakController.php` | Endpoint: record ruling outcome, trigger status transition, optionally create follow-up task |
| `lib/Service/BeroepEscalationService.php` | Business logic: validate bezwaar status, create beroep case, set parentCase, copy appellant role, link beslissing op bezwaar |
| `lib/Service/UitspraakService.php` | Business logic: record ruling on beroep case, transition status, create follow-up task if needed |
| `src/views/cases/components/BeroepEscalatieDialog.vue` | Dialog to create beroep case from bezwaar; pre-filled fields; voorzieningRequested toggle |
| `src/views/cases/components/UitspraakDialog.vue` | Dialog to record court ruling outcome (beroep_gegrond / beroep_ongegrond / deels_gegrond / niet_ontvankelijk); optional follow-up task |
| `src/views/cases/components/HogerBeroepBanner.vue` | Informational banner shown after uitspraak; static text referencing ABRvS and CRvB |
| `src/services/beroepEscalatieApi.js` | Frontend API service for escalation and uitspraak endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add seed objects: Beroep caseType, 9 statusTypes, 3 roleTypes, 4 resultTypes, 3 documentTypes, 1 propertyDefinition, 3 example cases |
| `appinfo/routes.php` | Add routes for `/cases/{id}/escalate-to-beroep` and `/cases/{id}/uitspraak` |
| `src/views/cases/CaseDetail.vue` | Conditionally render BeroepEscalatieDialog action (bezwaar cases), VoorzieningBadge, UitspraakDialog action, HogerBeroepBanner (beroep cases) |

## Design Decisions

### DD-01: No new OpenRegister schemas

The existing `case` + `caseType` + `statusType` pattern fully covers the Beroep lifecycle. A new `beroep` schema would duplicate case functionality. Instead, seed data creates a "Beroep" caseType and its dependent types; all court proceedings data is stored as standard case + caseProperty records.

### DD-02: Parent-child via `case.parentCase`

The ADR-000 data model defines `case.parentCase` (uuid) for sub-case linking. Setting `parentCase` on the beroep case to the bezwaar case UUID captures the escalation relationship without a separate join entity. The bezwaar case displays the beroep link by querying cases where `parentCase = bezwaarCaseId`.

### DD-03: VoorzieningRequested as propertyDefinition

`voorzieningRequested` is a boolean property on the Beroep case type's propertyDefinition. It is stored as a `caseProperty` record (value "true"/"false") rather than a new schema field, consistent with ADR-000's extensibility pattern.

### DD-04: Ruling outcome drives status, not result type

The uitspraak outcome (beroep_gegrond, beroep_ongegrond, deels_gegrond, niet_ontvankelijk) is captured as the case `result` (resultType), not just a caseProperty. This makes it queryable and reportable as a first-class case outcome in line with existing archival rules.

## Seed Data

Seed objects use the `@self` envelope for register/schema/slug references. All objects are for the municipality "Gemeente Westerbork" (RSIN: 001234567).

### caseType

```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "beroep" },
  "title": "Beroep",
  "description": "Beroepsprocedure bij de bestuursrechter conform Awb hoofdstuk 8",
  "trigger": "Beroepschrift bij de bestuursrechter",
  "subject": "Beroep tegen beslissing op bezwaar",
  "processingDeadline": "P26W",
  "extensionAllowed": false,
  "suspensionAllowed": true,
  "origin": "external",
  "internalOrExternal": "extern",
  "isDraft": false,
  "validFrom": "2026-01-01"
}
```

### statusType (9 records for Beroep)

```json
[
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-ontvangen" },
    "name": "Beroep ontvangen", "description": "Beroepschrift ontvangen van rechtbank", "caseType": "@slug:beroep", "order": 1, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-verweerschrift-in-voorbereiding" },
    "name": "Verweerschrift in voorbereiding", "description": "Gemeente bereidt verweerschrift voor", "caseType": "@slug:beroep", "order": 2, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-verweerschrift-ingediend" },
    "name": "Verweerschrift ingediend", "description": "Verweerschrift ingediend bij de rechtbank", "caseType": "@slug:beroep", "order": 3, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-zitting-gepland" },
    "name": "Zitting gepland", "description": "Zitting bij de rechtbank is ingepland", "caseType": "@slug:beroep", "order": 4, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-zitting-afgerond" },
    "name": "Zitting afgerond", "description": "Rechtbankzitting heeft plaatsgevonden", "caseType": "@slug:beroep", "order": 5, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-uitspraak-ontvangen" },
    "name": "Uitspraak ontvangen", "description": "Uitspraak van de rechtbank is ontvangen", "caseType": "@slug:beroep", "order": 6, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-afgehandeld" },
    "name": "Afgehandeld", "description": "Zaak afgehandeld na uitspraak", "caseType": "@slug:beroep", "order": 7, "isFinal": true },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-ingetrokken" },
    "name": "Ingetrokken", "description": "Beroep ingetrokken door appellant", "caseType": "@slug:beroep", "order": 90, "isFinal": true },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "beroep-schikking" },
    "name": "Schikking", "description": "Zaak buiten rechte geschikt", "caseType": "@slug:beroep", "order": 91, "isFinal": true }
]
```

### roleType (3 records for Beroep)

```json
[
  { "@self": { "register": "procest", "schema": "roleType", "slug": "beroep-behandelaar" },
    "name": "Behandelaar", "description": "Ambtenaar verantwoordelijk voor de beroepsprocedure", "caseType": "@slug:beroep" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "beroep-appellant" },
    "name": "Appellant", "description": "Burger of organisatie die beroep heeft ingesteld", "caseType": "@slug:beroep" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "beroep-rechtbank-contactpersoon" },
    "name": "Rechtbank-contactpersoon", "description": "Contactpersoon bij de rechtbank voor procedurevragen", "caseType": "@slug:beroep" }
]
```

### resultType (4 records for Beroep ruling outcomes)

```json
[
  { "@self": { "register": "procest", "schema": "resultType", "slug": "beroep-gegrond" },
    "name": "Beroep gegrond", "description": "Rechtbank heeft het beroep gegrond verklaard; gemeente moet nieuw besluit nemen", "caseType": "@slug:beroep", "archivalPeriod": "P10Y", "archivalAction": "bewaren" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "beroep-ongegrond" },
    "name": "Beroep ongegrond", "description": "Rechtbank heeft het beroep ongegrond verklaard; beslissing op bezwaar blijft in stand", "caseType": "@slug:beroep", "archivalPeriod": "P10Y", "archivalAction": "bewaren" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "beroep-deels-gegrond" },
    "name": "Beroep deels gegrond", "description": "Rechtbank heeft het beroep deels gegrond verklaard; gemeente neemt gedeeltelijk nieuw besluit", "caseType": "@slug:beroep", "archivalPeriod": "P10Y", "archivalAction": "bewaren" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "beroep-niet-ontvankelijk" },
    "name": "Niet-ontvankelijk", "description": "Rechtbank heeft het beroep niet-ontvankelijk verklaard", "caseType": "@slug:beroep", "archivalPeriod": "P10Y", "archivalAction": "bewaren" }
]
```

### documentType (3 records for Beroep)

```json
[
  { "@self": { "register": "procest", "schema": "documentType", "slug": "beroepschrift" },
    "name": "Beroepschrift", "description": "Formeel beroepschrift ingediend bij de bestuursrechter", "caseType": "@slug:beroep", "isDraft": false, "isRequired": false, "allowedMimeTypes": "[\"application/pdf\"]" },
  { "@self": { "register": "procest", "schema": "documentType", "slug": "verweerschrift" },
    "name": "Verweerschrift", "description": "Verweerschrift van de gemeente ingediend bij de rechtbank", "caseType": "@slug:beroep", "isDraft": false, "isRequired": false, "allowedMimeTypes": "[\"application/pdf\"]" },
  { "@self": { "register": "procest", "schema": "documentType", "slug": "uitspraak-rechtbank" },
    "name": "Uitspraak rechtbank", "description": "Gepubliceerde uitspraak van de bestuursrechter", "caseType": "@slug:beroep", "isDraft": false, "isRequired": false, "allowedMimeTypes": "[\"application/pdf\"]" }
]
```

### propertyDefinition (1 record for voorzieningRequested)

```json
{
  "@self": { "register": "procest", "schema": "propertyDefinition", "slug": "beroep-voorziening-requested" },
  "name": "voorzieningRequested",
  "definition": "Appellant heeft ook een voorlopige voorziening aangevraagd",
  "description": "Geeft aan of de appellant naast het beroep ook een verzoek om voorlopige voorziening heeft ingediend bij de voorzieningenrechter (spoedeisend karakter)",
  "caseType": "@slug:beroep",
  "propertyType": "boolean",
  "isRequired": false,
  "defaultValue": "false"
}
```

### case (3 example beroep cases)

```json
[
  {
    "@self": { "register": "procest", "schema": "case", "slug": "br-2026-0001" },
    "title": "Beroep: Weigering omgevingsvergunning Kerkstraat 14",
    "description": "Beroep ingesteld door J.A. van der Berg tegen de beslissing op bezwaar inzake de geweigerde omgevingsvergunning voor aanbouw aan Kerkstraat 14 te Westerbork",
    "identifier": "BR-2026-0001",
    "caseType": "@slug:beroep",
    "startDate": "2026-02-10",
    "deadline": "2026-08-10",
    "priority": "normal",
    "assignee": "behandelaar.rechtszaken"
  },
  {
    "@self": { "register": "procest", "schema": "case", "slug": "br-2026-0002" },
    "title": "Beroep: WOZ-waarde woning Molenweg 7",
    "description": "Beroep ingesteld door M.C. Jansen-de Wit tegen de beslissing op bezwaar inzake de vastgestelde WOZ-waarde van de woning Molenweg 7 te Westerbork voor belastingjaar 2025",
    "identifier": "BR-2026-0002",
    "caseType": "@slug:beroep",
    "startDate": "2026-03-01",
    "deadline": "2026-09-01",
    "priority": "normal",
    "assignee": "behandelaar.belastingen"
  },
  {
    "@self": { "register": "procest", "schema": "case", "slug": "br-2026-0003" },
    "title": "Beroep: Bestemmingsplanwijziging Industrieterrein Noord (+ voorlopige voorziening)",
    "description": "Beroep ingesteld door Stichting Leefbaar Westerbork tegen beslissing op bezwaar inzake bestemmingsplanwijziging Industrieterrein Noord. Tevens voorlopige voorziening aangevraagd bij de voorzieningenrechter.",
    "identifier": "BR-2026-0003",
    "caseType": "@slug:beroep",
    "startDate": "2026-03-15",
    "deadline": "2026-09-15",
    "priority": "urgent",
    "assignee": "behandelaar.omgeving"
  }
]
```
