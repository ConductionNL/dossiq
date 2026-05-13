---
status: implemented
---
# Legesberekening Specification

## Purpose

Legesberekening is the rules engine that calculates municipal fees (leges) on permit cases. It applies the gemeentelijke legesverordening -- typically based on the VNG modellegesverordening -- to case attributes and produces a calculated amount. The module does NOT handle payment or invoicing; it calculates and exports to the financial system.

**Tender demand**: Found as explicit requirement in 16 VTH tenders. Every VTH tender requires financial system export. Legesberekening is the #1 VTH-specific functional requirement after DSO integration.
**Standards**: VNG Modellegesverordening, Unie van Waterschappen modelverordening (for waterschappen), StUF-FIN, GEMMA VTH-referentiecomponenten (VTH055-VTH057, VTH103, VTH117, VTH119)
**Feature tier**: V1 (basic calculation, single verordening, manual export), V2 (multiple verordeningen, automatic DSO import, 4-ogen principe, versioned calculations, financial system connectors)

**Competitive context**: Dimpact ZAC does not include built-in legesberekening -- municipalities typically use their financial system or a separate legesmodule. Flowable can model fee calculations via DMN decision tables, providing a standards-based approach. Procest should implement legesberekening as a PHP calculation service with verordening data stored in OpenRegister, making it fully integrated in the case workflow rather than requiring external tools.

## Calculation Model

### Fee Calculation Types

| Type | Description | Example |
|------|-------------|---------|
| Vast bedrag | Fixed amount per application | Sloopmelding: EUR 250 |
| Percentage | Percentage of bouwkosten | 2.4% of declared construction costs |
| Staffel | Tiered brackets with different rates per bracket | 0-50K: 3%, 50K-250K: 2.5%, 250K+: 2% |
| Maximum | Fee capped at a maximum amount | Leges max EUR 50,000 |
| Minimum | Fee with a minimum floor amount | Leges min EUR 150 |
| Combinatie | Multiple calculation types combined | Base fee + percentage + surcharge |
| Staffel vast | Tiered brackets with fixed amounts per bracket | 0-50K: EUR 500, 50K-250K: EUR 1,200 |

### Verordening Structure

```
Legesverordening (year, valid-from, valid-until)
+-- Titel 1: Algemene dienstverlening
|   +-- Hoofdstuk 1: Burgerzaken
|   |   +-- Artikel 1.1.1: Uittreksel GBA -- vast EUR 14,10
|   |   +-- Artikel 1.1.2: Rijbewijs -- vast EUR 41,50
|   +-- Hoofdstuk 2: ...
+-- Titel 2: Fysieke leefomgeving (Omgevingswet)
|   +-- Hoofdstuk 1: Omgevingsvergunning bouwactiviteit
|   |   +-- Artikel 2.1.1: Bouwkosten t/m EUR 50.000 -- staffel 3,00%
|   |   +-- Artikel 2.1.2: Bouwkosten EUR 50.001-250.000 -- staffel 2,50%
|   |   +-- Artikel 2.1.3: Bouwkosten > EUR 250.000 -- staffel 2,00%
|   +-- Hoofdstuk 2: ...
+-- Titel 3: Europese dienstenrichtlijn
```

### OpenRegister Schema Model

```
legesverordening:
  title: string             # "Legesverordening 2026"
  year: integer             # 2026
  validFrom: date           # 2026-01-01
  validUntil: date          # 2026-12-31
  status: enum              # draft | active | archived
  municipality: string      # gemeente identifier

artikel:
  verordening: reference    # -> legesverordening
  nummer: string            # "2.1.1"
  titel: string             # "Bouwkosten t/m EUR 50.000"
  hoofdstuk: string         # "2.1"
  type: enum                # vast | percentage | staffel | staffel_vast | maximum | minimum
  tarief: decimal           # 3.00 (percentage or fixed amount)
  grondslag: string         # "bouwkosten" (case property to calculate from)
  rangeMin: decimal         # 0 (for staffel)
  rangeMax: decimal         # 50000 (for staffel)
  maximumBedrag: decimal    # null or cap amount
  minimumBedrag: decimal    # null or floor amount
  caseTypes: array          # applicable case type IDs

berekening:
  case: reference           # -> case
  verordening: reference    # -> legesverordening
  status: enum              # concept | ter_accordering | definitief | gecorrigeerd | terugbetaald
  totalAmount: decimal      # 4750.00
  calculatedBy: string      # user UID
  calculatedAt: datetime    # timestamp
  approvedBy: string        # user UID (4-ogen)
  approvedAt: datetime      # timestamp
  version: integer          # 1, 2, 3...
  reason: string            # reason for correction/version
  lines: array              # -> array of berekeningsregel

berekeningsregel:
  artikel: reference        # -> artikel
  grondslag: string         # "bouwkosten"
  grondslagWaarde: decimal  # 180000.00
  rangeApplied: string      # "0 - 50000"
  tarief: decimal           # 3.00
  bedrag: decimal           # 1500.00
```

