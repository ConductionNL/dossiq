# Design: bezwaar-beroep-workflow

## Domain framing — bezwaar en beroep as case management

Procest models bezwaar en beroep as **cases** (`schema:Project`) of
specific caseTypes. The bezwaar case is always linked to the original
case whose decision is being contested (primair besluit). The beroep
case is always linked to the bezwaar case. This gives a clean
three-level chain: primair besluit case → bezwaar case → beroep case.

```
┌─────────────────────────────────────────────────────────┐
│  Primair besluit case  (existing procest case)           │
│  e.g. Omgevingsvergunning 2026-OGV-0117                 │
│  decision: Vergunning verleend                           │
└───────────────────────┬─────────────────────────────────┘
                        │ relatedCases / contestedDecision
                        ▼
┌─────────────────────────────────────────────────────────┐
│  Bezwaar case  (caseType: Bezwaarschrift behandeling)   │
│  workflowTemplate: Bezwaar AWB workflow                 │
│  deadline: primair besluit date + 6 weeks (AWB art 7:10)│
│                                                         │
│  ┌──────────┐  ┌────────────┐  ┌────────────────────┐  │
│  │objection │  │hearingSession│ │advisoryReport      │  │
│  │(bezwaar  │  │(hoorzitting) │ │(commissie advies)  │  │
│  │schrift)  │  │             │ │                    │  │
│  └──────────┘  └────────────┘  └────────────────────┘  │
│                                                         │
│  ┌──────────────────────────────────────────────────┐   │
│  │  appealDecision  (beslissing op bezwaar)          │   │
│  │  rechtsmiddelenclausule → beroep information     │   │
│  └──────────────────────────────────────────────────┘   │
└───────────────────────┬─────────────────────────────────┘
                        │ relatedCases (bij escalatie)
                        ▼
┌─────────────────────────────────────────────────────────┐
│  Beroep case  (caseType: Beroepschrift behandeling)     │
│  workflowTemplate: Beroep workflow                      │
│  inherited dossier via caseDocument references          │
└─────────────────────────────────────────────────────────┘
```

Three design principles:

1. **No custom state machine.** AWB lifecycle declared as
   `x-openregister-lifecycle` on caseType / workflowTemplate; no
   `BezwaarService::transition()` in `lib/`.
2. **No custom deadline calculator.** AWB termijnen (6-week base,
   verdaging +6 weeks, opschorting pause) declared as
   `x-openregister-calculations` on the caseType; `extensionCount`
   on `case` tracks verdagingen.
3. **No parallel hearing database.** `hearingSession` is the single
   OR-backed entity; Nextcloud Calendar is a transport — the canonical
   record lives in OR.

## AWB process steps (bezwaar statusType sequence)

| Order | statusType name | AWB article | isFinal | Guard to advance |
|---|---|---|---|---|
| 1 | Ontvangen | 6:1, 6:7 | No | Bezwaarschrift registered (objection record created) |
| 2 | Ontvankelijkheidstoets | 6:6, 7:1 | No | Ontvankelijkheidsbeoordeling completed |
| 3 | Hoorzitting plannen | 7:2 | No | Ontvankelijk = true |
| 4 | Hoorzitting | 7:2–7:9 | No | hearingSession record exists and scheduled |
| 5 | Advies commissie | 7:13 | No | hearingSession.status = completed OR waiver granted |
| 6 | Beslissing op bezwaar | 7:11–7:12 | No | advisoryReport exists (commissie track) OR hearing waived |
| 7 | Bekendmaking | 3:41–3:45 | Yes | appealDecision record exists and decisionDate set |
| — | Niet-ontvankelijk verklaard | 6:6 | Yes | Terminal: ontvankelijk = false |
| — | Ingetrokken | — | Yes | Terminal: bezwaarmaker withdraws objection |

