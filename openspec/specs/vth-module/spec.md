# VTH Module Specification

## Purpose

The VTH (Vergunningen, Toezicht, Handhaving) module extends Procest with domain-specific capabilities for permit management, supervision, and enforcement. VTH processes are the most complex case management domain in Dutch municipalities, involving DSO/Omgevingsloket integration, configurable inspection checklists, enforcement strategies (Landelijke Handhavingsstrategie), supervision planning, and mobile inspection support.

**Tender demand**: 29% of tenders (20/69) explicitly require VTH capabilities. VTH tenders are high-value: they represent large municipalities and regional enforcement agencies (omgevingsdiensten) with budgets of EUR 500K-2M+.
**Standards**: Omgevingswet, GEMMA VTH-referentiecomponenten, DSO (Digitaal Stelsel Omgevingswet), StUF-LVO, ZGW APIs, Landelijke Handhavingsstrategie (LHS), IPPC (Integrated Pollution Prevention Control)
**Feature tier**: V1 (DSO intake, permit workflow, basic checklists, advice management), V2 (enforcement strategies, supervision planning, mobile inspection, LHS matrix, risk-based scheduling)

**Competitive context**: Dimpact ZAC handles VTH through its generic case model with zaaktype-specific configuration (zaakafhandelparameters). ZAC does not have built-in inspection checklists or LHS matrix support. Flowable can model VTH processes via CMMN/BPMN with configurable task forms. XXllnc Zaken and Mozard are dedicated VTH systems with deep DSO integration. Procest should implement VTH as case type extensions (not a separate module), leveraging the existing case infrastructure with VTH-specific case types, document types, and property definitions.

## VTH Process Overview

```
Vergunningen (Permits):
  DSO/aanvraag -> Ontvankelijkheidstoets -> Inhoudelijke toets -> Advies -> Besluit -> Bekendmaking

Toezicht (Supervision):
  Toezichtplan -> Inspectie plannen -> Inspectie uitvoeren -> Rapport -> Opvolging

Handhaving (Enforcement):
  Constatering -> Vooraankondiging -> Zienswijze -> Handhavingsbesluit -> Uitvoering -> Controle
```

### VTH Case Types (OpenRegister Configuration)

```
VTH case types (configured via caseType schema):

Vergunningen:
  - Omgevingsvergunning Bouwactiviteit (regulier: 8 weken, uitgebreid: 26 weken)
  - Omgevingsvergunning Milieubelastende activiteit (regulier/uitgebreid)
  - Omgevingsvergunning Monumenten
  - Sloopmelding (4 weken)
  - Milieumelding (Activiteitenbesluit)
  - Gebruiksmelding brandveiligheid

Toezicht:
  - Toezichtzaak Bouw (fases: fundering, ruwbouw, oplevering)
  - Toezichtzaak Milieu (periodiek, incidenteel)
  - Toezichtzaak Brandveiligheid
  - Toezichtzaak Horeca/Evenementen

Handhaving:
  - Handhavingszaak (vooraankondiging, last onder dwangsom, bestuursdwang)
  - Invorderingszaak (verbeuring dwangsom)
```

### VTH-Specific OpenRegister Schemas

```
inspectieChecklist:
  name: string              # "Bouwtoezicht fase 1 - Fundering"
  caseType: reference       # -> caseType
  version: integer          # 1, 2, 3...
  status: enum              # draft | active | archived
  items: array              # -> checklistItem[]

checklistItem:
  order: integer
  label: string             # "Fundering conform tekening"
  type: enum                # ja_nee_nvt | tekst | getal | foto | meerkeuze
  required: boolean
  fotoRequired: boolean
  options: array            # for meerkeuze type
  helpText: string          # guidance for inspector

inspectieRapport:
  case: reference           # -> case (toezichtzaak)
  checklist: reference      # -> inspectieChecklist
  inspector: string         # user UID
  inspectionDate: datetime
  location: string          # GPS coordinates or address
  result: enum              # conform | niet_conform | deels_conform
  failedItems: integer      # count of failed checklist items
  items: array              # -> completed checklistItem results
  photos: array             # Nextcloud file IDs
  remarks: string
  followUpRequired: boolean

handhavingsactie:
  case: reference           # -> case (handhavingszaak)
  type: enum                # waarschuwing | vooraankondiging | last_onder_dwangsom | bestuursdwang | proces_verbaal
  ernst: enum               # gering | aanzienlijk | ernstig
  gedrag: enum              # goedwillend | onverschillig | calculerend | crimineel
  interventie: string       # suggested by LHS matrix
  begunstigingstermijn: integer  # days
  dwangsomBedrag: decimal
  dwangsomMaximaal: decimal
  effectueringsDatum: date
  status: enum              # opgelegd | verbeurd | geeffectueerd | ingetrokken

adviesAanvraag:
  case: reference           # -> case
  adviseur: string          # user UID or external organization name
  type: enum                # intern | extern
  onderwerp: string
  deadline: date
  status: enum              # aangevraagd | ontvangen | verlopen
  adviesDocument: string    # Nextcloud file ID
  requestedAt: datetime
  receivedAt: datetime
```

