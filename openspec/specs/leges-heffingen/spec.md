---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# leges-heffingen Specification

## Purpose
TBD - created by archiving change leges-heffingen. Update Purpose after archive.
## Requirements
### Requirement: REQ-LEGES-001 Import a tariff verordening from a raadsbesluit

The system SHALL allow municipalities to import an annual legesverordening from a decidesk raadsbesluit in a structured way, without per-line manual entry.

#### Scenario: Import interface and validation
- GIVEN an adopted raadsbesluit "Legesverordening 2026" in decidesk with attachment tarieventabel.xlsx
- WHEN a tax adviser (with `LEGES_IMPORT` permission) starts the import and selects the besluit id
- THEN the system MUST fetch besluit metadata (titel, vastgesteldOp, raadsbesluit-ref) from decidesk
- AND the system MUST parse the attachment table (XLSX/CSV) and validate hierarchical tariefnummer, required fields, BTW in {0,9,21}, and decimal amounts
- AND the system MUST create a legesTariefTabel record with status `concept`
- AND the system MUST show a diff versus the previous version and notify the finance department

#### Scenario: Multiple tariff changes per year
- GIVEN a municipality that amends the legesverordening twice a year (1 January and 1 July)
- WHEN a tax adviser imports a new verordening with `geldigVanaf: 2026-07-01`
- THEN the system MUST create a new legesTariefTabel version
- AND the system MUST close the previous version with `geldigTotEnMet: 2026-06-30`
- AND cases filed before 1 July MUST use the old tariffs and cases from 1 July MUST use the new ones

### Requirement: REQ-LEGES-002 Automatic tariff calculation on case creation

The system SHALL determine the correct leges amount automatically when a case is created, without manual intervention.

#### Scenario: Fixed tariff
- GIVEN a case type "Paspoort aanvraag" coupled to leges tariff "1.1.1: Paspoort €100" with a valid legesTariefTabel for the current year
- WHEN a citizen applies for a passport
- THEN LegesCalculationService MUST determine the tariff from the case-type coupling and compute €100,00
- AND the system MUST create a legesBerekening record with status `berekend` and show the amount with an expandable explanation

#### Scenario: Staffel tariff based on a case attribute
- GIVEN a case type "Omgevingsvergunning bouwactiviteit" coupled to tariff "2.3.1.1" (3% of bouwsom, min €350) and a case with `bouwsom: 250000`
- WHEN the case is created
- THEN LegesCalculationService MUST compute 3% × €250.000 = €7.500 and persist a legesBerekening with that amount
- AND the berekeningsToelichting MUST show "Bouwsom €250.000 × 3% = €7.500"

#### Scenario: Tariff fixed at the peildatum (case start date)
- GIVEN a case created on 20 December 2026 (verordening 2026 valid)
- WHEN the case is created
- THEN legesBerekening.berekendeOp MUST be 2026-12-20 and tariefTabelId MUST reference the 2026 verordening even when the case runs into 2027
- AND a later recalculation onto a newer verordening MUST require explicit motivation

### Requirement: REQ-LEGES-003 Variant selection based on case attributes

The system SHALL select the applicable tariff variant automatically based on case flags (e.g. regular vs spoed).

#### Scenario: Variant selection
- GIVEN a case type "Aanvraag rijbewijs" with variant A (regular) €48,75 and variant B (spoed) €67,50
- WHEN a citizen files an application with `spoedAanvraag: true`
- THEN LegesCalculationService MUST select variant B, compute €67,50, and record "Variant B toegepast: spoedaanvraag" in the berekeningsToelichting and case history

### Requirement: REQ-LEGES-004 Apply discounts and exemptions automatically

The system SHALL detect and apply adopted discount and exemption rules (age, minima, repeat application) automatically.

#### Scenario: Age-based exemption
- GIVEN a 67-year-old citizen renewing a rijbewijs and a discount "65-plus vrijstelling" with `kortingsType: volledige_vrijstelling` and conditions `{leeftijd: {min: 65}}`
- WHEN LegesCalculationService runs
- THEN the system MUST detect age via the BRP coupling, apply a €0 amount, and record the discount in appliedKortingen with its grondslag

