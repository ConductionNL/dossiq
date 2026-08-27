---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-module Specification

## Purpose
Provides the VTH (Vergunningen, Toezicht en Handhaving) capability for permit, supervision, and enforcement processes, including DSO/Omgevingsloket intake, completeness checks (ontvankelijkheidstoets), inspection checklists, enforcement strategies (LHS), supervision planning, advice management, and Omgevingswet procedure deadlines. Ships pre-configured VTH case type templates and supports decision publication (bekendmaking), mobile inspection workflows, and configurable DSO intake field mapping.
## Requirements
### Requirement: REQ-VTH-01 — DSO/Omgevingsloket Integration

The system MUST support receiving vergunningaanvragen from the Digitaal Stelsel Omgevingswet (DSO) and creating cases from them.

**Feature tier**: V1

#### Scenario: DSO application creates permit case

- **GIVEN** DSO integration is configured via OpenConnector
- **WHEN** a citizen submits an omgevingsvergunning aanvraag via the Omgevingsloket
- **AND** the DSO forwards the verzoek to the municipality's VTH system
- **THEN** the system MUST create a case of type "Omgevingsvergunning Bouwactiviteit"
- **AND** the case MUST include: activiteiten (from DSO), locatie (BAG/kadaster), aanvrager gegevens, bijlagen
- **AND** bouwkosten from the DSO verzoek MUST be stored as a case property (used for legesberekening)
- **AND** the case deadline MUST be calculated per the Omgevingswet procedure type (regulier: 8 weeks, uitgebreid: 26 weeks)
- **AND** the case MUST be automatically assigned to the configured team for this case type

#### Scenario: Multiple activities in single application

- **GIVEN** a DSO verzoek with 3 activities: "Bouwen", "Kappen", "Uitrit aanleggen"
- **WHEN** the case is created
- **THEN** the system MUST register all 3 activities on the case as case properties
- **AND** each activity MAY trigger a separate toets/advies task
- **AND** legesberekening MUST consider samenloop (combined activities, see legesberekening spec)

#### Scenario: DSO status updates

- **GIVEN** a case created from a DSO verzoek
- **WHEN** the case status changes (e.g., from "Ontvangen" to "In behandeling")
- **THEN** the system MUST push a status update back to the DSO via OpenConnector
- **AND** the aanvrager MUST be able to see the current status in the Omgevingsloket

#### Scenario: DSO aanvulverzoek (request for additional information)

- **GIVEN** a case where the ontvankelijkheidstoets reveals missing documents
- **WHEN** the behandelaar sends an aanvulverzoek via the case dashboard
- **THEN** the system MUST push the aanvulverzoek to the DSO
- **AND** the case deadline MUST be suspended (opgeschort) during the aanvulperiode
- **AND** the suspension MUST be recorded in the case timeline

#### Scenario: DSO intake validation

- **GIVEN** a DSO verzoek arrives with invalid or incomplete data
- **WHEN** the system attempts to create a case
- **THEN** validation errors MUST be logged: "DSO verzoek [id]: ontbrekend veld 'bouwkosten'"
- **AND** the case MUST still be created (with incomplete data) for manual completion
- **AND** a task MUST be created for the behandelaar: "DSO intake validatie: controleer ontbrekende gegevens"

---

### Requirement: REQ-VTH-02 — Ontvankelijkheidstoets (Completeness Check)

The system MUST support a structured completeness check for permit applications, using document type checklists per case type.

**Feature tier**: V1

#### Scenario: Incomplete application detected

- **GIVEN** a case "Omgevingsvergunning Bouw" with document checklist requiring: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's bestaande situatie
- **AND** only bouwtekening and foto's have been uploaded
- **WHEN** the behandelaar performs the ontvankelijkheidstoets via the case dashboard
- **THEN** the system MUST display which required documents are missing (3 of 5)
- **AND** each missing document MUST be highlighted with a warning icon
- **AND** the system MUST support sending an "Aanvulverzoek" to the aanvrager (via DSO or email)

#### Scenario: Complete application

- **GIVEN** all required documents have been uploaded (5 of 5)
- **WHEN** the behandelaar performs the ontvankelijkheidstoets
- **THEN** the system MUST display "Ontvankelijk" with all checkmarks green
- **AND** the system MUST allow the behandelaar to confirm ontvankelijkheid and advance the case status

#### Scenario: Deadline suspension during aanvulperiode