## ADDED Requirements
---

### Requirement: REQ-LEGES-01 — Fee Calculation on Case Attributes

The system MUST calculate leges based on case attributes (bouwkosten, activiteiten, oppervlakte) and the applicable legesverordening.

**Feature tier**: V1

#### Scenario: Staffel (tiered) calculation

- **GIVEN** a case "Omgevingsvergunning Bouw" with bouwkosten = EUR 180,000
- **AND** legesverordening 2026 with artikel 2.1.1: bouwkosten t/m EUR 50,000 at 3.00% and artikel 2.1.2: EUR 50,001-250,000 at 2.50%
- **WHEN** legesberekening is triggered via the case dashboard "Leges berekenen" button
- **THEN** the system MUST calculate: (50,000 x 3.00%) + (130,000 x 2.50%) = EUR 1,500 + EUR 3,250 = EUR 4,750
- **AND** the calculation MUST be stored as a `berekening` object in OpenRegister with berekeningsregels per artikel

#### Scenario: Fixed amount calculation

- **GIVEN** a case "Sloopmelding" matching artikel 3.2.1: vast bedrag EUR 250
- **WHEN** legesberekening is triggered
- **THEN** the system MUST return EUR 250 with reference to artikel 3.2.1
- **AND** a single berekeningsregel MUST be created with type "vast"

#### Scenario: Corrected construction costs

- **GIVEN** a case with declared bouwkosten = EUR 300,000
- **AND** the behandelaar corrects bouwkosten to EUR 220,000 (gecorrigeerde bouwsom)
- **WHEN** legesberekening is recalculated
- **THEN** the system MUST use the corrected amount EUR 220,000
- **AND** the calculation history MUST show both the original and corrected calculation as separate versions

#### Scenario: Percentage calculation

- **GIVEN** a case with bouwkosten = EUR 500,000
- **AND** artikel 2.5.1: percentage 2.4% of bouwkosten
- **WHEN** legesberekening is triggered
- **THEN** the system MUST calculate: 500,000 x 2.4% = EUR 12,000

#### Scenario: Maximum cap

- **GIVEN** a case with bouwkosten = EUR 5,000,000
- **AND** the staffel calculation yields EUR 125,000
- **AND** the verordening has a maximum cap of EUR 50,000
- **WHEN** legesberekening is triggered
- **THEN** the system MUST cap the amount at EUR 50,000
- **AND** the berekeningsregel MUST show: "Berekend bedrag: EUR 125.000, gemaximeerd op EUR 50.000"

---

### Requirement: REQ-LEGES-02 — Multiple Verordeningen Per Year

The system MUST support multiple legesverordeningen active in the same year (e.g., when rates change mid-year).

**Feature tier**: V2

#### Scenario: Select correct verordening by date

- **GIVEN** legesverordening 2026-A valid from 2026-01-01 to 2026-06-30
- **AND** legesverordening 2026-B valid from 2026-07-01 to 2026-12-31
- **AND** a case with startdatum = 2026-08-15
- **WHEN** legesberekening is triggered
- **THEN** the system MUST apply verordening 2026-B (active on the case start date)

#### Scenario: No verordening found

- **GIVEN** no active verordening exists for the case's start date
- **WHEN** legesberekening is triggered
- **THEN** the system MUST display an error: "Geen actieve legesverordening gevonden voor datum [startdatum]. Neem contact op met de beheerder."
- **AND** the calculation MUST NOT proceed

#### Scenario: Transitional cases

