## Purpose

Provide VTH enforcement (handhaving) support in Procest based on the Landelijke Handhavingsstrategie (LHS): a configurable ernst x gedrag interventie matrix, an enforcement-action schema with dwangsom tracking and status lifecycle, a guided enforcement wizard, and a case-dashboard enforcement panel.

@e2e exclude LHS matrix configuration is V1; matrix admin UI is a generic index page not yet exercised in Playwright tests.

## Requirements

### Requirement: LHS matrix configuration

The system SHALL support configuring the Landelijke Handhavingsstrategie (LHS) 4x4 matrix (ernst x gedrag = interventie) in admin settings, with a default national configuration and per-municipality customization.

**Feature tier**: V1
**ZGW mapping**: Custom extension (no ZGW equivalent for LHS matrix)
**Standards**: Landelijke Handhavingsstrategie (Omgevingsdienst NL)

#### Scenario: Default LHS matrix on install

- **WHEN** the Procest app is installed with VTH seed data
- **THEN** the system SHALL create a default LHS matrix configuration with the national standard:
  | | Goedwillend | Onverschillig | Calculerend | Crimineel |
  |---|---|---|---|---|
  | Gering | Waarschuwing | Waarschuwing + herstel | Last onder dwangsom | PV + Last |
  | Aanzienlijk | Herstel | Last onder dwangsom | Last + PV | PV + Bestuursdwang |
  | Ernstig | Last onder dwangsom | Last + PV | PV + Bestuursdwang | PV + Bestuursdwang |

#### Scenario: Customize LHS matrix

- **WHEN** the beheerder navigates to Procest Admin > VTH Instellingen > Handhavingsstrategie
- **THEN** the system SHALL display the 4x4 matrix as an editable grid
- **THEN** each cell SHALL be editable with a dropdown of intervention types: Waarschuwing, Herstelactie, Last onder dwangsom, Last + PV, PV + Bestuursdwang, Bestuursdwang
- **THEN** the beheerder SHALL be able to save the customized matrix

### Requirement: Enforcement action schema

The system SHALL store enforcement actions as `handhavingsactie` objects in OpenRegister with LHS classification, dwangsom tracking, and status lifecycle.

**Feature tier**: V1
**Schema.org**: schema:LegalForceStatus (enforcement status)

#### Scenario: Create enforcement action from LHS classification

- **WHEN** a behandelaar classifies a constatering with ernst="aanzienlijk" and gedrag="onverschillig"
- **THEN** the system SHALL look up the LHS matrix and suggest interventie "Last onder dwangsom"
- **THEN** the system SHALL create a `handhavingsactie` record with: case (reference), type (from LHS lookup), ernst, gedrag, interventie, status="opgelegd"
- **THEN** the behandelaar SHALL be able to override the suggestion with documented reasoning stored in the audit trail

#### Scenario: Dwangsom tracking

- **WHEN** a handhavingsactie of type "last_onder_dwangsom" is created
- **THEN** the record SHALL include: dwangsomBedrag (per overtreding), dwangsomMaximaal (maximum total), begunstigingstermijn (days)
- **THEN** when the begunstigingstermijn expires, the system SHALL create a task "Hercontrole uitvoeren"
- **THEN** if the overtreding persists, the system SHALL support recording a verbeuring with amount and date, updating status to "verbeurd"

#### Scenario: Enforcement status lifecycle

- **WHEN** a handhavingsactie is created
- **THEN** the status SHALL follow the lifecycle: opgelegd -> verbeurd -> geeffectueerd | ingetrokken
- **THEN** each status change SHALL be recorded in the case timeline

### Requirement: Enforcement wizard

The system SHALL provide a guided wizard UI for creating enforcement actions based on LHS matrix classification.

**Feature tier**: V1

#### Scenario: Step 1 - Classification

- **WHEN** the behandelaar clicks "Handhaving starten" on a case with a constatering
- **THEN** the wizard SHALL display step 1: "Classificatie"
- **THEN** the wizard SHALL present two selectors: ernst (gering/aanzienlijk/ernstig) and gedrag (goedwillend/onverschillig/calculerend/crimineel)
- **THEN** selecting both SHALL immediately show the suggested interventie from the LHS matrix

#### Scenario: Step 2 - Intervention details

- **WHEN** the behandelaar confirms or overrides the suggested interventie
- **THEN** the wizard SHALL display step 2: "Interventiedetails"
- **THEN** for "Last onder dwangsom" the form SHALL include: dwangsomBedrag, dwangsomMaximaal, begunstigingstermijn (days)
- **THEN** for "Bestuursdwang" the form SHALL include: effectueringsDatum, kostenraming

#### Scenario: Step 3 - Vooraankondiging

- **WHEN** the behandelaar completes the intervention details
- **THEN** the wizard SHALL display step 3: "Vooraankondiging"
- **THEN** the system SHALL offer to generate a vooraankondigingsbrief (placeholder for Docudesk integration)
- **THEN** the wizard SHALL set a zienswijzetermijn (default 2 weeks, configurable)

#### Scenario: Wizard creates enforcement records

- **WHEN** the behandelaar completes the wizard
- **THEN** the system SHALL create: handhavingsactie record, vooraankondiging document link, zienswijze task with deadline
- **THEN** the case workflow SHALL advance to the "Vooraankondiging" step

### Requirement: Enforcement panel on case dashboard

The system SHALL display an enforcement panel on the case dashboard for Handhaving case types.

**Feature tier**: V1

#### Scenario: Display enforcement status

- **WHEN** a user views the case dashboard for a Handhavingszaak
- **THEN** the "Handhaving" panel SHALL show: LHS classification (ernst x gedrag), interventie type, current enforcement status
- **THEN** for dwangsom: the panel SHALL show bedrag per overtreding, maximum, begunstigingstermijn countdown, total verbeurd amount
- **THEN** the timeline SHALL show all enforcement actions in chronological order