Note: Hoorzitting plannen → Hoorzitting requires `hearingSession.scheduledDate`
to be set. Advies commissie is only mandatory when a bezwaarschriften-
commissie is installed (`caseType.referenceProcess` declares commissie
track); otherwise step 5 is skipped and the workflow goes directly to
Beslissing op bezwaar.

## workflowTemplate design (bezwaar)

The bezwaar `workflowTemplate` declares:

**Steps** (mapped 1-to-1 to the statusType sequence):

```json
[
  {
    "id": "<uuid-step-1>",
    "title": "Ontvangst bezwaarschrift",
    "status": "<uuid-statustype-ontvangen>",
    "order": 1,
    "isRequired": true,
    "checklist": [
      {"id": "c1", "label": "Bezwaarschrift ontvangen en geregistreerd"},
      {"id": "c2", "label": "Ontvangstbevestiging verstuurd naar bezwaarmaker"},
      {"id": "c3", "label": "Primair besluit gekoppeld"}
    ],
    "automaticActions": [
      {"type": "createTask", "title": "Registreer bezwaarschrift", "assigneeRole": "<uuid-behandelaar>"},
      {"type": "setField", "field": "startDate", "value": "{{today}}"}
    ]
  },
  {
    "id": "<uuid-step-2>",
    "title": "Ontvankelijkheidstoets",
    "status": "<uuid-statustype-ontvankelijkheidstoets>",
    "order": 2,
    "isRequired": true,
    "checklist": [
      {"id": "c1", "label": "Termijn getoetst (AWB art. 6:7 — 6 weken)"},
      {"id": "c2", "label": "Bezwaar ingediend door belanghebbende (art. 1:2)"},
      {"id": "c3", "label": "Bezwaar gericht tegen een besluit (art. 1:3)"},
      {"id": "c4", "label": "Oordeel vastgelegd in zaak"}
    ]
  }
]
```

**Transitions** (key guards):

| From | To | Guard | automaticAction |
|---|---|---|---|
| Ontvangen | Ontvankelijkheidstoets | objection.receivedDate set | sendEmail: ontvangstbevestiging |
| Ontvankelijkheidstoets | Hoorzitting plannen | objection.isTimely = true AND objection record complete | notify behandelaar |
| Ontvankelijkheidstoets | Niet-ontvankelijk verklaard | objection.isTimely = false OR not a decision | createTask: beslissing niet-ontvankelijk |
| Hoorzitting plannen | Hoorzitting | hearingSession exists with scheduledDate in future | sendEmail: uitnodigingen hoorzitting |
| Hoorzitting | Advies commissie | hearingSession.status = completed OR hearingWaived = true | notify commissievoorzitter |
| Advies commissie | Beslissing op bezwaar | advisoryReport.adviceDate set | createTask: opstellen beslissing |
| Beslissing op bezwaar | Bekendmaking | appealDecision.decisionDate set | sendEmail: beslissing aan bezwaarmaker |
| Bekendmaking | (terminal) | — | setField: endDate = today |

## Entities used (from ADR-000 — no new entities introduced)

| Entity | Role in bezwaar/beroep workflow |
|---|---|
| `case` | The bezwaar case itself; `parentCase` for deelzaak model; `relatedCases` links to primair besluit and beroep |
| `caseType` | Defines `processingDeadline: "P6W"`, `extensionAllowed`, `extensionPeriod: "P6W"`, `suspensionAllowed` |
| `workflowTemplate` | The AWB-compliant step/transition/guard definition |
| `statusType` | The 7 named steps (+ 2 terminal statuses) |
| `objection` | The formal bezwaarschrift, linked to the contested `decision` |
| `hearingSession` | Hoorzitting scheduling, invitees, minutes (verslag) |
| `advisoryReport` | Bezwaarschriftencommissie advice record (AWB art. 7:13) |
| `appealDecision` | Beslissing op bezwaar with disposition, motivation, rechtsmiddelenclausule |
| `role` / `roleType` | Bezwaarmaker, Behandelaar, Commissievoorzitter, Commissielid, Vertegenwoordiger |
| `document` / `caseDocument` | Dossier documents (bezwaarschrift, verweerschrift, verslag, advies, beslissing) |
| `decision` | The primair besluit being contested (existing entity in the original case) |
| `propertyDefinition` | AWB-specific custom fields (verdagingReden, opschorting dates) |
| `resultType` / `result` | Outcome types: gegrond, ongegrond, niet-ontvankelijk, beroep ingesteld |
| `decisionType` | Gegrond, Ongegrond, Niet-ontvankelijk, Gedeeltelijk gegrond |
| `documentType` | Required document types per caseType |
| `task` | Per-step tasks generated by workflowTemplate automaticActions |

