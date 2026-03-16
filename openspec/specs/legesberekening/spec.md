# Legesberekening Specification

## Purpose

Legesberekening is the rules engine that calculates municipal fees (leges) on permit cases. It applies the gemeentelijke legesverordening -- typically based on the VNG modellegesverordening -- to case attributes and produces a calculated amount. The module does NOT handle payment or invoicing; it calculates and exports to the financial system.

**Tender demand**: Found as explicit requirement in 16 VTH tenders. Every VTH tender requires financial system export. Legesberekening is the #1 VTH-specific functional requirement after DSO integration.
**Standards**: VNG Modellegesverordening, Unie van Waterschappen modelverordening (for waterschappen), StUF-FIN, GEMMA VTH-referentiecomponenten (VTH055-VTH057, VTH103, VTH117, VTH119)
**Feature tier**: V1 (basic calculation, single verordening, manual export), V2 (multiple verordeningen, automatic DSO import, 4-ogen principe, versioned calculations, financial system connectors)

## Calculation Model

### Fee Calculation Types

| Type | Description | Example |
|------|-------------|---------|
| Vast bedrag | Fixed amount per application | Sloopmelding: EUR 250 |
| Percentage | Percentage of bouwkosten | 2.4% of declared construction costs |
| Staffel | Tiered brackets with different rates per bracket | 0-50K: 3%, 50K-250K: 2.5%, 250K+: 2% |
| Maximum | Fee capped at a maximum amount | Leges max EUR 50,000 |
| Combinatie | Multiple calculation types combined | Base fee + percentage + surcharge |

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

## Requirements

---

### REQ-LEGES-01: Fee Calculation on Case Attributes

**Feature tier**: V1

The system MUST calculate leges based on case attributes (bouwkosten, activiteiten, oppervlakte) and the applicable legesverordening.

#### Scenario LEGES-01a: Staffel (tiered) calculation

- GIVEN a case "Omgevingsvergunning Bouw" with bouwkosten = EUR 180,000
- AND legesverordening 2026 with artikel 2.1.1: bouwkosten t/m EUR 50,000 at 3.00% and artikel 2.1.2: EUR 50,001-250,000 at 2.50%
- WHEN legesberekening is triggered
- THEN the system MUST calculate: (50,000 x 3.00%) + (130,000 x 2.50%) = EUR 1,500 + EUR 3,250 = EUR 4,750
- AND the calculation MUST be stored on the case with a breakdown per artikel

#### Scenario LEGES-01b: Fixed amount calculation

- GIVEN a case "Sloopmelding" matching artikel 3.2.1: vast bedrag EUR 250
- WHEN legesberekening is triggered
- THEN the system MUST return EUR 250 with reference to artikel 3.2.1

#### Scenario LEGES-01c: Corrected construction costs

- GIVEN a case with declared bouwkosten = EUR 300,000
- AND the behandelaar corrects bouwkosten to EUR 220,000 (gecorrigeerde bouwsom)
- WHEN legesberekening is recalculated
- THEN the system MUST use the corrected amount EUR 220,000
- AND the calculation history MUST show both the original and corrected calculation

---

### REQ-LEGES-02: Multiple Verordeningen Per Year

**Feature tier**: V2

The system MUST support multiple legesverordeningen active in the same year (e.g., when rates change mid-year).

#### Scenario LEGES-02a: Select correct verordening by date

- GIVEN legesverordening 2026-A valid from 2026-01-01 to 2026-06-30
- AND legesverordening 2026-B valid from 2026-07-01 to 2026-12-31
- AND a case with startdatum = 2026-08-15
- WHEN legesberekening is triggered
- THEN the system MUST apply verordening 2026-B (active on the case start date)

---

### REQ-LEGES-03: Verrekening, Teruggaaf, and Corrections

**Feature tier**: V1

The system MUST support deducting previously imposed fees, issuing refunds, and correcting calculations.

#### Scenario LEGES-03a: Deduct previously imposed leges

- GIVEN a case where leges of EUR 4,750 were previously imposed for a provisional permit
- AND the definitive permit has leges of EUR 6,200
- WHEN the behandelaar applies verrekening
- THEN the system MUST calculate the remaining amount: EUR 6,200 - EUR 4,750 = EUR 1,450
- AND the export MUST show the net amount EUR 1,450 with reference to the original assessment

#### Scenario LEGES-03b: Refund on withdrawn application (teruggaaf)

- GIVEN a case with imposed leges of EUR 4,750
- AND the aanvrager withdraws the application before the besluit
- WHEN the behandelaar initiates teruggaaf
- THEN the system MUST generate a negative amount (EUR -4,750 or partial refund per verordening)
- AND the refund MUST be traceable in the calculation history