- **GIVEN** a case started on 2026-06-28 (under verordening 2026-A)
- **AND** verordening 2026-B takes effect on 2026-07-01
- **WHEN** legesberekening is triggered on 2026-07-05
- **THEN** the system MUST use verordening 2026-A (based on case start date, not calculation date)

---

### Requirement: REQ-LEGES-03 — Verrekening, Teruggaaf, and Corrections

The system MUST support deducting previously imposed fees, issuing refunds, and correcting calculations.

**Feature tier**: V1

#### Scenario: Deduct previously imposed leges

- **GIVEN** a case where leges of EUR 4,750 were previously imposed for a provisional permit
- **AND** the definitive permit has leges of EUR 6,200
- **WHEN** the behandelaar applies verrekening
- **THEN** the system MUST calculate the remaining amount: EUR 6,200 - EUR 4,750 = EUR 1,450
- **AND** the export MUST show the net amount EUR 1,450 with reference to the original assessment

#### Scenario: Refund on withdrawn application (teruggaaf)

- **GIVEN** a case with imposed leges of EUR 4,750
- **AND** the aanvrager withdraws the application before the besluit
- **WHEN** the behandelaar initiates teruggaaf
- **THEN** the system MUST generate a negative amount (EUR -4,750 or partial refund per verordening)
- **AND** the refund MUST be traceable in the calculation history
- **AND** the refund percentage MUST be configurable (some verordeningen allow only 75% refund)

#### Scenario: Correction with audit trail

- **GIVEN** a legesberekening with an error (wrong artikel applied)
- **WHEN** the behandelaar corrects the calculation
- **THEN** the original calculation MUST be preserved (not overwritten)
- **AND** the correction MUST be a new version with: reason, corrected by, timestamp
- **AND** the net difference MUST be exported to the financial system

#### Scenario: Multiple corrections

- **GIVEN** a case with 3 calculation versions: initial (EUR 4,750), correction (EUR 5,200), refund (EUR -2,600)
- **WHEN** viewing the leges panel on the case dashboard
- **THEN** all 3 versions MUST be visible with version numbers (v1, v2, v3)
- **AND** the net result (EUR 2,600) MUST be clearly displayed as the current effective amount

---

### Requirement: REQ-LEGES-04 — 4-Ogen Principe (Four-Eyes Approval)

The system MUST support requiring approval from a second person before a legesberekening becomes definitive.

**Feature tier**: V2

#### Scenario: Require second approval

- **GIVEN** a legesberekening of EUR 12,500 on case "2026-089"
- **AND** the case type requires 4-ogen principe for leges above EUR 5,000
- **WHEN** the behandelaar submits the calculation
- **THEN** the status MUST be set to "Ter accordering"
- **AND** a task MUST be created for the configured approver (teamleider or financieel medewerker)
- **AND** the leges MUST NOT be exported until approved

#### Scenario: Approve legesberekening

- **GIVEN** a pending legesberekening "Ter accordering"
- **WHEN** the approver reviews and approves the calculation
- **THEN** the status MUST change to "Definitief"
- **AND** the audit trail MUST record: calculated by, approved by, timestamps
- **AND** the calculation MUST now be eligible for export

#### Scenario: Reject legesberekening

- **GIVEN** a pending legesberekening "Ter accordering"
- **WHEN** the approver rejects the calculation with reason "Verkeerd tarief toegepast"
- **THEN** the status MUST change to "Afgekeurd"
- **AND** the behandelaar MUST receive a notification with the rejection reason
- **AND** the behandelaar MUST be able to create a corrected version

#### Scenario: Threshold configuration

- **GIVEN** the beheerder configures 4-ogen thresholds per case type
- **WHEN** setting threshold to EUR 5,000 for "Omgevingsvergunning"
- **THEN** calculations below EUR 5,000 MUST proceed directly to "Definitief"
- **AND** calculations at or above EUR 5,000 MUST require approval

---

### Requirement: REQ-LEGES-05 — Export to Financial System

The system MUST support exporting legesberekeningen to the municipality's financial system. Export is always to an external system -- Procest does NOT handle payment or invoicing.

**Feature tier**: V1

#### Scenario: Generate export file

- **GIVEN** 5 definitieve legesberekeningen ready for export
- **WHEN** the beheerder triggers a periodic export via the leges admin panel
- **THEN** the system MUST generate an export containing per record: NAW-gegevens, BSN/KvK debiteur, zaaknummer, leges artikelnummer, omschrijving, bedrag, datum beschikking
- **AND** the export format MUST be configurable: ASCII (flat file), XML, CSV, or StUF-FIN