## Requirements

---

### REQ-VTH-01: DSO/Omgevingsloket Integration

The system MUST support receiving vergunningaanvragen from the Digitaal Stelsel Omgevingswet (DSO) and creating cases from them.

**Feature tier**: V1


#### Scenario VTH-01a: DSO application creates permit case

- GIVEN DSO integration is configured via OpenConnector
- WHEN a citizen submits an omgevingsvergunning aanvraag via the Omgevingsloket
- AND the DSO forwards the verzoek to the municipality's VTH system
- THEN the system MUST create a case of type "Omgevingsvergunning Bouwactiviteit"
- AND the case MUST include: activiteiten (from DSO), locatie (BAG/kadaster), aanvrager gegevens, bijlagen
- AND bouwkosten from the DSO verzoek MUST be stored as a case property (used for legesberekening)
- AND the case deadline MUST be calculated per the Omgevingswet procedure type (regulier: 8 weeks, uitgebreid: 26 weeks)
- AND the case MUST be automatically assigned to the configured team for this case type

#### Scenario VTH-01b: Multiple activities in single application

- GIVEN a DSO verzoek with 3 activities: "Bouwen", "Kappen", "Uitrit aanleggen"
- WHEN the case is created
- THEN the system MUST register all 3 activities on the case as case properties
- AND each activity MAY trigger a separate toets/advies task
- AND legesberekening MUST consider samenloop (combined activities, see legesberekening spec)

#### Scenario VTH-01c: DSO status updates

- GIVEN a case created from a DSO verzoek
- WHEN the case status changes (e.g., from "Ontvangen" to "In behandeling")
- THEN the system MUST push a status update back to the DSO via OpenConnector
- AND the aanvrager MUST be able to see the current status in the Omgevingsloket

#### Scenario VTH-01d: DSO aanvulverzoek (request for additional information)

- GIVEN a case where the ontvankelijkheidstoets reveals missing documents
- WHEN the behandelaar sends an aanvulverzoek via the case dashboard
- THEN the system MUST push the aanvulverzoek to the DSO
- AND the case deadline MUST be suspended (opgeschort) during the aanvulperiode
- AND the suspension MUST be recorded in the case timeline

#### Scenario VTH-01e: DSO intake validation

- GIVEN a DSO verzoek arrives with invalid or incomplete data
- WHEN the system attempts to create a case
- THEN validation errors MUST be logged: "DSO verzoek [id]: ontbrekend veld 'bouwkosten'"
- AND the case MUST still be created (with incomplete data) for manual completion
- AND a task MUST be created for the behandelaar: "DSO intake validatie: controleer ontbrekende gegevens"

---

### REQ-VTH-02: Ontvankelijkheidstoets (Completeness Check)

The system MUST support a structured completeness check for permit applications, using document type checklists per case type.

**Feature tier**: V1


#### Scenario VTH-02a: Incomplete application detected

- GIVEN a case "Omgevingsvergunning Bouw" with document checklist requiring: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's bestaande situatie
- AND only bouwtekening and foto's have been uploaded
- WHEN the behandelaar performs the ontvankelijkheidstoets via the case dashboard
- THEN the system MUST display which required documents are missing (3 of 5)
- AND each missing document MUST be highlighted with a warning icon
- AND the system MUST support sending an "Aanvulverzoek" to the aanvrager (via DSO or email)