## Seed data

### caseType — Bezwaarschrift behandeling

```json
{
  "title": "Bezwaarschrift behandeling",
  "description": "Behandeling van bezwaarschriften conform de Algemene wet bestuursrecht (AWB). De bezwaarmaker heeft 6 weken na de bekendmaking van het primaire besluit om bezwaar in te dienen.",
  "identifier": "bezwaar",
  "purpose": "Heroverweging van een genomen besluit naar aanleiding van een ingediend bezwaarschrift",
  "trigger": "Ontvangst bezwaarschrift van een belanghebbende (AWB art. 6:4 lid 1)",
  "processingDeadline": "P6W",
  "extensionAllowed": true,
  "extensionPeriod": "P6W",
  "suspensionAllowed": true,
  "publicationRequired": true,
  "internalOrExternal": "extern",
  "origin": "indienen",
  "confidentiality": "vertrouwelijk"
}
```

### caseType — Beroepschrift behandeling

```json
{
  "title": "Beroepschrift behandeling",
  "description": "Ondersteuning bij de behandeling van beroepszaken bij de bestuursrechter (AWB afdeling 8.1). Beheert het beroepsdossier, de procesgang, en het exporteren van stukken naar de rechtbank.",
  "identifier": "beroep",
  "purpose": "Beheer van beroepsprocedure na ongegrond of niet-ontvankelijk verklaard bezwaar",
  "trigger": "Instellen beroep door appellant bij de bestuursrechter (AWB art. 8:1)",
  "processingDeadline": "P52W",
  "extensionAllowed": false,
  "suspensionAllowed": false,
  "publicationRequired": false,
  "internalOrExternal": "extern",
  "origin": "indienen",
  "confidentiality": "vertrouwelijk"
}
```

### statusType seeds (bezwaar — ordered sequence)

```json
[
  {"name": "Ontvangen", "description": "Bezwaarschrift is ontvangen en geregistreerd. Ontvangstbevestiging wordt verstuurd.", "order": 1, "isFinal": false},
  {"name": "Ontvankelijkheidstoets", "description": "Beoordeling of het bezwaar tijdig en door een belanghebbende is ingediend tegen een voor bezwaar vatbaar besluit (AWB art. 6:6).", "order": 2, "isFinal": false},
  {"name": "Hoorzitting plannen", "description": "Plannen van de hoorzitting. Uitnodigingen aan bezwaarmaker, vertegenwoordiger en commissieleden worden verstuurd.", "order": 3, "isFinal": false},
  {"name": "Hoorzitting", "description": "De hoorzitting heeft plaatsgevonden of is waived. Verslag wordt opgesteld (AWB art. 7:7).", "order": 4, "isFinal": false},
  {"name": "Advies commissie", "description": "De bezwaarschriftencommissie stelt haar advies op conform AWB art. 7:13.", "order": 5, "isFinal": false},
  {"name": "Beslissing op bezwaar", "description": "Bestuursorgaan neemt beslissing op het bezwaar met motivering (AWB art. 7:12) en rechtsmiddelenclausule (art. 7:11).", "order": 6, "isFinal": false},
  {"name": "Bekendmaking", "description": "Beslissing is bekend gemaakt aan alle partijen conform AWB art. 3:41.", "order": 7, "isFinal": true},
  {"name": "Niet-ontvankelijk verklaard", "description": "Bezwaar is niet-ontvankelijk verklaard (te laat, niet door belanghebbende, of niet tegen een besluit).", "order": 99, "isFinal": true},
  {"name": "Ingetrokken", "description": "Bezwaarmaker heeft het bezwaar ingetrokken.", "order": 98, "isFinal": true}
]
```