#### Scenario: API export to Key2Financien

- **GIVEN** an OpenConnector adapter configured for Key2Financien (Centric)
- **WHEN** a legesberekening is marked definitief
- **THEN** the system MUST support automatic push via StUF-FIN or REST API
- **AND** the financial system reference number MUST be stored back on the berekening object
- **AND** the export status MUST be tracked: "Te exporteren", "Geexporteerd", "Fout bij export"

#### Scenario: Supported export targets

- The system MUST support export to common financial systems via configurable adapters:
  - Key2Financien (Centric) -- StUF-FIN or export file
  - Civision Innen (PinkRoccade) -- Centraal Facturen koppelvlak
  - iFinancieen (Centric) -- Export/API
  - Unit4Financials -- ZGW-API
  - Generic CSV/ASCII for other systems

#### Scenario: Export batch management

- **GIVEN** the beheerder opens the export management screen
- **THEN** the system MUST show: pending exports count, last export date, export history
- **AND** each export batch MUST be downloadable as a file
- **AND** failed exports MUST be retryable individually

---

### Requirement: REQ-LEGES-06 — Verordening Administration

The system MUST support administering legesverordeningen so that fee calculations stay current.

**Feature tier**: V1

#### Scenario: Import verordening from Excel

- **GIVEN** a new legesverordening 2027 prepared in Excel format with columns: artikelnummer, titel, type (vast/percentage/staffel), tarief, grondslag, range_min, range_max, maximum, minimum
- **WHEN** the beheerder imports the Excel file via the admin panel
- **THEN** the system MUST parse artikelen, tarieven, grondslagen, and staffels
- **AND** the verordening MUST be created in draft status for review before activation
- **AND** import errors MUST be reported per row: "Rij 15: ongeldig tarief '3,00%' -- gebruik decimaal getal (3.00)"

#### Scenario: Test verordening before production

- **GIVEN** a draft legesverordening 2027
- **WHEN** the beheerder runs a test calculation on a sample case
- **THEN** the system MUST show the calculated amount using the draft verordening
- **AND** the test MUST NOT affect the actual case or produce exportable records
- **AND** the test result MUST show a comparison with the active verordening (if available)

#### Scenario: Activate verordening

- **GIVEN** a draft verordening "2027" that has been reviewed
- **WHEN** the beheerder clicks "Activeren"
- **THEN** the verordening status MUST change from "draft" to "active"
- **AND** any previously active verordening for the same date range MUST be archived
- **AND** a confirmation dialog MUST warn: "Dit activeert de verordening voor alle nieuwe berekeningen vanaf [validFrom]"

#### Scenario: Manual artikel editing

- **GIVEN** an active verordening
- **WHEN** the beheerder needs to correct a tarief (e.g., typo: 2.50% should be 2.55%)
- **THEN** the system MUST allow editing individual artikelen
- **AND** the edit MUST be logged in the audit trail: "Artikel 2.1.2 tarief gewijzigd van 2.50% naar 2.55% door [beheerder]"
- **AND** existing calculations MUST NOT be retroactively recalculated (only new calculations use the updated tarief)

---

### Requirement: REQ-LEGES-07 — Calculation Version History

The system MUST maintain a complete version history of all calculations per case, supporting accountantscontrole (audit by external accountant) and rechtmatigheidsverantwoording.

**Feature tier**: V2

#### Scenario: Version history for accountability

- **GIVEN** a case with 3 calculation versions: initial (EUR 4,750), correction (EUR 5,200), refund (EUR -2,600)
- **WHEN** an accountant reviews the case
- **THEN** all 3 versions MUST be visible with: timestamp, calculated by, approved by (if 4-ogen), reason for change
- **AND** the net result (EUR 2,600) MUST be clearly shown

#### Scenario: Export version history as PDF

- **GIVEN** a case with multiple calculation versions
- **WHEN** the beheerder clicks "Exporteer berekening"
- **THEN** the system MUST generate a PDF containing: verordening reference, all berekeningsregels per version, totals, audit information
- **AND** the PDF MUST be suitable for archiving under the Archiefwet

#### Scenario: Immutable history