#### Scenario LEGES-03c: Correction with audit trail

- GIVEN a legesberekening with an error (wrong artikel applied)
- WHEN the behandelaar corrects the calculation
- THEN the original calculation MUST be preserved (not overwritten)
- AND the correction MUST be a new version with: reason, corrected by, timestamp
- AND the net difference MUST be exported to the financial system

---

### REQ-LEGES-04: 4-Ogen Principe (Four-Eyes Approval)

**Feature tier**: V2

The system MUST support requiring approval from a second person before a legesberekening becomes definitive.

#### Scenario LEGES-04a: Require second approval

- GIVEN a legesberekening of EUR 12,500 on case "2026-089"
- AND the case type requires 4-ogen principe for leges above EUR 5,000
- WHEN the behandelaar submits the calculation
- THEN the status MUST be set to "Ter accordering"
- AND a task MUST be created for the configured approver
- AND the leges MUST NOT be exported until approved

#### Scenario LEGES-04b: Approve legesberekening

- GIVEN a pending legesberekening "Ter accordering"
- WHEN the approver reviews and approves the calculation
- THEN the status MUST change to "Definitief"
- AND the audit trail MUST record: calculated by, approved by, timestamps
- AND the calculation MUST now be eligible for export

---

### REQ-LEGES-05: Export to Financial System

**Feature tier**: V1

The system MUST support exporting legesberekeningen to the municipality's financial system. Export is always to an external system -- Procest does NOT handle payment or invoicing.

#### Scenario LEGES-05a: Generate export file

- GIVEN 5 definitieve legesberekeningen ready for export
- WHEN the beheerder triggers a periodic export
- THEN the system MUST generate an export containing per record: NAW-gegevens, BSN/KvK debiteur, zaaknummer, leges artikelnummer, omschrijving, bedrag, datum beschikking
- AND the export format MUST be configurable: ASCII (flat file), XML, CSV, or StUF-FIN

#### Scenario LEGES-05b: API export to Key2Financien

- GIVEN an OpenConnector adapter configured for Key2Financien (Centric)
- WHEN a legesberekening is marked definitief
- THEN the system MUST support automatic push via StUF-FIN or REST API
- AND the financial system reference number MUST be stored back on the case

#### Scenario LEGES-05c: Supported export targets

- The system MUST support export to common financial systems via configurable adapters:
  - Key2Financien (Centric) -- StUF-FIN or export file
  - Civision Innen (PinkRoccade) -- Centraal Facturen koppelvlak
  - iFinancieen (Centric) -- Export/API
  - Unit4Financials -- ZGW-API
  - Generic CSV/ASCII for other systems

---

### REQ-LEGES-06: Verordening Administration

**Feature tier**: V1

The system MUST support administering legesverordeningen so that fee calculations stay current.

#### Scenario LEGES-06a: Import verordening from Excel

- GIVEN a new legesverordening 2027 prepared in Excel format
- WHEN the beheerder imports the Excel file
- THEN the system MUST parse artikelen, tarieven, grondslagen, and staffels
- AND the verordening MUST be created in draft status for review before activation

#### Scenario LEGES-06b: Test verordening before production

- GIVEN a draft legesverordening 2027
- WHEN the beheerder runs a test calculation on a sample case
- THEN the system MUST show the calculated amount using the draft verordening
- AND the test MUST NOT affect the actual case or produce exportable records

---

### REQ-LEGES-07: Calculation Version History

**Feature tier**: V2

The system MUST maintain a complete version history of all calculations per case, supporting accountantscontrole (audit by external accountant) and rechtmatigheidsverantwoording.

#### Scenario LEGES-07a: Version history for accountability

- GIVEN a case with 3 calculation versions: initial (EUR 4,750), correction (EUR 5,200), refund (EUR -2,600)
- WHEN an accountant reviews the case
- THEN all 3 versions MUST be visible with: timestamp, calculated by, approved by (if 4-ogen), reason for change
- AND the net result (EUR 2,600) MUST be clearly shown

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Leges are calculated on cases.
- **VTH Module spec** (`../vth-module/spec.md`): Legesberekening is triggered during VTH permit workflow.
- **Zaak Intake Flow spec** (`../zaak-intake-flow/spec.md`): Bouwkosten imported from DSO intake.
- **OpenRegister**: Verordeningen and calculations stored as OpenRegister objects.
- **OpenConnector**: Financial system export adapters (StUF-FIN, Key2Financien, Civision Innen).