### roleType seeds (bezwaar)

```json
[
  {"name": "Bezwaarmaker", "description": "De natuurlijke persoon of rechtspersoon die het bezwaarschrift heeft ingediend (AWB art. 1:2)"},
  {"name": "Vertegenwoordiger", "description": "Gemachtigde of advocaat die namens de bezwaarmaker optreedt"},
  {"name": "Behandelaar", "description": "Medewerker Juridische Zaken verantwoordelijk voor de behandeling van het bezwaar"},
  {"name": "Commissievoorzitter", "description": "Voorzitter van de bezwaarschriftencommissie (AWB art. 7:13 lid 2)"},
  {"name": "Commissielid", "description": "Lid van de bezwaarschriftencommissie"}
]
```

### hearingSession — example seed objects

```json
[
  {
    "scheduledDate": "2026-06-15T10:00:00",
    "location": "Stadhuis, Vergaderzaal Maas, Grote Markt 1, Rotterdam",
    "chairperson": "<uuid-rol-commissievoorzitter>",
    "invitees": "[{\"name\": \"Jan Pietersen\", \"role\": \"Bezwaarmaker\", \"email\": \"j.pietersen@example.nl\", \"status\": \"uitgenodigd\"}, {\"name\": \"Mr. A. de Groot\", \"role\": \"Vertegenwoordiger\", \"email\": \"a.degroot@advocaten.nl\", \"status\": \"uitgenodigd\"}, {\"name\": \"H. van Dam\", \"role\": \"Behandelaar\", \"email\": \"h.vandam@rotterdam.nl\", \"status\": \"bevestigd\"}]",
    "status": "gepland",
    "hearingWaived": false
  },
  {
    "scheduledDate": "2026-07-03T14:00:00",
    "location": "Online",
    "videoCallUrl": "https://meet.gemeente.nl/hoorzitting-2026-BZW-0089",
    "chairperson": "<uuid-rol-commissievoorzitter>",
    "invitees": "[{\"name\": \"Stichting Natuur Behoud\", \"role\": \"Bezwaarmaker\", \"email\": \"info@stichtingnatuur.nl\", \"status\": \"uitgenodigd\"}]",
    "minutesSummary": "Bezwaarmaker heeft bezwaar gehandhaafd. Gemeente heeft standpunt toegelicht. Geen nieuwe feiten naar voren gebracht.",
    "status": "afgerond",
    "hearingWaived": false
  },
  {
    "scheduledDate": "2026-05-28T09:30:00",
    "location": "Gemeentehuis, Kamer 12, Molenstraat 5, Delft",
    "chairperson": "<uuid-rol-commissievoorzitter>",
    "invitees": "[{\"name\": \"BV Bouwbedrijf Noord\", \"role\": \"Bezwaarmaker\", \"email\": \"info@bouwbedrijfnoord.nl\", \"status\": \"uitgenodigd\"}]",
    "status": "gepland",
    "hearingWaived": false
  },
  {
    "scheduledDate": "2026-04-10T11:00:00",
    "location": "Niet van toepassing — afstandsverklaring",
    "chairperson": "<uuid-rol-behandelaar>",
    "invitees": "[]",
    "status": "afgerond",
    "hearingWaived": true,
    "waiverReason": "Bezwaarmaker heeft schriftelijk afstand gedaan van het recht om gehoord te worden (AWB art. 7:3 sub c)"
  }
]
```

### objection — example seed objects