- **GIVEN** a case with deadline of 8 weeks from start date 2026-03-01 (deadline: 2026-04-26)
- **AND** the behandelaar sends an aanvulverzoek on 2026-03-10
- **AND** the aanvrager responds with additional documents on 2026-03-25
- **WHEN** the behandelaar marks the aanvulverzoek as completed
- **THEN** the case deadline MUST be recalculated: 15 days were suspended, new deadline: 2026-05-11
- **AND** the suspension period MUST be recorded in the case timeline and deadline panel

#### Scenario: Aanvulverzoek timeout

- **GIVEN** an aanvulverzoek was sent with a 4-week response deadline
- **AND** the aanvrager has not responded within 4 weeks
- **WHEN** the timeout is reached
- **THEN** a task MUST be created for the behandelaar: "Aanvulverzoek verlopen: beoordeel of aanvraag buiten behandeling wordt gesteld"
- **AND** the teamleider MUST receive a notification

---

### Requirement: REQ-VTH-03 — Inspection Checklists

The system MUST support configurable inspection checklists per case type or inspection type, stored as `inspectieChecklist` objects in OpenRegister.

**Feature tier**: V1

#### Scenario: Configure inspection checklist

- **GIVEN** an admin configuring checklist "Bouwtoezicht fase 1 - Fundering" with items:
  - "Fundering conform tekening" (ja/nee/nvt + toelichting)
  - "Wapening aanwezig en correct" (ja/nee/nvt + toelichting)
  - "Waterkering conform bestek" (ja/nee/nvt + toelichting + foto verplicht)
  - "Maatvoering gecontroleerd" (ja/nee/nvt + getal: afwijking in mm)
- **WHEN** the checklist is saved
- **THEN** the checklist MUST be linked to case type "Toezichtzaak Bouw"
- **AND** each item MUST support: pass/fail/not-applicable, free text comment, optional photo requirement, optional numeric measurement
- **AND** the checklist MUST be versioned (v1, v2, ...) so in-progress inspections use their original version

#### Scenario: Complete inspection checklist

- **GIVEN** an inspector performing "Bouwtoezicht fase 1" on case "2026-089"
- **WHEN** the inspector fills in all checklist items and marks 2 items as "nee" (failed)
- **THEN** the system MUST record an `inspectieRapport`: inspector name, date/time, location, result per item
- **AND** the overall result MUST be automatically determined: "Niet-conform" (2 failed items)
- **AND** the completed rapport MUST be stored as a document on the case
- **AND** a summary task MUST be generated: "Opvolging vereist: 2 afwijkingen geconstateerd"

#### Scenario: Photo capture during inspection

- **GIVEN** a checklist item "Waterkering conform bestek" with fotoRequired=true
- **WHEN** the inspector marks this item as "nee" (failed)
- **THEN** the system MUST require at least one photo before the rapport can be submitted
- **AND** photos MUST be stored in Nextcloud Files under the case folder
- **AND** each photo MUST be linked to the specific checklist item

#### Scenario: Multiple inspections per case

- **GIVEN** a bouwtoezicht case with 3 inspection phases: fundering, ruwbouw, oplevering
- **WHEN** the inspector completes "fase 1 - fundering"
- **THEN** the completed rapport MUST be stored and the next inspection ("fase 2 - ruwbouw") MUST become available
- **AND** the case dashboard MUST show inspection progress: "Inspectie 1/3 voltooid"

#### Scenario: Inspection history

- **GIVEN** a case with 3 completed inspection rapporten
- **WHEN** the behandelaar views the case dashboard
- **THEN** the "Inspecties" panel MUST show all 3 rapporten: date, inspector, result (conform/niet-conform), failed item count
- **AND** each rapport MUST be expandable to view individual checklist item results

---

### Requirement: REQ-VTH-04 — Enforcement Strategies (Handhaving)

The system SHALL support the Landelijke Handhavingsstrategie (LHS) for determining appropriate enforcement actions.

**Feature tier**: V2

#### Scenario: LHS matrix application

- **GIVEN** a constatering of type "Overtreding bouwvergunning"
- **AND** the inspector classifies: ernst = "aanzienlijk", gedrag = "onverschillig"
- **WHEN** the LHS matrix is applied via the enforcement wizard
- **THEN** the system MUST suggest the appropriate interventie: "Last onder dwangsom + proces-verbaal"
- **AND** the behandelaar MAY override the suggestion with documented reasoning
- **AND** the override MUST be recorded in the audit trail

#### Scenario: Enforcement workflow

- **GIVEN** a handhavingsbesluit "Last onder dwangsom" with begunstigingstermijn of 6 weeks
- **AND** dwangsom bedrag EUR 5,000 per overtreding, maximaal EUR 25,000
- **WHEN** the begunstigingstermijn expires
- **THEN** the system MUST create a follow-up task: "Hercontrole uitvoeren"
- **AND** if the overtreding persists, the system MUST support: verbeuring dwangsom, effectuering bestuursdwang
- **AND** each verbeuring MUST be recorded with amount and date