- **GIVEN** a definitief legesberekening (version 1)
- **WHEN** a correction is needed
- **THEN** the system MUST NOT modify version 1
- **AND** a new version 2 MUST be created with the corrected values
- **AND** version 1 MUST remain accessible and unmodified

---

### Requirement: REQ-LEGES-08 — Case Dashboard Integration

The legesberekening MUST be accessible from the case dashboard as a dedicated panel.

**Feature tier**: V1

#### Scenario: Leges panel on case dashboard

- **GIVEN** a case of type "Omgevingsvergunning" (which has legesberekening enabled)
- **WHEN** the behandelaar views the case dashboard
- **THEN** a "Leges" panel MUST be displayed showing:
  - Current effective amount (or "Niet berekend" if no calculation exists)
  - Status (concept/ter_accordering/definitief)
  - Button "Leges berekenen" (if no calculation) or "Herberekenen" (if calculation exists)

#### Scenario: Calculation breakdown in panel

- **GIVEN** a definitief legesberekening of EUR 4,750
- **WHEN** the behandelaar expands the leges panel
- **THEN** the breakdown MUST show per berekeningsregel: artikel nummer, omschrijving, grondslag, tarief, bedrag
- **AND** the total MUST be shown at the bottom with EUR 4,750

#### Scenario: Trigger calculation from dashboard

- **GIVEN** a case with bouwkosten property filled in
- **WHEN** the behandelaar clicks "Leges berekenen"
- **THEN** the system MUST fetch the applicable verordening
- **AND** calculate the leges using the calculation service
- **AND** display the result in the leges panel immediately
- **AND** store the berekening in OpenRegister

#### Scenario: Case type without leges

- **GIVEN** a case type "Klacht" that has no legesberekening configured
- **WHEN** viewing the case dashboard
- **THEN** the leges panel MUST NOT be rendered

---

### Requirement: REQ-LEGES-09 — Samenloop (Combined Activities)

The system MUST handle samenloop (combined activities) where a single case has multiple activities each with their own fee calculation, and specific samenloopregels determine the total.

**Feature tier**: V1

#### Scenario: Multiple activities with individual fees

- **GIVEN** a case with activities: "Bouwen" (leges EUR 4,750), "Kappen" (leges EUR 150), "Uitrit" (leges EUR 350)
- **WHEN** legesberekening is triggered
- **THEN** the system MUST calculate each activity's leges separately
- **AND** the total MUST be the sum: EUR 4,750 + EUR 150 + EUR 350 = EUR 5,250

#### Scenario: Samenloop discount

- **GIVEN** a verordening with samenloopkorting: "Bij 3 of meer activiteiten: 10% korting op het totaal"
- **AND** a case with 3 activities totaling EUR 5,250
- **WHEN** legesberekening applies samenloopregels
- **THEN** the discount MUST be calculated: 10% x EUR 5,250 = EUR 525
- **AND** the final amount MUST be: EUR 5,250 - EUR 525 = EUR 4,725

#### Scenario: Activity-specific rules

- **GIVEN** activity "Bouwen" has a separate staffel calculation based on bouwkosten
- **AND** activity "Kappen" has a fixed fee of EUR 75 per boom
- **AND** the case specifies 2 bomen to be kapped
- **WHEN** calculating the "Kappen" fee
- **THEN** the system MUST calculate: 2 x EUR 75 = EUR 150

---

### Requirement: REQ-LEGES-10 — Rounding and Precision

The system MUST apply consistent rounding rules to all calculations.

**Feature tier**: V1

#### Scenario: Standard rounding

- **GIVEN** a staffel calculation yielding EUR 4,749.50
- **WHEN** rounding is applied
- **THEN** the system MUST round to the nearest whole euro: EUR 4,750 (per VNG modelverordening)

#### Scenario: Intermediate calculations

- **GIVEN** a multi-bracket staffel calculation
- **WHEN** calculating per bracket
- **THEN** intermediate results MUST use full precision (no rounding per bracket)
- **AND** rounding MUST only be applied to the final total

#### Scenario: Minimum fee