```json
[
  {
    "grounds": "De verleende omgevingsvergunning voor uitbreiding van de bedrijfshal is in strijd met het bestemmingsplan 'Bedrijventerrein Noord 2021', artikel 4.2.1. Het bouwvlak wordt overschreden met 12 meter. Tevens heeft verweerder de belangen van omwonenden onvoldoende meegewogen.",
    "requestedRelief": "Herroeping van de vergunning en weigering van de aanvraag",
    "receivedDate": "2026-04-22",
    "receivedChannel": "digitaal-loket",
    "isTimely": true,
    "timelinessAssessment": "Besluit bekend gemaakt op 11 maart 2026. Bezwaar ontvangen op 22 april 2026. Termijn: 6 weken = 22 april 2026. Tijdig ingediend.",
    "proVoorziening": false
  },
  {
    "grounds": "De afwijzing van de bijstandsaanvraag is onterecht. Verzoekers inkomen uit verhuur betreft een tijdelijke situatie die reeds geëindigd is. De berekening van de vermogensgrens is onjuist.",
    "requestedRelief": "Toekenning van bijstandsuitkering met terugwerkende kracht vanaf 1 maart 2026",
    "receivedDate": "2026-05-05",
    "receivedChannel": "post",
    "isTimely": true,
    "timelinessAssessment": "Besluit gedateerd 24 maart 2026, aangetekend verzonden. Bezwaar per aangetekende post ontvangen op 5 mei 2026. Termijn verloopt op 5 mei 2026. Tijdig.",
    "proVoorziening": true,
    "attachments": "[\"doc-loonstroken-2026.pdf\", \"doc-huurcontract-beeindigd.pdf\"]"
  },
  {
    "grounds": "Het besluit tot intrekking van de horecavergunning is disproportioneel. De geconstateerde overtreding (1 incident op 14 februari) rechtvaardigt geen intrekking. Er is geen sprake van recidive en verzoekers hebben direct corrigerende maatregelen getroffen.",
    "requestedRelief": "Schorsing van het intrekkingsbesluit en heroverweging",
    "receivedDate": "2026-03-18",
    "receivedChannel": "email",
    "isTimely": true,
    "proVoorziening": true
  }
]
```

### advisoryReport — example seed objects

```json
[
  {
    "committeeChair": "<uuid-rol-commissievoorzitter>",
    "committeeMembers": "[\"<uuid-lid-1>\", \"<uuid-lid-2>\"]",
    "adviceDate": "2026-07-14",
    "adviceType": "gegrond",
    "summary": "De commissie adviseert het bezwaar gegrond te verklaren. De verleende omgevingsvergunning is in strijd met het bestemmingsplan. Het bouwvlak wordt aantoonbaar overschreden.",
    "grounds": "Op grond van artikel 2.10 lid 1 sub c Wabo moet een omgevingsvergunning voor bouwen worden geweigerd indien het bouwplan in strijd is met het bestemmingsplan. De commissie stelt vast dat het bouwplan het bouwvlak overschrijdt met 12 meter zoals door bezwaarmaker aangetoond met kadastrale gegevens en het bestemmingsplan.",
    "recommendation": "Herroep de omgevingsvergunning en weiger de aanvraag opnieuw. Informeer bezwaarmaker en aanvrager conform AWB art. 3:41.",
    "deviationFromPrimaryDecision": true
  },
  {
    "committeeChair": "<uuid-rol-commissievoorzitter>",
    "committeeMembers": "[\"<uuid-lid-1>\"]",
    "adviceDate": "2026-06-30",
    "adviceType": "ongegrond",
    "summary": "De commissie adviseert het bezwaar ongegrond te verklaren. De afwijzing van de bijstandsaanvraag is juist gemotiveerd. Het vermogen overschreed ten tijde van de aanvraag de grens.",
    "grounds": "Het door bezwaarmaker gestelde inkomen uit verhuur is geen tijdelijk inkomen in de zin van artikel 31 lid 2 sub n PW. De huurovereenkomst liep ten tijde van de aanvraag nog. De vermogensvaststelling door de gemeente is conform regelgeving uitgevoerd.",
    "recommendation": "Handhaaf het primaire besluit.",
    "deviationFromPrimaryDecision": false
  }
]
```