#### Scenario: Vooraankondiging workflow

- **GIVEN** a constatering requiring enforcement
- **WHEN** the behandelaar initiates a vooraankondiging
- **THEN** the system MUST:
  1. Generate a vooraankondigingsbrief via Docudesk template
  2. Set a zienswijzetermijn (typically 2 weeks)
  3. Create a task: "Beoordeel zienswijze na termijn"
- **AND** if a zienswijze is received, it MUST be recorded on the case
- **AND** the behandelaar MUST assess the zienswijze before proceeding to handhavingsbesluit

#### Scenario: LHS matrix configuration

- **GIVEN** the beheerder configures the LHS matrix in admin settings
- **WHEN** setting up the ernst x gedrag matrix
- **THEN** the system MUST support the standard 4x4 matrix:
  | | Goedwillend | Onverschillig | Calculerend | Crimineel |
  |---|---|---|---|---|
  | Gering | Waarschuwing | Waarschuwing + herstel | Last onder dwangsom | PV + Last |
  | Aanzienlijk | Herstel | Last onder dwangsom | Last + PV | PV + Bestuursdwang |
  | Ernstig | Last onder dwangsom | Last + PV | PV + Bestuursdwang | PV + Bestuursdwang |
- **AND** the matrix MUST be customizable per municipality

---

### Requirement: REQ-VTH-05 — Supervision Planning (Toezichtplan)

The system SHALL support creating and managing annual supervision plans.

**Feature tier**: V2

#### Scenario: Annual inspection planning

- **GIVEN** a toezichtplan for 2026 with 150 planned inspections across categories:
  - Horeca: 40 inspections
  - Bouw: 60 inspections
  - Milieu: 50 inspections
- **WHEN** the planner views the toezichtplan dashboard
- **THEN** the system MUST show: planned vs. completed per category, capacity vs. demand, completion percentage
- **AND** a progress bar per category MUST indicate completion: Horeca (15/40 = 38%), Bouw (22/60 = 37%), Milieu (18/50 = 36%)

#### Scenario: Risk-based inspection scheduling

- **GIVEN** objects with risk profiles (high/medium/low) based on previous inspection results
- **WHEN** generating the toezichtplan
- **THEN** high-risk objects MUST be scheduled more frequently (e.g., every 6 months)
- **AND** medium-risk objects: annually
- **AND** low-risk objects: every 2 years
- **AND** the system SHOULD suggest an optimal inspection schedule based on available inspector capacity

#### Scenario: Geographic clustering

- **GIVEN** 20 planned inspections across the municipality
- **WHEN** the planner views the inspection map
- **THEN** inspections MUST be plottable on a map (using BAG coordinates)
- **AND** the system SHOULD suggest geographic clusters for efficient routing

#### Scenario: Actual vs. planned tracking

- **GIVEN** a toezichtplan with 40 planned horeca inspections for Q1
- **AND** 25 have been completed, 5 have been cancelled
- **WHEN** viewing the quarterly overview
- **THEN** the system MUST show: planned (40), completed (25), cancelled (5), remaining (10)
- **AND** a forecast MUST indicate whether the annual target is achievable at current pace

---

### Requirement: REQ-VTH-06 — Advice Management (Advies)

The system MUST support requesting and tracking internal and external advice on permit applications.

**Feature tier**: V1

#### Scenario: Request internal advice from specialist

- **GIVEN** a case "Omgevingsvergunning Bouw" requiring welstandsadvies
- **WHEN** the behandelaar clicks "Advies aanvragen" and selects the welstandscommissie (internal)
- **THEN** an `adviesAanvraag` MUST be created: type=intern, adviseur=welstandscommissie, deadline=[configured default]
- **AND** a task MUST be created for the welstandscommissie: "Welstandsadvies uitbrengen voor [case identifier]"
- **AND** the task MUST include: deadline, relevant case documents, specific questions
- **AND** the case timeline MUST show: "Advies aangevraagd bij Welstandscommissie"

#### Scenario: External advice via ketenpartner

- **GIVEN** a case requiring advice from the brandweer (Veiligheidsregio)
- **WHEN** the behandelaar requests external advice
- **THEN** the system MUST support sending the request via email (with case documents attached) or ZGW API
- **AND** the adviesAanvraag MUST be created with type=extern and a deadline
- **AND** a reminder notification MUST be generated 3 days before the deadline
- **AND** late advice MUST trigger an escalation notification to the behandelaar and teamleider