- **GIVEN** a percentage calculation yielding EUR 12.50
- **AND** the minimum fee for this artikel is EUR 150
- **WHEN** the calculation completes
- **THEN** the system MUST apply the minimum: EUR 150

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Leges are calculated on cases.
- **VTH Module spec** (`../vth-module/spec.md`): Legesberekening is triggered during VTH permit workflow.
- **Zaak Intake Flow spec** (`../zaak-intake-flow/spec.md`): Bouwkosten imported from DSO intake.
- **Case Dashboard View spec** (`../case-dashboard-view/spec.md`): Leges panel on case detail.
- **OpenRegister**: Verordeningen, artikelen, and calculations stored as OpenRegister objects.
- **OpenConnector**: Financial system export adapters (StUF-FIN, Key2Financien, Civision Innen).
- **BAG mock register**: Oppervlakte data for fee calculations based on floor area.

### Using Mock Register Data

This spec depends on the **BAG** mock register for oppervlakte (floor area) data used in fee calculations.

**Loading the register:**
```bash
# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag", schemas: "nummeraanduiding", "verblijfsobject", "pand")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **Oppervlakte for fee calculation**: BAG `verblijfsobject` records include `oppervlakte` (floor area in m2) -- use these values in staffel calculations
- **Bouwkosten linked to BAG-object**: Link a BAG address to a permit case, then test fee calculation using the declared bouwkosten
- **Multiple gebruiksdoel types**: BAG records include woonfunctie, kantoorfunctie, winkelfunctie -- test different fee rates per building type

### Current Implementation Status

**Not yet implemented.** No legesberekening-related schemas, controllers, services, or Vue components exist in the Procest codebase. There are no schemas for legesverordening, artikelen, tarieven, or berekeningen in `procest_register.json`.

**Foundation available:**
- Case properties infrastructure (`case_property_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`) could store calculated leges amounts as case properties (e.g., `bouwkosten`, `legesbedrag`).
- Property definitions (`property_definition_schema`) could define case type-specific fee-relevant fields.
- The object store with `auditTrailsPlugin` provides version tracking for calculation history.
- OpenConnector (external dependency) could host financial system export adapters.
- The case detail view (`CaseDetail.vue`) could display a "Leges" panel using the existing `CnDetailCard` component pattern.
- Task management infrastructure could be used for 4-ogen approval tasks.
- `BrcController.php` demonstrates the ZGW Besluiten API pattern that could be extended for leges export notifications.

**Partial implementations:** None.

### Standards & References

- **VNG Modellegesverordening**: Standard fee ordinance template used by most Dutch municipalities; defines the tariff structure (titels, hoofdstukken, artikelen). Procest follows this structure in the OpenRegister schema model.
- **StUF-FIN**: XML-based standard for financial system integration in Dutch government.
- **GEMMA VTH-referentiecomponenten**: VTH055 (Legesberekening), VTH056 (Legesnota), VTH057 (Financiele afhandeling), VTH103, VTH117, VTH119.
- **Unie van Waterschappen Modelverordening**: Fee ordinance template for waterschappen.
- **Rechtmatigheidsverantwoording**: Dutch government accountability framework requiring transparent fee calculations with full version history.
- **Archiefwet**: Calculation records must be retained per archival requirements. PDF export supports this.
- **Key2Financien / Civision Innen / Unit4Financials / iFinancieen**: Common Dutch municipal financial systems targeted for export.
- **DMN 1.3**: Flowable uses DMN decision tables for fee calculations; Procest implements equivalent logic in PHP but could expose DMN-compatible rule definitions in the future.

### Specificity Assessment

This is a well-specified domain spec with concrete calculation examples, a clear verordening structure model, and defined OpenRegister schemas.

**Strengths:** Clear calculation type taxonomy (vast, percentage, staffel, maximum, minimum, combinatie, staffel_vast). Concrete arithmetic examples with exact amounts. Verordening hierarchy diagram. Financial system export targets listed. OpenRegister schema model defined. Samenloop rules specified. Rounding rules defined.

**Resolved ambiguities:**
- Calculation engine is implemented as a PHP service (not n8n workflow), for precision and auditability.
- Verordeningen use date-based activation with validFrom/validUntil fields.
- The spec now supports per-article exemptions via the `caseTypes` field on artikelen.
- Mid-year verordening changes are handled by case start date matching (REQ-LEGES-02c).
- Calculation precision uses full decimal precision with rounding only on final totals (REQ-LEGES-10).
- Excel import format is specified with column definitions (REQ-LEGES-06a).
- 4-ogen threshold is configurable per case type (REQ-LEGES-04d).