#### Scenario VTH-02b: Complete application

- GIVEN all required documents have been uploaded (5 of 5)
- WHEN the behandelaar performs the ontvankelijkheidstoets
- THEN the system MUST display "Ontvankelijk" with all checkmarks green
- AND the system MUST allow the behandelaar to confirm ontvankelijkheid and advance the case status

#### Scenario VTH-02c: Deadline suspension during aanvulperiode

- GIVEN a case with deadline of 8 weeks from start date 2026-03-01 (deadline: 2026-04-26)
- AND the behandelaar sends an aanvulverzoek on 2026-03-10
- AND the aanvrager responds with additional documents on 2026-03-25
- WHEN the behandelaar marks the aanvulverzoek as completed
- THEN the case deadline MUST be recalculated: 15 days were suspended, new deadline: 2026-05-11
- AND the suspension period MUST be recorded in the case timeline and deadline panel

#### Scenario VTH-02d: Aanvulverzoek timeout

- GIVEN an aanvulverzoek was sent with a 4-week response deadline
- AND the aanvrager has not responded within 4 weeks
- WHEN the timeout is reached
- THEN a task MUST be created for the behandelaar: "Aanvulverzoek verlopen: beoordeel of aanvraag buiten behandeling wordt gesteld"
- AND the teamleider MUST receive a notification

---

### REQ-VTH-03: Inspection Checklists

The system MUST support configurable inspection checklists per case type or inspection type, stored as `inspectieChecklist` objects in OpenRegister.

**Feature tier**: V1


#### Scenario VTH-03a: Configure inspection checklist

- GIVEN an admin configuring checklist "Bouwtoezicht fase 1 - Fundering" with items:
  - "Fundering conform tekening" (ja/nee/nvt + toelichting)
  - "Wapening aanwezig en correct" (ja/nee/nvt + toelichting)
  - "Waterkering conform bestek" (ja/nee/nvt + toelichting + foto verplicht)
  - "Maatvoering gecontroleerd" (ja/nee/nvt + getal: afwijking in mm)
- WHEN the checklist is saved
- THEN the checklist MUST be linked to case type "Toezichtzaak Bouw"
- AND each item MUST support: pass/fail/not-applicable, free text comment, optional photo requirement, optional numeric measurement
- AND the checklist MUST be versioned (v1, v2, ...) so in-progress inspections use their original version

#### Scenario VTH-03b: Complete inspection checklist

- GIVEN an inspector performing "Bouwtoezicht fase 1" on case "2026-089"
- WHEN the inspector fills in all checklist items and marks 2 items as "nee" (failed)
- THEN the system MUST record an `inspectieRapport`: inspector name, date/time, location, result per item
- AND the overall result MUST be automatically determined: "Niet-conform" (2 failed items)
- AND the completed rapport MUST be stored as a document on the case
- AND a summary task MUST be generated: "Opvolging vereist: 2 afwijkingen geconstateerd"

#### Scenario VTH-03c: Photo capture during inspection

- GIVEN a checklist item "Waterkering conform bestek" with fotoRequired=true
- WHEN the inspector marks this item as "nee" (failed)
- THEN the system MUST require at least one photo before the rapport can be submitted
- AND photos MUST be stored in Nextcloud Files under the case folder
- AND each photo MUST be linked to the specific checklist item

#### Scenario VTH-03d: Multiple inspections per case

- GIVEN a bouwtoezicht case with 3 inspection phases: fundering, ruwbouw, oplevering
- WHEN the inspector completes "fase 1 - fundering"
- THEN the completed rapport MUST be stored and the next inspection ("fase 2 - ruwbouw") MUST become available
- AND the case dashboard MUST show inspection progress: "Inspectie 1/3 voltooid"

#### Scenario VTH-03e: Inspection history

- GIVEN a case with 3 completed inspection rapporten
- WHEN the behandelaar views the case dashboard
- THEN the "Inspecties" panel MUST show all 3 rapporten: date, inspector, result (conform/niet-conform), failed item count
- AND each rapport MUST be expandable to view individual checklist item results

---

### REQ-VTH-04: Enforcement Strategies (Handhaving)

The system SHALL support the Landelijke Handhavingsstrategie (LHS) for determining appropriate enforcement actions.