### appealDecision — example seed objects

```json
[
  {
    "dispositionType": "gegrond",
    "dispositionDetails": "Het college van burgemeester en wethouders verklaart het bezwaar gegrond. De omgevingsvergunning van 11 maart 2026 (kenmerk OGV-2026-0117) wordt herroepen. Het bouwplan overschrijdt het bestemmingsplanvlak met 12 meter hetgeen op grond van artikel 2.10 lid 1 sub c Wabo een dwingende weigeringsgrond is. Het bezwaar van de heer J. Pietersen treft doel.",
    "followsAdvice": true,
    "remedialAction": "De aanvraag voor de omgevingsvergunning wordt opnieuw beoordeeld met inachtneming van de bestemmingsplangrens.",
    "decisionDate": "2026-07-28",
    "effectiveDate": "2026-07-28",
    "appealInformation": "Tegen dit besluit kunt u binnen zes weken na de dag van verzending beroep instellen bij de Rechtbank Rotterdam, Afdeling Bestuursrecht, Postbus 50951, 3007 BM Rotterdam.",
    "decisionMaker": "College van Burgemeester en Wethouders gemeente Rotterdam"
  },
  {
    "dispositionType": "ongegrond",
    "dispositionDetails": "Het college verklaart het bezwaar ongegrond. De afwijzing van de bijstandsaanvraag van mevrouw S. Bakker van 24 maart 2026 blijft in stand. Het vermogen van bezwaarmaker overschreed ten tijde van de aanvraag de vermogensgrens als bedoeld in artikel 34 PW. De commissie heeft dit eveneens geconcludeerd.",
    "followsAdvice": true,
    "decisionDate": "2026-07-15",
    "effectiveDate": "2026-07-15",
    "appealInformation": "Tegen dit besluit kunt u binnen zes weken na de dag van verzending beroep instellen bij de Rechtbank Den Haag, Afdeling Bestuursrecht, Postbus 20302, 2500 EH Den Haag.",
    "decisionMaker": "Het college van burgemeester en wethouders van gemeente Delft"
  },
  {
    "dispositionType": "niet_ontvankelijk",
    "dispositionDetails": "Het bezwaar is niet-ontvankelijk. Het bezwaarschrift is ingediend op 12 juni 2026. Het bestreden besluit is bekendgemaakt op 29 april 2026. De bezwaartermijn bedraagt zes weken en eindigde op 10 juni 2026. Het bezwaar is derhalve twee dagen te laat ingediend. Bijzondere omstandigheden die verschoonbare termijnoverschrijding zouden rechtvaardigen zijn niet gesteld noch gebleken.",
    "followsAdvice": false,
    "deviationReason": "Geen commissieadvies vereist bij niet-ontvankelijkverklaring wegens termijnoverschrijding",
    "decisionDate": "2026-06-20",
    "effectiveDate": "2026-06-20",
    "appealInformation": "Tegen dit besluit kunt u binnen zes weken beroep instellen bij de rechtbank.",
    "decisionMaker": "College van Burgemeester en Wethouders gemeente Utrecht"
  }
]
```

## OR abstraction usage

| Abstraction | Bezwaar workflow | Beroep workflow |
|---|---|---|
| Registers + schemas + objects | ✓ | ✓ |
| RBAC (authorization) | ✓ | ✓ |
| Audit trail (immutable) | ✓ | ✓ |
| Archival + destruction (retention) | ✓ | ✓ |
| `x-openregister-lifecycle` | ✓ (caseType + workflowTemplate) | ✓ |
| `x-openregister-calculations` | ✓ (deadline, verdaging, opschorting) | – |
| `x-openregister-notifications` | ✓ (uitnodigingen, beslissing) | ✓ (dagvaarding ontvangen) |
| `x-openregister-relations` | ✓ (primair besluit link) | ✓ (bezwaar + primair besluit) |
| `x-openregister-aggregations` | – | – |
| `x-openregister-widgets` | – | – |
| Nextcloud Calendar (transport) | ✓ (hearingSession invitations) | – |
| Events + webhooks (CloudEvents) | ✓ | ✓ |