#### Scenario: Percentage discount
- GIVEN an applicant eligible for "Herhaalaanvraag korting 25% (binnen 12 maanden)"
- WHEN the same applicant re-applies within 12 months
- THEN the system MUST detect the prior application and apply a 25% discount, recording it in appliedKortingen with grondslag "Herhaalaanvraag binnen 12 maanden"

### Requirement: REQ-LEGES-005 Create an invoice in shillinq accounts-receivable

The system SHALL create an invoice in shillinq AR once leges are calculated.

#### Scenario: Automatic invoice creation
- GIVEN a legesBerekening with status `berekend`, a citizen with NAW/BSN, and a valid shillinq installation
- WHEN the case handler triggers "Factuur verzenden" (or the configured wait elapses)
- THEN LegesShillinqService MUST send the AR request (debiteur, factuurregels, grootboekrekening, kostendrager, betalingstermijn, reference)
- AND on success the system MUST store the returned factuurId, set legesBerekening.status = `gefactureerd`, and notify the case

#### Scenario: Payment synchronisation
- GIVEN an invoice has been sent
- WHEN the citizen pays in shillinq
- THEN the shillinq webhook MUST update Procest, set legesBerekening.status = `betaald`, and show the payment date on the case detail page

### Requirement: REQ-LEGES-006 Refund on withdrawn application

The system SHALL determine a refund percentage automatically based on the case phase when an application is withdrawn.

#### Scenario: Refund staffel per phase
- GIVEN a gefactureerde and betaalde legesBerekening of €350, a withdrawn case, and a refund staffel (100% within 14 days, 75% until assessment start, 0% after decision)
- WHEN the case handler files a refund request and the phase is "In behandeling" (started on day 8)
- THEN LegesRestitutieService MUST determine 75% refund, compute €262,50, create a legesRestitutie record, send a credit-invoice request to shillinq AR, and notify the citizen

#### Scenario: Register refunds
- GIVEN multiple refunds per year
- WHEN a finance officer requests the audit report
- THEN the report MUST show per case the original amount, withdrawal date, refund percentage, refund amount, and credit-invoice reference for reconciliation

### Requirement: REQ-LEGES-007 Income-dependent minima exemption with verification

The system SHALL require income verification before applying a minima exemption; verification MAY be manual or via data sources.

#### Scenario: Minima verification workflow
- GIVEN a discount "Minima-vrijstelling uittreksel BRP" with conditions `{huishoudinkomen: {max: bijstandsnorm}}`
- WHEN an applicant requests a BRP uittreksel and indicates they are minima
- THEN LegesCalculationService MUST set legesBerekening.status = `pending_minima_check` and check whether `minima_registratie` is available (pipelinq coupling)
- AND when verification is approved the system MUST recalculate, apply the full exemption, set status `berekend`, and record administratively without sending an invoice

### Requirement: REQ-LEGES-008 Audit trail per calculation

The system SHALL provide full traceability per calculation: which verordening, tariff, variant, discounts, and why.

#### Scenario: Audit logging
- GIVEN each legesBerekening with appliedKortingen and berekeningsToelichting
- WHEN a controller/accountant reviews the calculation via the case detail page or audit export
- THEN the system MUST show the legesTariefTabel version used, the selected tariff and variant, all appliedKortingen with legal grondslag and satisfied conditions, the BTW percentage and amount, the grondslag values used, the initiator/timestamp, and any manual corrections with motivation

### Requirement: REQ-LEGES-009 Verordening preparation and review workflow

The system SHALL require a `concept` verordening to be reviewed and approved before it becomes `vastgesteld`.

#### Scenario: Concept to vastgesteld workflow
- GIVEN a legesTariefTabel with status `concept` (just imported)
- WHEN a finance officer opens the verordening page
- THEN the system MUST show the concept as ready for review with a diff versus the previous version, allow amendments and comments, and on "Goedkeuren" set status = `vastgesteld`
- AND from then on cases MUST be calculated against this verordening and all staff MUST be notified