**Feature tier**: V2


#### Scenario VTH-04a: LHS matrix application

- GIVEN a constatering of type "Overtreding bouwvergunning"
- AND the inspector classifies: ernst = "aanzienlijk", gedrag = "onverschillig"
- WHEN the LHS matrix is applied via the enforcement wizard
- THEN the system MUST suggest the appropriate interventie: "Last onder dwangsom + proces-verbaal"
- AND the behandelaar MAY override the suggestion with documented reasoning
- AND the override MUST be recorded in the audit trail

#### Scenario VTH-04b: Enforcement workflow

- GIVEN a handhavingsbesluit "Last onder dwangsom" with begunstigingstermijn of 6 weeks
- AND dwangsom bedrag EUR 5,000 per overtreding, maximaal EUR 25,000
- WHEN the begunstigingstermijn expires
- THEN the system MUST create a follow-up task: "Hercontrole uitvoeren"
- AND if the overtreding persists, the system MUST support: verbeuring dwangsom, effectuering bestuursdwang
- AND each verbeuring MUST be recorded with amount and date

#### Scenario VTH-04c: Vooraankondiging workflow

- GIVEN a constatering requiring enforcement
- WHEN the behandelaar initiates a vooraankondiging
- THEN the system MUST:
  1. Generate a vooraankondigingsbrief via Docudesk template
  2. Set a zienswijzetermijn (typically 2 weeks)
  3. Create a task: "Beoordeel zienswijze na termijn"
- AND if a zienswijze is received, it MUST be recorded on the case
- AND the behandelaar MUST assess the zienswijze before proceeding to handhavingsbesluit

#### Scenario VTH-04d: LHS matrix configuration

- GIVEN the beheerder configures the LHS matrix in admin settings
- WHEN setting up the ernst x gedrag matrix
- THEN the system MUST support the standard 4x4 matrix:
  | | Goedwillend | Onverschillig | Calculerend | Crimineel |
  |---|---|---|---|---|
  | Gering | Waarschuwing | Waarschuwing + herstel | Last onder dwangsom | PV + Last |
  | Aanzienlijk | Herstel | Last onder dwangsom | Last + PV | PV + Bestuursdwang |
  | Ernstig | Last onder dwangsom | Last + PV | PV + Bestuursdwang | PV + Bestuursdwang |
- AND the matrix MUST be customizable per municipality

---

### REQ-VTH-05: Supervision Planning (Toezichtplan)

The system SHALL support creating and managing annual supervision plans.

**Feature tier**: V2


#### Scenario VTH-05a: Annual inspection planning

- GIVEN a toezichtplan for 2026 with 150 planned inspections across categories:
  - Horeca: 40 inspections
  - Bouw: 60 inspections
  - Milieu: 50 inspections
- WHEN the planner views the toezichtplan dashboard
- THEN the system MUST show: planned vs. completed per category, capacity vs. demand, completion percentage
- AND a progress bar per category MUST indicate completion: Horeca (15/40 = 38%), Bouw (22/60 = 37%), Milieu (18/50 = 36%)

#### Scenario VTH-05b: Risk-based inspection scheduling

- GIVEN objects with risk profiles (high/medium/low) based on previous inspection results
- WHEN generating the toezichtplan
- THEN high-risk objects MUST be scheduled more frequently (e.g., every 6 months)
- AND medium-risk objects: annually
- AND low-risk objects: every 2 years
- AND the system SHOULD suggest an optimal inspection schedule based on available inspector capacity

#### Scenario VTH-05c: Geographic clustering

- GIVEN 20 planned inspections across the municipality
- WHEN the planner views the inspection map
- THEN inspections MUST be plottable on a map (using BAG coordinates)
- AND the system SHOULD suggest geographic clusters for efficient routing

#### Scenario VTH-05d: Actual vs. planned tracking

- GIVEN a toezichtplan with 40 planned horeca inspections for Q1
- AND 25 have been completed, 5 have been cancelled
- WHEN viewing the quarterly overview
- THEN the system MUST show: planned (40), completed (25), cancelled (5), remaining (10)
- AND a forecast MUST indicate whether the annual target is achievable at current pace

---

### REQ-VTH-06: Advice Management (Advies)

The system MUST support requesting and tracking internal and external advice on permit applications.

