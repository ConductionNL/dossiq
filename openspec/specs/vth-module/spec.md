# VTH Module Specification

## Purpose

The VTH (Vergunningen, Toezicht, Handhaving) module extends Procest with domain-specific capabilities for permit management, supervision, and enforcement. VTH processes are the most complex case management domain in Dutch municipalities, involving DSO/Omgevingsloket integration, configurable inspection checklists, enforcement strategies (Landelijke Handhavingsstrategie), supervision planning, and mobile inspection support.

**Tender demand**: 29% of tenders (20/69) explicitly require VTH capabilities. VTH tenders are high-value: they represent large municipalities and regional enforcement agencies (omgevingsdiensten) with budgets of EUR 500K-2M+.
**Standards**: Omgevingswet, GEMMA VTH-referentiecomponenten, DSO (Digitaal Stelsel Omgevingswet), StUF-LVO, ZGW APIs
**Feature tier**: V1 (DSO intake, permit workflow, basic checklists), V2 (enforcement strategies, supervision planning, mobile inspection)

## VTH Process Overview

```
Vergunningen (Permits):
  DSO/aanvraag -> Ontvankelijkheidstoets -> Inhoudelijke toets -> Advies -> Besluit -> Bekendmaking

Toezicht (Supervision):
  Toezichtplan -> Inspectie plannen -> Inspectie uitvoeren -> Rapport -> Opvolging

Handhaving (Enforcement):
  Constatering -> Vooraankondiging -> Zienswijze -> Handhavingsbesluit -> Uitvoering -> Controle
```

## Requirements

---

### REQ-VTH-01: DSO/Omgevingsloket Integration

**Feature tier**: V1

The system MUST support receiving vergunningaanvragen from the Digitaal Stelsel Omgevingswet (DSO) and creating cases from them.

#### Scenario VTH-01a: DSO application creates permit case

- GIVEN DSO integration is configured via OpenConnector
- WHEN a citizen submits an omgevingsvergunning aanvraag via the Omgevingsloket
- AND the DSO forwards the verzoek to the municipality's VTH system
- THEN the system MUST create a case of type "Omgevingsvergunning"
- AND the case MUST include: activiteiten (from DSO), locatie (BAG/kadaster), aanvrager gegevens, bijlagen
- AND bouwkosten from the DSO verzoek MUST be stored as a case property (used for legesberekening)
- AND the case deadline MUST be calculated per the Omgevingswet procedure type (regulier: 8 weeks, uitgebreid: 26 weeks)

#### Scenario VTH-01b: Multiple activities in single application

- GIVEN a DSO verzoek with 3 activities: "Bouwen", "Kappen", "Uitrit aanleggen"
- WHEN the case is created
- THEN the system MUST register all 3 activities on the case
- AND each activity MAY trigger a separate toets/advies task
- AND legesberekening MUST consider samenloop (combined activities)

---

### REQ-VTH-02: Ontvankelijkheidstoets (Completeness Check)

**Feature tier**: V1

The system MUST support a structured completeness check for permit applications.

#### Scenario VTH-02a: Incomplete application detected

- GIVEN a case "Omgevingsvergunning Bouw" with document checklist requiring: bouwtekening, constructieberekening, situatietekening
- AND only bouwtekening has been uploaded
- WHEN the behandelaar performs the ontvankelijkheidstoets
- THEN the system MUST display which required documents are missing
- AND the system MUST support sending an "Aanvulverzoek" to the aanvrager
- AND the case deadline MUST be suspended during the aanvulperiode (if suspension is allowed)

---

### REQ-VTH-03: Inspection Checklists

**Feature tier**: V1

The system MUST support configurable inspection checklists per case type or inspection type.

#### Scenario VTH-03a: Configure inspection checklist

- GIVEN an admin configuring checklist "Bouwtoezicht fase 1" with items:
  - "Fundering conform tekening" (ja/nee/nvt + toelichting)
  - "Wapening aanwezig en correct" (ja/nee/nvt + toelichting)
  - "Waterkering conform bestek" (ja/nee/nvt + toelichting + foto verplicht)