## Declarative vs imperative classification

**Declarative (in seed data / workflowTemplate JSON):**
- Status lifecycle: all 7 steps + 2 terminal states declared in workflowTemplate transitions
- AWB deadline: `processingDeadline`, `extensionAllowed`, `extensionPeriod`, `suspensionAllowed` on caseType
- Verdaging/opschorting tracking: `extensionCount` on case, propertyDefinition for reden + dates
- Guards: checklist items on workflowTemplate steps, requiredField guards on transitions
- Automatic actions: sendEmail, createTask, setField in workflowTemplate transition actions

**Imperative (targeted code extensions — code chain `bezwaar-beroep-workflow-code`):**
- `BezwaarCreationHook`: fires on case creation of caseType bezwaar, sets `relatedCases` reference to the primair besluit case
- `HoorzittingCalendarSync`: fires on hearingSession POST/PUT, creates/updates Nextcloud Calendar event and sends ICS invitations
- `DossierCompiler`: assembles ordered caseDocument references from original case + bezwaar case into a dossier view
- `BeroepDossierExport`: produces a ZIP of dossier documents for court submission; delegates PDF merging to docudesk if available

No `BezwaarService::transition()`, `TermijnService`, `OpschortingCalculator`, or `CommissieNotificationService` class is authored. All lifecycle behaviour is workflowTemplate-driven.

## AWB deadline calculation rules (declared, not coded)

```
AWB beslissingstermijn (art. 7:10):
  base:      P6W from ontvangst bezwaarschrift
  verdaging: + P6W (eenmalig, schriftelijk mededelen aan partijen)
  opschorting: pause while
    - bezwaarmaker in gebreke (nadere informatie, art. 7:10 lid 3)
    - pro-forma bezwaar + motiveringsverzoek
    - onderling overleg (instemming partijen)

Declared as:
  caseType.processingDeadline = "P6W"
  caseType.extensionAllowed   = true
  caseType.extensionPeriod    = "P6W"
  caseType.suspensionAllowed  = true
  case.extensionCount         = tracks number of verdagingen applied
  propertyDefinition[verdagingReden]       = text, optional
  propertyDefinition[opschortingReden]     = text, optional
  propertyDefinition[opschortingStartDatum]= date, optional
  propertyDefinition[opschortingEindDatum] = date, optional
```

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Commissie track is optional but workflow must support both tracks | workflowTemplate transition from Hoorzitting → Advies commissie has guard `commissieTrack = true`; if false, transition goes directly to Beslissing op bezwaar |
| Hearing right can be waived (AWB art. 7:3) | `hearingSession.hearingWaived` boolean + `waiverReason` text; workflow guard accepts waiver as valid advance condition |
| Calendar sync failure should not block hearing record | `HoorzittingCalendarSync` is best-effort; hearingSession record is created regardless; calendar failure logged in audit trail |
| Beroep dossier export needs docudesk for PDF merge | Export always produces ZIP; PDF merge is conditional on docudesk availability; declared in code chain spec |
| Multiple verdagingen could exceed legal maximum | `extensionCount` guard on workflowTemplate: warn if extensionCount > 1 (AWB allows one verdaging of 6 weeks) |

## See also

- ADR-022: apps consume OR abstractions
- ADR-031: schema-declarative business logic
- ADR-024: manifest navigation
- `workflow-engine-enhancement`: the generic engine this change configures
- `signalering-widgets`: where AWB deadline alert rendering lives
- AWB art. 6:4 (bezwaarschrift), 6:7 (termijn), 7:2 (hoorrecht), 7:10 (beslissingstermijn), 7:11-7:12 (beslissing + motivering), 7:13 (commissieadvies), 3:41-3:45 (bekendmaking)