**Feature tier**: V1


#### Scenario VTH-06a: Request internal advice from specialist

- GIVEN a case "Omgevingsvergunning Bouw" requiring welstandsadvies
- WHEN the behandelaar clicks "Advies aanvragen" and selects the welstandscommissie (internal)
- THEN an `adviesAanvraag` MUST be created: type=intern, adviseur=welstandscommissie, deadline=[configured default]
- AND a task MUST be created for the welstandscommissie: "Welstandsadvies uitbrengen voor [case identifier]"
- AND the task MUST include: deadline, relevant case documents, specific questions
- AND the case timeline MUST show: "Advies aangevraagd bij Welstandscommissie"

#### Scenario VTH-06b: External advice via ketenpartner

- GIVEN a case requiring advice from the brandweer (Veiligheidsregio)
- WHEN the behandelaar requests external advice
- THEN the system MUST support sending the request via email (with case documents attached) or ZGW API
- AND the adviesAanvraag MUST be created with type=extern and a deadline
- AND a reminder notification MUST be generated 3 days before the deadline
- AND late advice MUST trigger an escalation notification to the behandelaar and teamleider

#### Scenario VTH-06c: Receive and process advice

- GIVEN an adviesAanvraag is pending for the welstandscommissie
- WHEN the adviseur uploads the advies document and marks the request as "Ontvangen"
- THEN the adviesAanvraag status MUST change to "Ontvangen"
- AND the advies document MUST be linked to the case
- AND the behandelaar MUST be notified: "Welstandsadvies ontvangen voor [case identifier]"
- AND the case timeline MUST show: "Welstandsadvies ontvangen" with a link to the document

#### Scenario VTH-06d: Advice overview on case dashboard

- GIVEN a case with 3 adviesAanvragen: welstandscommissie (ontvangen), brandweer (aangevraagd, deadline in 5 days), milieuadvies (verlopen, 2 days overdue)
- WHEN viewing the case dashboard
- THEN an "Adviezen" panel MUST show all 3 with: adviseur, status badge (green/orange/red), deadline
- AND overdue advice MUST be highlighted in red with days overdue

#### Scenario VTH-06e: Advice deadline tracking

- GIVEN 5 open adviesAanvragen across multiple cases
- AND 2 are overdue
- WHEN the teamleider views the werkvoorraad
- THEN overdue advice requests MUST appear as a separate filter option: "Verlopen adviezen (2)"
- AND each overdue request MUST show: case reference, adviseur, days overdue

---

### REQ-VTH-07: Permit Procedure Deadlines

The system MUST enforce Omgevingswet procedure deadlines per permit type and support suspension and extension.

**Feature tier**: V1


#### Scenario VTH-07a: Reguliere procedure deadline

- GIVEN a case type "Omgevingsvergunning Bouwactiviteit" with procedure "regulier"
- AND the Omgevingswet reguliere beslistermijn is 8 weeks
- WHEN the case is created with startdatum 2026-03-01
- THEN the case deadline MUST be automatically set to 2026-04-26 (8 weeks)
- AND the deadline MUST be displayed in the case dashboard's deadline panel

#### Scenario VTH-07b: Uitgebreide procedure deadline

- GIVEN a case requiring "uitgebreide procedure" (e.g., milieu-impact)
- AND the Omgevingswet uitgebreide beslistermijn is 26 weeks
- WHEN the case is created
- THEN the case deadline MUST be set to startdatum + 26 weeks

#### Scenario VTH-07c: Extension (verlenging)

- GIVEN a case with reguliere procedure deadline approaching
- AND the case type allows one extension of 6 weeks
- WHEN the behandelaar requests an extension via the deadline panel
- THEN the deadline MUST be extended by 6 weeks
- AND the extension MUST be communicated to the aanvrager (via DSO status update)
- AND the case timeline MUST record: "Beslistermijn verlengd met 6 weken. Reden: [reden]"

#### Scenario VTH-07d: Lex silencio positivo (van rechtswege)

- GIVEN a case where the deadline has passed without a decision
- AND the case type is subject to lex silencio positivo
- WHEN the deadline expires
- THEN the system MUST create an urgent alert: "WAARSCHUWING: Vergunning van rechtswege verleend op [deadline datum]"
- AND a task MUST be created for the teamleider: "Beoordeel vergunning van rechtswege"
- AND this scenario MUST be preventable by the deadline monitoring in the werkvoorraad spec

