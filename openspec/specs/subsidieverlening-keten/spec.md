---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# subsidieverlening-keten Specification

## Purpose
TBD - created by archiving change subsidieverlening-keten. Update Purpose after archive.
## Requirements
### Requirement: REQ-SUB-001 Multi-year beschikking with voorschot-schema

The system SHALL support grant decisions spanning multiple years with a structured schedule of advance disbursements.

#### Scenario: Voorschot-schema definition and validation
- GIVEN a beschikking being drafted with looptijd 2026-01-01 to 2028-12-31 and verleend_bedrag €450.000
- WHEN the behandelaar adds a voorschot-schema of three €120.000 yearly advances plus a €90.000 nabetaling op vaststelling
- THEN the system MUST validate that the total scheduled disbursements equal the verleend_bedrag and reject the beschikking if the sum does not match
- AND when valid and saved, the system MUST record each voorschot with planned date, amount, and condition in the `voorschot_schema` JSON array

#### Scenario: Conditional disbursement triggering
- GIVEN a beschikking verleend with a voorschot scheduled for 2027-01-15 conditional on Q4-2026 tussenrapportage approval
- WHEN the scheduled date arrives but the tussenrapportage is not yet beoordeeld
- THEN the system MUST NOT trigger the disbursement signal and MUST notify the behandelaar that the condition is unmet
- AND when all conditions are satisfied, the system MUST emit a `VoorschotReadyEvent` with bedrag, planned date, and financial back-office integration ID

#### Scenario: Financial back-office integration
- GIVEN a voorschot approved for disbursement
- WHEN the system signals the financial back-office via OpenConnector and the betalings-integration
- THEN the system MUST record the disbursement reference, expected payment date, and mark the voorschot status "in betaling" with timestamp and ERP transaction ID
- AND when the back-office confirms payment, the system MUST update the voorschot status to "betaald" and link the actual payment date

### Requirement: REQ-SUB-002 AWB termijn-binding for each phase

The system SHALL enforce Dutch administrative-law deadlines for each subsidy lifecycle phase via the shared termijnbewaking engine.

#### Scenario: Termijn-counter binding on aanvraag registration
- GIVEN a SubsidieAanvraag registered under regeling "Innovatiefonds 2026"
- WHEN the aanvraag is created with caseType "SubsidieAanvraag" and registered in OpenRegister
- THEN the system MUST create a termijn-counter (regeling-specific duur per AWB 4:13, start = registration date, deadline computed with working-day math, linked zaak, assigned behandelaar)

#### Scenario: Tussenrapportage termijn
- GIVEN a tussenrapportage created with rapportage_periode_eind = 2026-12-31
- WHEN it is marked "verwacht"
- THEN the system MUST bind a termijn-counter (regeling-defined duur or 8 weeks default, deadline = periode_eind + duur, linked to this tussenrapportage)

#### Scenario: Vaststelling termijn
- GIVEN a vaststellings-aanvraag is submitted
- WHEN the system registers it
- THEN the system MUST start a 22-week beoordelings-termijn per AWB 4:13 complex procedure (deadline = indiening + 22 weeks, linked to SubsidieVaststelling)

#### Scenario: Termijn-expiration warnings
- GIVEN a termijn approaching expiration with less than two weeks remaining
- WHEN the daily termijn-scan runs
- THEN the system MUST notify the behandelaar and teamleider with identifier, days remaining, and action required
- AND when a termijn is past expiration the nightly scan MUST flag "Termijn verstreken" and escalate to manager level

### Requirement: REQ-SUB-003 Verplichtingen-tracking and substantiation

The system SHALL track conditions attached to a grant decision and link required evidence to each condition.

#### Scenario: Verplichting definition
- GIVEN a beschikking with a verplichting "minimaal 50 deelnemers in jaar 1, te bewijzen met deelnemerslijst"
- WHEN the beschikking is created
- THEN the system MUST register the verplichting with beschrijving, initial status "open", bewijsstukken_vereist, deadline, and a verplichting_id UUID

#### Scenario: Evidence linking to verplichting
- GIVEN a subsidie-ontvanger submits a tussenrapportage with a deelnemerslijst attached
- WHEN the tussenrapportage is marked "ingediend"
- THEN the system MUST surface the matching verplichting and its required bewijsstukken in the TussenrapportageDetail view with the status of each linked bewijsstuk

#### Scenario: Verplichting status on vaststelling
- GIVEN a verplichting remains "niet-voldaan" at final settlement
- WHEN the vaststellings-beslissing is prepared
- THEN the system MUST flag it as a korting-grond on the definitive subsidiebedrag and MUST require the behandelaar to either lower het vastgesteld_bedrag proportionally or grant a waiver with motivation recorded in the audit trail

