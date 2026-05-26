## Purpose

@e2e exclude Seed data imported via repair step; data presence is covered by PHPUnit repair-step tests.

## ADDED Requirements

### Requirement: VTH case type seed data for Vergunningen

The system SHALL provide seed data for permit case types that are automatically imported via the repair step, creating ready-to-use VTH case types with pre-configured status types, role types, document types, and property definitions.

**Feature tier**: V1
**ZGW mapping**: ZaakType with StatusType[], RolType[], InformatieObjectType[], EigenschapType[]

#### Scenario: Import Omgevingsvergunning Bouwactiviteit case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Omgevingsvergunning Bouwactiviteit" with:
  - Status types: Ontvangen, Ontvankelijkheidstoets, In behandeling, Advies, Besluitvorming, Bekendmaking, Afgehandeld
  - Role types: behandelaar, aanvrager, gemachtigde, adviseur (welstand), adviseur (brandweer), teamleider
  - Document types: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's bestaande situatie, besluit, bekendmakingstekst
  - Property definitions: bouwkosten (decimal), oppervlakte (decimal), aantalBouwlagen (integer), bagObject (string/reference), activiteiten (array), procedureType (enum: regulier/uitgebreid), dsoVerzoekId (string)
  - processingDeadline: P56D (regulier) with extension P42D

#### Scenario: Import Sloopmelding case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Sloopmelding" with:
  - Status types: Ontvangen, Beoordeling, Akkoord, Afgehandeld
  - processingDeadline: P28D (4 weeks)
  - Property definitions: asbestInventarisatie (boolean), sloopOppervlakte (decimal)

### Requirement: VTH case type seed data for Toezicht

The system SHALL provide seed data for supervision case types with inspection-specific configuration.

**Feature tier**: V1
**ZGW mapping**: ZaakType with StatusType[], RolType[]

#### Scenario: Import Toezichtzaak Bouw case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Toezichtzaak Bouw" with:
  - Status types: Gepland, Inspectie fase 1, Inspectie fase 2, Inspectie fase 3, Rapport, Opvolging, Afgehandeld
  - Role types: inspecteur, contactpersoon, opdrachtgever
  - Document types: constateringsrapport, inspectieRapport, foto's
  - Property definitions: bouwvergunningZaak (reference to permit case), inspectieFases (array: fundering/ruwbouw/oplevering), laatsteInspectieResultaat (enum: conform/niet_conform/deels_conform)

#### Scenario: Import Toezichtzaak Milieu case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Toezichtzaak Milieu" with:
  - Status types: Gepland, Inspectie, Rapport, Opvolging, Afgehandeld
  - Role types: inspecteur, contactpersoon, inrichtinghouder
  - Property definitions: inspectieType (enum: periodiek/incidenteel), risicoCategorie (enum: hoog/midden/laag), voorgaandeInspecties (integer)

### Requirement: VTH case type seed data for Handhaving

The system SHALL provide seed data for enforcement case types with LHS-specific configuration.

**Feature tier**: V1
**ZGW mapping**: ZaakType with StatusType[], EigenschapType[]

#### Scenario: Import Handhavingszaak case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Handhavingszaak" with:
  - Status types: Constatering, Vooraankondiging, Zienswijze, Handhavingsbesluit, Begunstigingstermijn, Hercontrole, Afgehandeld
  - Role types: behandelaar, overtreder, gemachtigde, teamleider
  - Document types: constateringsrapport, vooraankondigingsbrief, handhavingsbesluit, dwangsombeschikking, hercontrolerapport
  - Property definitions: overtredingstype (string), ernst (enum: gering/aanzienlijk/ernstig), gedrag (enum: goedwillend/onverschillig/calculerend/crimineel), interventie (string), dwangsomBedrag (decimal), dwangsomMaximaal (decimal), begunstigingstermijn (integer/days), bronInspectie (reference to toezichtzaak)

#### Scenario: Import Invorderingszaak case type

- **WHEN** the Procest app is installed or upgraded
- **THEN** the repair step SHALL import a case type "Invorderingszaak" with:
  - Status types: Verbeuring, Invordering, Betaald, Afgehandeld
  - Property definitions: bronHandhavingszaak (reference), verbeurdBedrag (decimal), verbeuringsDatum (date)

### Requirement: Seed data is idempotent

The seed data import SHALL be idempotent -- running the repair step multiple times SHALL NOT create duplicate case types or overwrite user customizations.

**Feature tier**: V1

#### Scenario: Re-import does not duplicate

- **WHEN** the repair step runs and the "Omgevingsvergunning Bouwactiviteit" case type already exists
- **THEN** the system SHALL skip creation of that case type
- **THEN** the system SHALL NOT modify any user-customized properties on the existing case type

#### Scenario: Upgrade adds new seed case types

- **WHEN** a Procest upgrade adds a new VTH case type template (e.g., "Gebruiksmelding brandveiligheid")
- **THEN** the repair step SHALL create the new case type without affecting existing ones