---

### REQ-VTH-08: VTH Case Type Templates

The system MUST provide pre-configured case type templates for common VTH processes.

**Feature tier**: V1


#### Scenario VTH-08a: Omgevingsvergunning template

- GIVEN the beheerder wants to set up VTH case types
- WHEN importing the "Omgevingsvergunning Bouwactiviteit" template
- THEN the system MUST create:
  - Case type with correct processing deadline (8 weeks regulier)
  - Status types: Ontvangen, Ontvankelijkheidstoets, In behandeling, Advies, Besluitvorming, Afgehandeld
  - Document types: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's
  - Property definitions: bouwkosten, oppervlakte, aantal bouwlagen, BAG-object
  - Role types: behandelaar, aanvrager, gemachtigde, adviseur

#### Scenario VTH-08b: Toezichtzaak template

- GIVEN the beheerder imports the "Toezichtzaak Bouw" template
- THEN the system MUST create:
  - Case type with inspection phases (fundering, ruwbouw, oplevering)
  - Inspection checklists per phase
  - Status types: Gepland, In uitvoering, Rapport, Opvolging, Afgehandeld
  - Role types: inspecteur, contactpersoon, opdrachtgever

#### Scenario VTH-08c: Handhavingszaak template

- GIVEN the beheerder imports the "Handhavingszaak" template
- THEN the system MUST create:
  - Case type with enforcement phases
  - Status types: Constatering, Vooraankondiging, Zienswijze, Handhavingsbesluit, Begunstigingstermijn, Hercontrole, Afgehandeld
  - Property definitions: overtredingstype, ernst, gedrag, interventie, dwangsombedrag, begunstigingstermijn
  - Document types: constateringsrapport, vooraankondigingsbrief, handhavingsbesluit, dwangsombeschikking

---

### REQ-VTH-09: Bekendmaking (Publication)

The system MUST support publishing permit decisions in accordance with the Omgevingswet publication requirements.

**Feature tier**: V1


#### Scenario VTH-09a: Publish permit decision

- GIVEN a case with a definitief besluit (vergunning verleend)
- WHEN the behandelaar triggers bekendmaking
- THEN the system MUST generate a bekendmakingstekst using a Docudesk template
- AND the publicatiedatum and bezwaartermijn MUST be calculated
- AND the bekendmaking data MUST be exportable to the Gemeentelijk Publicatieplatform or DROP (Decentrale Regelgeving en Officiële Publicaties)

#### Scenario VTH-09b: Bezwaartermijn tracking

- GIVEN a published besluit with a bezwaartermijn of 6 weeks starting from publicatiedatum
- WHEN the bezwaartermijn expires
- THEN the system MUST create a task: "Bezwaartermijn verlopen: [case identifier]"
- AND if no bezwaar is received, the besluit becomes onherroepelijk
- AND the case timeline MUST record: "Besluit onherroepelijk geworden"

#### Scenario VTH-09c: Bezwaar received

- GIVEN a published besluit within the bezwaartermijn
- WHEN a bezwaar is received
- THEN the system MUST support creating a new case linked to the original: type "Bezwaarprocedure"
- AND the original case MUST show: "Bezwaar ontvangen: [datum]"

---

### REQ-VTH-10: Mobile Inspection Support

The system SHALL support mobile inspection workflows for inspectors working in the field.

**Feature tier**: V2


#### Scenario VTH-10a: Mobile checklist completion

- GIVEN an inspector using a tablet or smartphone in the field
- WHEN opening a planned inspection from the Nextcloud mobile app or web browser
- THEN the inspection checklist MUST render in a mobile-friendly layout
- AND checklist items MUST be completable via large touch targets
- AND photo capture MUST use the device camera directly

#### Scenario VTH-10b: Offline support

- GIVEN an inspector in an area with poor connectivity
- WHEN the inspector starts filling in a checklist
- THEN checklist progress MUST be saved locally
- AND when connectivity returns, the rapport MUST sync to the server
- AND a visual indicator MUST show sync status (synced/pending/error)

#### Scenario VTH-10c: GPS location capture