### Requirement: REQ-SUB-004 Tussenrapportage as typed sub-zaak

The system SHALL model interim reports as independent case objects with their own lifecycle, assessment workflow, and deadline.

#### Scenario: Automatic tussenrapportage creation
- GIVEN a beschikking with tussenrapportage-frequentie "jaarlijks per kalenderjaar"
- WHEN the year change passes (or a milestone date is reached)
- THEN the system MUST create a Tussenrapportage-zaak with status "verwacht", periode dates from the regeling schedule, link to SubsidieUitvoering, and a bound termijn-counter

#### Scenario: Tussenrapportage submission and assessment
- GIVEN a tussenrapportage ingediend by the subsidie-ontvanger
- WHEN de behandelaar approves it (status "goedgekeurd")
- THEN the system MUST advance SubsidieUitvoering status, trigger conditional voorschotten (emit `VoorschotReadyEvent`), notify the applicant, and record beoordelaar and date

#### Scenario: Partial approval and corrections
- GIVEN a tussenrapportage with some items approved and some requiring rework
- WHEN de behandelaar marks status "gedeeltelijk_goedgekeurd" requesting rework
- THEN the system MUST keep that status, notify the applicant with required corrections, allow resubmission (reverting to "ingediend"), and track resubmissions in the audit trail

### Requirement: REQ-SUB-005 Vaststelling with optional terugvordering

The system SHALL finalize the grant with an actual-cost review and automatically create a clawback case when overpayment occurred.

#### Scenario: Settlement calculation
- GIVEN a beschikking verleend €450.000, three voorschotten of €120.000 paid (€360.000), and a vaststellings-beoordeling of €330.000
- WHEN the vaststellingsbeschikking is issued
- THEN the system MUST record werkelijke_kosten_totaal €330.000, calculate overpayment €30.000, set trigger_terugvordering = true, and emit `OverpaymentDetectedEvent`

#### Scenario: Automatic terugvordering case creation
- GIVEN trigger_terugvordering = true after the vaststellingsbeschikking is finalized
- WHEN the automatic terugvordering workflow executes
- THEN the system MUST create a Terugvordering-zaak (bedrag €30.000, grondslag AWB 4:57, status "opgelegd", bezwaartermijn 6 weeks, betaaltermijn 4 weeks, linked to SubsidieUitvoering)
- AND when a manager approves or amends it, the system MUST record the approval and allow it to proceed to "opgelegd"

#### Scenario: Terugvordering inning and rente
- GIVEN a terugvordering remains unpaid after bezwaartermijn and betaaltermijn pass
- WHEN the invorderingstermijn expires
- THEN the system MUST calculate invorderingsrente per AWB 4:97, record bedrag + rente, send a payment-plus-rente reminder, and escalate to deurwaarder via OpenConnector if still unpaid

### Requirement: REQ-SUB-006 Subsidieregister publication feed

The system SHALL expose all awarded and settled grants in a structured, machine-readable feed per Dutch open-data requirements.

#### Scenario: Beschikking publication
- GIVEN a beschikking is onherroepelijk (bezwaartermijn expired without bezwaar, or bezwaar rejected)
- WHEN the daily register-publication job runs
- THEN the system MUST include the subsidie with regeling, anonymized ontvanger per AVG, bedrag, looptijd (ISO 8601), doel, dates, and status "verleend"

#### Scenario: Vaststelling status update
- GIVEN a vaststellingsbeschikking is finalized and published
- WHEN the register-publication job runs
- THEN the system MUST update the record from "verleend" to "vastgesteld" with vastgesteld_bedrag, settlement date, and any publicly visible remarks

#### Scenario: Feed format and delivery
- GIVEN an administrator configures the subsidieregister feed endpoint
- WHEN `GET /api/subsidies/register/export` is called with optional filters
- THEN the system MUST return a JSON array matching the VNG subsidieregister schema (`@context`, `@type`, `items[]`, `totalItems`, `dateModified`, pagination)
- AND the data MUST be consumable as standard JSON and support linked-data integration

### Requirement: REQ-SUB-007 Bewijsstukken-management with bewaartermijn

The system SHALL manage evidence documents with type-specific validation, retention-period tracking, and automatic archival handover.