#### Scenario: Receive and process advice

- **GIVEN** an adviesAanvraag is pending for the welstandscommissie
- **WHEN** the adviseur uploads the advies document and marks the request as "Ontvangen"
- **THEN** the adviesAanvraag status MUST change to "Ontvangen"
- **AND** the advies document MUST be linked to the case
- **AND** the behandelaar MUST be notified: "Welstandsadvies ontvangen voor [case identifier]"
- **AND** the case timeline MUST show: "Welstandsadvies ontvangen" with a link to the document

#### Scenario: Advice overview on case dashboard

- **GIVEN** a case with 3 adviesAanvragen: welstandscommissie (ontvangen), brandweer (aangevraagd, deadline in 5 days), milieuadvies (verlopen, 2 days overdue)
- **WHEN** viewing the case dashboard
- **THEN** an "Adviezen" panel MUST show all 3 with: adviseur, status badge (green/orange/red), deadline
- **AND** overdue advice MUST be highlighted in red with days overdue

#### Scenario: Advice deadline tracking

- **GIVEN** 5 open adviesAanvragen across multiple cases
- **AND** 2 are overdue
- **WHEN** the teamleider views the werkvoorraad
- **THEN** overdue advice requests MUST appear as a separate filter option: "Verlopen adviezen (2)"
- **AND** each overdue request MUST show: case reference, adviseur, days overdue

---

### Requirement: REQ-VTH-07 — Permit Procedure Deadlines

The system MUST enforce Omgevingswet procedure deadlines per permit type and support suspension and extension.

**Feature tier**: V1

#### Scenario: Reguliere procedure deadline

- **GIVEN** a case type "Omgevingsvergunning Bouwactiviteit" with procedure "regulier"
- **AND** the Omgevingswet reguliere beslistermijn is 8 weeks
- **WHEN** the case is created with startdatum 2026-03-01
- **THEN** the case deadline MUST be automatically set to 2026-04-26 (8 weeks)
- **AND** the deadline MUST be displayed in the case dashboard's deadline panel

#### Scenario: Uitgebreide procedure deadline

- **GIVEN** a case requiring "uitgebreide procedure" (e.g., milieu-impact)
- **AND** the Omgevingswet uitgebreide beslistermijn is 26 weeks
- **WHEN** the case is created
- **THEN** the case deadline MUST be set to startdatum + 26 weeks

#### Scenario: Extension (verlenging)

- **GIVEN** a case with reguliere procedure deadline approaching
- **AND** the case type allows one extension of 6 weeks
- **WHEN** the behandelaar requests an extension via the deadline panel
- **THEN** the deadline MUST be extended by 6 weeks
- **AND** the extension MUST be communicated to the aanvrager (via DSO status update)
- **AND** the case timeline MUST record: "Beslistermijn verlengd met 6 weken. Reden: [reden]"

#### Scenario: Lex silencio positivo (van rechtswege)

- **GIVEN** a case where the deadline has passed without a decision
- **AND** the case type is subject to lex silencio positivo
- **WHEN** the deadline expires
- **THEN** the system MUST create an urgent alert: "WAARSCHUWING: Vergunning van rechtswege verleend op [deadline datum]"
- **AND** a task MUST be created for the teamleider: "Beoordeel vergunning van rechtswege"
- **AND** this scenario MUST be preventable by the deadline monitoring in the werkvoorraad spec

---

### Requirement: REQ-VTH-08 — VTH Case Type Templates

The system MUST provide pre-configured case type templates for common VTH processes.

**Feature tier**: V1

#### Scenario: Omgevingsvergunning template

- **GIVEN** the beheerder wants to set up VTH case types
- **WHEN** importing the "Omgevingsvergunning Bouwactiviteit" template
- **THEN** the system MUST create:
  - Case type with correct processing deadline (8 weeks regulier)
  - Status types: Ontvangen, Ontvankelijkheidstoets, In behandeling, Advies, Besluitvorming, Afgehandeld
  - Document types: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's
  - Property definitions: bouwkosten, oppervlakte, aantal bouwlagen, BAG-object
  - Role types: behandelaar, aanvrager, gemachtigde, adviseur

#### Scenario: Toezichtzaak template

- **GIVEN** the beheerder imports the "Toezichtzaak Bouw" template
- **THEN** the system MUST create:
  - Case type with inspection phases (fundering, ruwbouw, oplevering)
  - Inspection checklists per phase
  - Status types: Gepland, In uitvoering, Rapport, Opvolging, Afgehandeld
  - Role types: inspecteur, contactpersoon, opdrachtgever