- GIVEN an inspector completing a checklist
- WHEN the inspector submits the rapport
- THEN the device GPS coordinates MUST be automatically captured
- AND the coordinates MUST be stored on the inspectieRapport
- AND a map pin MUST be visible in the rapport detail view

---

### REQ-VTH-11: DSO Intake Mapping

The system MUST map DSO verzoek data fields to Procest case properties.

**Feature tier**: V1


#### Scenario VTH-11a: Field mapping configuration

- GIVEN the beheerder configures DSO field mapping
- WHEN setting up mappings:
  - DSO `aanvraag.bouwkosten` -> case property `bouwkosten`
  - DSO `aanvraag.locatie.adres` -> case property `locatie` + linked BAG object
  - DSO `aanvraag.aanvrager.bsn` -> participant with role "aanvrager"
  - DSO `aanvraag.activiteiten[]` -> case property `activiteiten`
- THEN the mappings MUST be stored in the admin settings
- AND new DSO intakes MUST apply these mappings automatically

#### Scenario VTH-11b: Unmapped fields

- GIVEN a DSO verzoek with fields not in the mapping configuration
- WHEN the case is created
- THEN unmapped fields MUST be stored as raw JSON on the case (custom property "dso_raw")
- AND a notification MUST be logged: "DSO intake: [n] velden niet gemapped"

#### Scenario VTH-11c: Mapping validation

- GIVEN a DSO verzoek where mapped fields have unexpected formats
- WHEN validation fails (e.g., bouwkosten is not a number)
- THEN the case MUST still be created
- AND a task MUST flag the validation issue: "DSO intake validatie: bouwkosten formaat ongeldig"

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): VTH cases are standard cases with domain-specific extensions.
- **Case Types spec** (`../case-types/spec.md`): VTH-specific case types (Omgevingsvergunning, Toezichtzaak, Handhavingszaak).
- **Zaak Intake Flow spec** (`../zaak-intake-flow/spec.md`): DSO intake creates VTH cases.
- **Legesberekening spec** (`../legesberekening/spec.md`): Leges are calculated on permit cases (bouwkosten from DSO intake).
- **Case Dashboard View spec** (`../case-dashboard-view/spec.md`): Inspection panel, advice panel, enforcement panel on case detail.
- **Werkvoorraad spec** (`../werkvoorraad/spec.md`): Deadline monitoring, overdue advice tracking.
- **B&W Parafering spec** (`../bw-parafering/spec.md`): Permit decisions may require B&W approval.
- **Mobiel Inspectie spec** (`../mobiel-inspectie/spec.md`): Field inspection UI for toezicht.
- **OpenRegister**: VTH schemas (inspectieChecklist, inspectieRapport, handhavingsactie, adviesAanvraag).
- **OpenConnector**: DSO, BAG, BRK, Veiligheidsregio integrations.
- **Docudesk**: Templates for vooraankondigingsbrief, handhavingsbesluit, bekendmakingstekst.

---

### Using Mock Register Data

This spec depends on the **DSO** and **BAG** mock registers for permit intake and location data (REQ-VTH-01).