#### Scenario: Document type detection and retention assignment
- GIVEN a Bewijsstuk uploaded with a tussenrapportage
- WHEN the document is ingested
- THEN the system MUST detect bewijsstuk_type (or allow whitelist selection), retrieve bewaartermijn from regeling config, calculate bewaartermijn_einde, set archief_status "actief", and record bestand_hash_sha256

#### Scenario: Archival handover to Docudesk
- GIVEN a subsidie-zaak is afgerond and the bewaartermijn for all bewijsstukken is reached
- WHEN the nightly archief-trigger runs
- THEN the system MUST bundle all bewijsstukken with metadata, convert to PDF/A via Docudesk, transfer to the Docudesk archief-handover with retention code, and mark archief_status "gearchiveerd"

#### Scenario: Document integrity and provenance
- GIVEN a Bewijsstuk stored in Nextcloud Files
- WHEN the document is linked to the subsidie
- THEN the system MUST record file properties, verify the SHA-256 hash on every read, lock the document once linked to vaststelling, and audit all access for BIO compliance

### Requirement: REQ-SUB-008 Cofinanciering and EU staatssteun checks

The system SHALL support grants with co-financing and ensure compliance with EU state-aid rules (de-minimis, AGVV, DAEB).

#### Scenario: De-minimis threshold checking
- GIVEN an aanvraag above the de-minimis threshold (€300.000 per three years per onderneming) per Verordening 1407/2013
- WHEN de behandelaar assesses the aanvraag
- THEN the system MUST require a staatssteun-rechtsgrond field, call `StatesteunClassifier.checkDeMinimis(...)`, flag AGVV/notification if cumulative exceeds the threshold, and display a warning

#### Scenario: AGVV classification and TAM-melding
- GIVEN a beschikking under AGVV (Verordening 651/2014) with an eligible artikel
- WHEN the beschikking is published
- THEN the system MUST call `StatesteunClassifier.classifyAGVV(...)`, generate an AGVV-melding per the TAM register, emit `AgvvMeldingReadyEvent` to OpenConnector, and record the TAM reference
- AND when the ministry confirms receipt, the system MUST mark the beschikking "EU_reporting_complete"

#### Scenario: Cofinanciering validation
- GIVEN a beschikking with co-financing from multiple parties
- WHEN the beschikking is created
- THEN the system MUST validate that the sum of cofinanciering + gemeente subsidie equals 100% (or documented partial funding), each party has identity and bedrag, and EU regulations are compatible
- AND when validation fails the error MUST specify "Cofinanciering sum (€X) does not equal project total (€Y); please reconcile"

### Requirement: REQ-SUB-009 Wijzigingsbeschikking workflow

The system SHALL support amendments to existing grant decisions with audit-trail linking.

#### Scenario: Wijziging request and basis selection
- GIVEN a subsidie-ontvanger requests a project-period extension
- WHEN de behandelaar initiates a wijzigingsbeschikking
- THEN the system MUST retrieve the original beschikking, create a new SubsidieBeschikking with beschikkingtype "wijzigingsbeschikking", set trekt_in_besluit to the original UUID, deep-copy fields, and display a diff view

#### Scenario: Amendment change tracking
- GIVEN de behandelaar edits looptijd_eind from 2026-12-31 to 2027-12-31
- WHEN the wijziging is saved
- THEN the system MUST record original value, new value, a required wijzigingsreden, and behandelaar/timestamp

#### Scenario: Publication and effect
- GIVEN a wijzigingsbeschikking is onherroepelijk
- WHEN it takes legal effect
- THEN the system MUST update SubsidieUitvoering to the new conditions, recalculate affected termijn-counters and voorschot disbursements, notify the grantee, and record the wijziging in the feed with a `previousDecisionId` reference

### Requirement: REQ-SUB-010 Reporting and dashboards

The system SHALL provide standard management and accountability reports exported in CSV and PDF formats.

#### Scenario: Quarterly financial report
- GIVEN the end of a quarter approaches
- WHEN the financial controller requests `GET /api/subsidies/reports/quarterly?quarter=Q1&year=2026`
- THEN the system MUST return a PDF with totaal verleend per regeling per year, totaal uitgekeerd, totaal vastgesteld, openstaande voorschotten, lopende terugvorderingen, overdue verplichtingen, and KPIs

#### Scenario: Audit sampling and dossier export
- GIVEN an accountant performs a sample check
- WHEN they select a sample of 30 dossiers
- THEN the system MUST, via `POST /api/subsidies/reports/audit-export`, deliver a ZIP containing per-dossier beschikking.pdf, bewijsstukken/, audit_trail.csv, metadata.json, plus a manifest.csv and report_metadata.json