#### Scenario: Handhavingszaak template

- **GIVEN** the beheerder imports the "Handhavingszaak" template
- **THEN** the system MUST create:
  - Case type with enforcement phases
  - Status types: Constatering, Vooraankondiging, Zienswijze, Handhavingsbesluit, Begunstigingstermijn, Hercontrole, Afgehandeld
  - Property definitions: overtredingstype, ernst, gedrag, interventie, dwangsombedrag, begunstigingstermijn
  - Document types: constateringsrapport, vooraankondigingsbrief, handhavingsbesluit, dwangsombeschikking

---

### Requirement: REQ-VTH-09 — Bekendmaking (Publication)

The system MUST support publishing permit decisions in accordance with the Omgevingswet publication requirements.

**Feature tier**: V1

#### Scenario: Publish permit decision

- **GIVEN** a case with a definitief besluit (vergunning verleend)
- **WHEN** the behandelaar triggers bekendmaking
- **THEN** the system MUST generate a bekendmakingstekst using a Docudesk template
- **AND** the publicatiedatum and bezwaartermijn MUST be calculated
- **AND** the bekendmaking data MUST be exportable to the Gemeentelijk Publicatieplatform or DROP (Decentrale Regelgeving en Officiële Publicaties)

#### Scenario: Bezwaartermijn tracking

- **GIVEN** a published besluit with a bezwaartermijn of 6 weeks starting from publicatiedatum
- **WHEN** the bezwaartermijn expires
- **THEN** the system MUST create a task: "Bezwaartermijn verlopen: [case identifier]"
- **AND** if no bezwaar is received, the besluit becomes onherroepelijk
- **AND** the case timeline MUST record: "Besluit onherroepelijk geworden"

#### Scenario: Bezwaar received

- **GIVEN** a published besluit within the bezwaartermijn
- **WHEN** a bezwaar is received
- **THEN** the system MUST support creating a new case linked to the original: type "Bezwaarprocedure"
- **AND** the original case MUST show: "Bezwaar ontvangen: [datum]"

---

### Requirement: REQ-VTH-10 — Mobile Inspection Support

The system SHALL support mobile inspection workflows for inspectors working in the field.

**Feature tier**: V2

#### Scenario: Mobile checklist completion

- **GIVEN** an inspector using a tablet or smartphone in the field
- **WHEN** opening a planned inspection from the Nextcloud mobile app or web browser
- **THEN** the inspection checklist MUST render in a mobile-friendly layout
- **AND** checklist items MUST be completable via large touch targets
- **AND** photo capture MUST use the device camera directly

#### Scenario: Offline support

- **GIVEN** an inspector in an area with poor connectivity
- **WHEN** the inspector starts filling in a checklist
- **THEN** checklist progress MUST be saved locally
- **AND** when connectivity returns, the rapport MUST sync to the server
- **AND** a visual indicator MUST show sync status (synced/pending/error)

#### Scenario: GPS location capture

- **GIVEN** an inspector completing a checklist
- **WHEN** the inspector submits the rapport
- **THEN** the device GPS coordinates MUST be automatically captured
- **AND** the coordinates MUST be stored on the inspectieRapport
- **AND** a map pin MUST be visible in the rapport detail view

---

### Requirement: REQ-VTH-11 — DSO Intake Mapping

The system MUST map DSO verzoek data fields to Dossiq case properties.

**Feature tier**: V1

#### Scenario: Field mapping configuration

- **GIVEN** the beheerder configures DSO field mapping
- **WHEN** setting up mappings:
  - DSO `aanvraag.bouwkosten` -> case property `bouwkosten`
  - DSO `aanvraag.locatie.adres` -> case property `locatie` + linked BAG object
  - DSO `aanvraag.aanvrager.bsn` -> participant with role "aanvrager"
  - DSO `aanvraag.activiteiten[]` -> case property `activiteiten`
- **THEN** the mappings MUST be stored in the admin settings
- **AND** new DSO intakes MUST apply these mappings automatically

#### Scenario: Unmapped fields

- **GIVEN** a DSO verzoek with fields not in the mapping configuration
- **WHEN** the case is created
- **THEN** unmapped fields MUST be stored as raw JSON on the case (custom property "dso_raw")
- **AND** a notification MUST be logged: "DSO intake: [n] velden niet gemapped"

#### Scenario: Mapping validation

- **GIVEN** a DSO verzoek where mapped fields have unexpected formats
- **WHEN** validation fails (e.g., bouwkosten is not a number)
- **THEN** the case MUST still be created
- **AND** a task MUST flag the validation issue: "DSO intake validatie: bouwkosten formaat ongeldig"