**Loading the registers:**
```bash
# Load DSO register (53 records, register slug: "dso", schemas: "activiteit", "locatie", "omgevingsdocument", "vergunningaanvraag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json

# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **DSO application intake (REQ-VTH-01)**: Use DSO `vergunningaanvraag` records to test case creation from omgevingsvergunning aanvraag with activiteiten and locatie
- **Multiple activities**: DSO `activiteit` records include "Bouwen", "Kappen", "Uitrit aanleggen" etc. -- test samenloop (combined activities)
- **BAG locatie**: BAG `nummeraanduiding` records with postcode/huisnummer -- test permit location linking
- **Bouwkosten from DSO**: DSO `vergunningaanvraag` includes cost data -- test passing bouwkosten to legesberekening
- **Inspection locations**: BAG addresses across multiple municipalities -- test inspection planning with geographic data

### Current Implementation Status

**Not implemented.** No VTH-specific functionality exists in the Procest codebase. The following foundational elements could support future VTH development:

- **Case management infrastructure**: The core case system (case types, statuses, tasks, roles, decisions) exists and could be extended with VTH-specific case types.
- **ZGW API layer**: Full ZGW-compliant controllers exist (`lib/Controller/ZrcController.php`, `ZtcController.php`, `DrcController.php`, `BrcController.php`) which could serve as the API layer for VTH integrations and DSO status updates.
- **ZGW mappings**: `lib/Service/ZgwMappingService.php` and `lib/Controller/ZgwMappingController.php` support configurable field mappings between English and ZGW Dutch terminology -- extensible for DSO field mapping.
- **Case type configuration**: `src/views/settings/CaseTypeAdmin.vue`, `CaseTypeDetail.vue`, and `CaseTypeList.vue` provide the admin UI for configuring case types, which could be used to create VTH-specific case types.
- **Document management**: The `filesPlugin()` in the object store and the DRC controller provide document handling capabilities for inspection photos and reports.
- **Register config**: `lib/Settings/procest_register.json` defines `documentType` and `propertyDefinition` schemas that could support inspection checklists and VTH-specific properties.
- **Deadline panel**: `src/views/cases/components/DeadlinePanel.vue` already supports extension and suspension display.
- **Notification service**: `lib/Service/NotificatieService.php` for advice deadline reminders.

**Nothing VTH-specific exists:**
- No DSO/Omgevingsloket integration
- No inspection checklist configuration or completion UI
- No enforcement strategy (LHS) matrix
- No supervision planning (toezichtplan) views
- No mobile inspection UI
- No advice management workflow
- No VTH-specific case type templates
- No bekendmaking (publication) workflow

### Standards & References

- **Omgevingswet**: Dutch Environmental Law (effective Jan 1, 2024) governing permits, supervision, and enforcement. Defines procedure types (regulier 8 weeks, uitgebreid 26 weeks). Source of lex silencio positivo rules.
- **DSO (Digitaal Stelsel Omgevingswet)**: Digital system for environmental law. DSO API specifications for receiving vergunningaanvragen and pushing status updates.
- **GEMMA VTH-referentiecomponenten**: VNG reference architecture for VTH processes. Defines components VTH001-VTH120+.
- **StUF-LVO**: Message standard for environmental law data exchange (being replaced by DSO APIs).
- **ZGW APIs**: Zaken, Documenten, Catalogi, Besluiten APIs for VTH case management.
- **Landelijke Handhavingsstrategie (LHS)**: National enforcement strategy matrix (ernst x gedrag = interventie). Published by the Omgevingsdienst NL.
- **BAG/BRK**: Kadaster registries for address and parcel data linked to permit locations.
- **WOO (Wet open overheid)**: Transparency requirements for VTH decisions and bekendmakingen.
- **Archiefwet**: Archival requirements for VTH decisions, inspection records, and enforcement actions.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Security requirements for government systems handling permit data.
- **DROP**: Decentrale Regelgeving en Officiele Publicaties platform for publishing permit decisions.
- **Awb**: Algemene wet bestuursrecht for procedure deadlines, bezwaartermijn, lex silencio positivo.

### Specificity Assessment

This spec is now a comprehensive VTH domain specification with defined OpenRegister schemas, concrete scenarios, and clear V1/V2 tiering. Each subsystem (DSO intake, inspection checklists, enforcement, supervision, advice) has actionable requirements.

**Strengths:** Defined OpenRegister schemas for all VTH entities. Concrete process flows for all three VTH domains (V, T, H). VTH case type templates provide quick setup. DSO field mapping is specified. LHS matrix is defined with customizable configuration. Advice management covers both internal and external advisory. Bekendmaking and bezwaartermijn tracking close the permit lifecycle.

**Resolved ambiguities:**
- DSO integration goes through OpenConnector (not directly in Procest), using the existing ZGW mapping infrastructure for field mapping.
- Inspection checklists are versioned -- in-progress inspections use their original version (REQ-VTH-03a).
- The LHS matrix is configurable per municipality but ships with the national default (REQ-VTH-04d).
- Mobile/offline support is V2 and leverages Nextcloud mobile app's offline sync for documents, with local storage for checklist progress.
- VTH module integrates with legesberekening via bouwkosten case property from DSO intake (REQ-VTH-01a -> legesberekening spec).
- Bekendmaking exports to DROP/Gemeentelijk Publicatieplatform (REQ-VTH-09a).