- WHEN the checklist is saved
- THEN the checklist MUST be linked to case type "Bouwtoezicht"
- AND each item MUST support: pass/fail/not-applicable, free text comment, optional photo requirement

#### Scenario VTH-03b: Complete inspection checklist

- GIVEN an inspector performing "Bouwtoezicht fase 1" on case "2026-089"
- WHEN the inspector completes all checklist items
- THEN the system MUST record: inspector name, date/time, location (GPS), result per item
- AND the completed checklist MUST be stored as a document on the case
- AND a summary task result MUST be generated: "Conform" or "Niet-conform" with count of failed items

---

### REQ-VTH-04: Enforcement Strategies (Handhaving)

**Feature tier**: V2

The system SHOULD support the Landelijke Handhavingsstrategie (LHS) for determining appropriate enforcement actions.

#### Scenario VTH-04a: LHS matrix application

- GIVEN a constatering of type "Overtreding bouwvergunning"
- AND the inspector classifies: ernst = "aanzienlijk", gedrag = "onverschillig"
- WHEN the LHS matrix is applied
- THEN the system MUST suggest the appropriate interventie: "Last onder dwangsom + proces-verbaal"
- AND the behandelaar MAY override the suggestion with documented reasoning

#### Scenario VTH-04b: Enforcement workflow

- GIVEN a handhavingsbesluit "Last onder dwangsom" with begunstigingstermijn of 6 weeks
- WHEN the begunstigingstermijn expires
- THEN the system MUST create a follow-up task: "Hercontrole uitvoeren"
- AND if the overtreding persists, the system MUST support: verbeuring dwangsom, effectuering bestuursdwang

---

### REQ-VTH-05: Supervision Planning (Toezichtplan)

**Feature tier**: V2

The system SHOULD support creating and managing annual supervision plans.

#### Scenario VTH-05a: Annual inspection planning

- GIVEN a toezichtplan for 2026 with 150 planned inspections across categories:
  - Horeca: 40 inspections
  - Bouw: 60 inspections
  - Milieu: 50 inspections
- WHEN the planner views the toezichtplan dashboard
- THEN the system MUST show: planned vs. completed per category, capacity vs. demand, geographic distribution

#### Scenario VTH-05b: Risk-based inspection scheduling

- GIVEN objects with risk profiles (high/medium/low) based on previous inspection results
- WHEN generating the toezichtplan
- THEN high-risk objects MUST be scheduled more frequently
- AND the system SHOULD suggest an optimal inspection schedule based on available capacity

---

### REQ-VTH-06: Advice Management (Advies)

**Feature tier**: V1

The system MUST support requesting and tracking internal and external advice on permit applications.

#### Scenario VTH-06a: Request advice from specialist

- GIVEN a case "Omgevingsvergunning Bouw" requiring welstandsadvies
- WHEN the behandelaar requests advice from the welstandscommissie
- THEN a task MUST be created: "Welstandsadvies uitbrengen" assigned to the commission
- AND the task MUST include: deadline (wettelijke termijn), relevant case documents, specific questions
- AND the case timeline MUST show: "Advies aangevraagd bij Welstandscommissie"

#### Scenario VTH-06b: External advice via ketenpartner

- GIVEN a case requiring advice from the brandweer (Veiligheidsregio)
- WHEN the behandelaar requests external advice
- THEN the system MUST support sending the request via ZGW API or e-mail
- AND the response MUST be trackable with a deadline
- AND late advice MUST trigger an escalation notification

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): VTH cases are standard cases with domain-specific extensions.
- **Case Types spec** (`../case-types/spec.md`): VTH-specific case types (Omgevingsvergunning, Toezichtzaak, Handhavingszaak).
- **Zaak Intake Flow spec** (`../zaak-intake-flow/spec.md`): DSO intake creates VTH cases.
- **Legesberekening spec** (`../legesberekening/spec.md`): Leges are calculated on permit cases.
- **Mobiel Inspectie spec** (`../mobiel-inspectie/spec.md`): Field inspection UI for toezicht.
- **OpenConnector**: DSO, BAG, BRK, Veiligheidsregio integrations.
