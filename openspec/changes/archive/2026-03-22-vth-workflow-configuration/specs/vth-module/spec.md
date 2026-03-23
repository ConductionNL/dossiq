## MODIFIED Requirements

### REQ-VTH-03: Inspection Checklists

The system MUST support configurable inspection checklists per case type or inspection type, stored as `inspectieChecklist` objects in OpenRegister.

**Feature tier**: V1

**Implementation note**: This requirement is now implemented via the `inspection-checklists` capability. The inspectieChecklist, checklistItem, and inspectieRapport schemas are defined in procest_register.json. Checklist configuration is available in the case type admin UI. Rapport creation is available on the case dashboard for Toezicht case types.

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

### REQ-VTH-04: Enforcement Strategies (Handhaving)

The system SHALL support the Landelijke Handhavingsstrategie (LHS) for determining appropriate enforcement actions.

**Feature tier**: V1 (upgraded from V2 — implemented via enforcement-lhs capability)

**Implementation note**: The LHS matrix configuration and enforcement wizard are now V1 capabilities, implemented via the `enforcement-lhs` capability with handhavingsactie schema and admin UI.

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
  1. Generate a vooraankondigingsbrief via Docudesk template (placeholder)
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

### REQ-VTH-06: Advice Management (Advies)

The system MUST support requesting and tracking internal and external advice on permit applications.

**Feature tier**: V1

**Implementation note**: This requirement is now implemented via the `advice-management` capability with adviesAanvraag schema, advice panel, and advice request form.

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

### REQ-VTH-08: VTH Case Type Templates

The system MUST provide pre-configured case type templates for common VTH processes.

**Feature tier**: V1

**Implementation note**: This requirement is now implemented via the `vth-case-type-seed` capability and `vth-workflow-templates` capability. Case types are seeded via repair step; workflow templates are importable via the workflow engine.

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
