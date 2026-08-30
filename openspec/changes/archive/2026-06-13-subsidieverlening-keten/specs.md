# Specs: subsidieverlening-keten

## Overview

Detailed requirements for subsidieverlening-keten, covering multi-year grant execution, AWB deadline binding, condition tracking, interim reports, settlement procedures, clawback workflows, evidence document lifecycle, EU staatssteun compliance, amendment procedures, and regulatory reporting.

---

## REQ-SUB-001: Multi-year beschikking with voorschot-schema

**Purpose**: Support grant decisions spanning multiple years with a structured schedule of advance disbursements.

### REQ-SUB-001-A: Voorschot-Schema Definition and Validation
GIVEN a beschikking is being drafted with looptijd 2026-01-01 to 2028-12-31 and verleend_bedrag €450.000
WHEN the behandelaar adds a voorschot-schema of three €120.000 yearly advances plus a €90.000 nabetaling op vaststelling
THEN the system MUST validate that the total scheduled disbursements equal the verleend_bedrag and reject the beschikking if sum does not match

GIVEN a valid voorschot-schema totaling €450.000
WHEN the beschikking is saved
THEN the system MUST record each voorschot with planned date, amount, and condition (e.g. "after Q2 tussenrapportage approved") in the `voorschot_schema` JSON array

### REQ-SUB-001-B: Conditional Disbursement Triggering
GIVEN a beschikking is verleend with a voorschot scheduled for 2027-01-15 conditional on Q4-2026 tussenrapportage approval
WHEN the scheduled date arrives but the tussenrapportage is not yet beoordeeld
THEN the system MUST NOT trigger the disbursement signal to the financial back-office and MUST notify the behandelaar that the condition is unmet

GIVEN all conditions for a voorschot are satisfied (e.g., tussenrapportage approved on time)
WHEN the scheduled date arrives
THEN the system MUST emit a `VoorschotReadyEvent` with the voorschot bedrag, planned date, and financial back-office integration ID

### REQ-SUB-001-C: Financial Back-Office Integration
GIVEN a voorschot is approved for disbursement
WHEN the system signals the financial back-office via OpenConnector and the betalings-integration
THEN the system MUST record the disbursement reference, expected payment date, and mark the voorschot status as "in betaling" with timestamp and ERP transaction ID for later reconciliation

GIVEN the financial back-office confirms payment (via reconciliation import)
WHEN the confirmation is registered
THEN the system MUST update the voorschot status to "betaald" and link the actual payment date

---

## REQ-SUB-002: AWB termijn-binding for each phase

**Purpose**: Enforce Dutch administrative law deadlines for each subsidy lifecycle phase via the shared termijnbewaking engine.

### REQ-SUB-002-A: Termijn-Counter Binding on Aanvraag Registration
GIVEN a SubsidieAanvraag is registered under regeling "Innovatiefonds 2026"
WHEN the aanvraag is created with caseType = "SubsidieAanvraag" and registered in OpenRegister
THEN the system MUST create a termijn-counter object via the termijnbewaking engine with:
- Termijn duur: regeling-specific termijn (default 13 weeks per AWB 4:13; complex regelingen up to 22 weeks per AWB 4:13)
- Start datum: aanvraag registratie datum
- Deadline datum: calculated per regeling + working-day math
- Linked zaak: this SubsidieAanvraag
- Verantwoordelijke: assigned behandelaar

### REQ-SUB-002-B: Tussenrapportage Termijn
GIVEN a tussenrapportage is created with rapportage_periode_eind = 2026-12-31
WHEN the tussenrapportage is marked "verwacht" (expected due)
THEN the system MUST bind a termijn-counter with:
- Duur: regeling-defined tussenrapportage termijn (e.g., 30 days after periode eind) or 8 weeks default
- Deadline: rapportage_periode_eind + termijn_duur
- Linked object: this Tussenrapportage

### REQ-SUB-002-C: Vaststelling Termijn
GIVEN a vaststellings-aanvraag (settlement request) is submitted
WHEN the system registers the aanvraag
THEN the system MUST start a new 22-week beoordelings-termijn voor de vaststellingsbeschikking per AWB 4:13 complex procedure, with:
- Deadline: indiening_datum + 22 weeks
- Linked object: SubsidieVaststelling

### REQ-SUB-002-D: Termijn-Expiration Warnings
GIVEN a termijn is approaching expiration with less than two weeks remaining
WHEN the daily termijn-scan runs
THEN the system MUST notify the behandelaar AND the teamleider via the configured notification channel (email or portal) with:
- Case/object identifier and title
- Days remaining
- Action required (submit decision, approve report, etc.)

GIVEN a termijn is past expiration
WHEN the nightly scan runs
THEN the system MUST flag the case as "Termijn verstreken" and escalate to manager level

---

## REQ-SUB-003: Verplichtingen-tracking and substantiation

**Purpose**: Track conditions attached to a grant decision and link required evidence (bewijsstukken) to each condition.

### REQ-SUB-003-A: Verplichting Definition
GIVEN a beschikking has a verplichting "minimaal 50 deelnemers in jaar 1, te bewijzen met deelnemerslijst"
WHEN the beschikking is created
THEN the system MUST register the verplichting with:
- Beschrijving: text of condition
- Status: initial "open"
- Bewijsstukken_vereist: list of document types (e.g., "deelnemerslijst", "naamlijst_handtekeningen")
- Deadline: when condition must be met (often linked to tussenrapportage date)
- Verplichting_id: UUID for later linking

### REQ-SUB-003-B: Evidence Linking to Verplichting
GIVEN a subsidie-ontvanger submits a tussenrapportage met deelnemerslijst attached
WHEN the tussenrapportage is marked "ingediend"
THEN the system MUST surface the matching verplichting (by bewijsstukken_vereist filter) and its required bewijsstukken to the beoordelaar in een single pane of the TussenrapportageDetail view, with:
- Verplichting description
- Required bewijsstukken types
- Uploaded bewijsstukken linked to this tussenrapportage
- Status of each linked bewijsstuk (uploaded, validated, accepted)

### REQ-SUB-003-C: Verplichting Status on Vaststelling
GIVEN a verplichting blijft op status "niet-voldaan" at final settlement time
WHEN the vaststellings-beslissing wordt voorbereid
THEN the system MUST automatisch flag this verplichting as a korting-grond (reason for fee reduction) on het definitieve subsidiebedrag and MUST require the behandelaar to make an explicit decision:
- Option A: Lower het vastgesteld_bedrag proportionally to compensate for unmet condition
- Option B: Grant a waiver (afwijking) with explicit motivering recorded in audit trail

---

## REQ-SUB-004: Tussenrapportage as typed sub-zaak

**Purpose**: Model interim reports as independent case objects with their own lifecycle, assessment workflow, and deadline.

### REQ-SUB-004-A: Automatic Tussenrapportage Creation
GIVEN a beschikking has a tussenrapportage-frequentie "jaarlijks per kalenderjaar"
WHEN the year change passes (or milestone date is reached)
THEN the system MUST automatisch create a Tussenrapportage-zaak with:
- Status: "verwacht" (expected, not yet due)
- Rapportage_periode_start and rapportage_periode_eind: based on regeling schedule
- Linked to SubsidieUitvoering
- Termijn-counter bound for the reporting deadline

GIVEN a milestone-based frequency (e.g., "after 50% project completion")
WHEN an external trigger or manual marking sets the milestone as reached
THEN the system MUST create the next tussenrapportage with the applicable periode dates

### REQ-SUB-004-B: Tussenrapportage Submission and Assessment
GIVEN a tussenrapportage is ingediend by the subsidie-ontvanger
WHEN de behandelaar het beoordeelt en goedkeurt (status = "goedgekeurd")
THEN the system MUST:
- Update SubsidieUitvoering status: if all conditions met, advance to "tussenrapportage_beoordeeld"
- Trigger any voorwaardelijke voorschotten that depended on this tussenrapportage approval (emit `VoorschotReadyEvent`)
- Send notification to applicant: "Uw tussenrapportage is goedgekeurd"
- Record beoordelaar and beoordelingsdatum in audit trail

### REQ-SUB-004-C: Partial Approval and Corrections
GIVEN a tussenrapportage is submitted with some items approved and some requiring rework
WHEN de behandelaar marks status = "gedeeltelijk_goedgekeurd" with a beoordelingsoordeel requesting rework
THEN the system MUST:
- Keep the tussenrapportage status as "gedeeltelijk_goedgekeurd"
- Send notification to applicant with required corrections
- Allow applicant to resubmit (status reverts to "ingediend" after amendment)
- Track number of resubmissions in audit trail

---

## REQ-SUB-005: Vaststelling met optional terugvordering

**Purpose**: Finalize the grant with actual cost review and automatic clawback case creation if overpayment occurred.

### REQ-SUB-005-A: Settlement Calculation
GIVEN a beschikking with verleend €450.000, three voorschotten van €120.000 reeds uitgekeerd (total €360.000), en een vaststellings-beoordeling van €330.000 (€30.000 lager dan voorschotten-totaal)
WHEN the vaststellingsbeschikking wordt geslagen
THEN the system MUST:
- Record werkelijke_kosten_totaal = €330.000 in SubsidieVaststelling
- Calculate overpayment: €360.000 (betaalde voorschotten) - €330.000 (vastgesteld bedrag) = €30.000
- Set trigger_terugvordering = true
- Emit `OverpaymentDetectedEvent` with recovery amount

### REQ-SUB-005-B: Automatic Terugvordering Case Creation
GIVEN trigger_terugvordering = true after vaststellingsbeschikking is finalized
WHEN the automatic terugvordering workflow executes
THEN the system MUST create a Terugvordering-zaak with:
- Bedrag: €30.000 (overpayment amount)
- Wettelijke_grondslag: AWB 4:57 (clawback authority)
- Status: "opgelegd" (imposed)
- Bezwaartermijn_einde: 6 weeks from publication (calculated per AWB 4:47)
- Betaaltermijn_einde: 4 weeks from publication (standard inning termijn)
- Linked to SubsidieUitvoering for audit trail

GIVEN a manager reviews the auto-triggered terugvordering case
WHEN they approve or amend the bedrag/termijnen
THEN the system MUST record the approval in audit trail and allow the case to proceed to "opgelegd" status

### REQ-SUB-005-C: Terugvordering Inning and Rente
GIVEN a terugvordering remains onbetaald after bezwaartermijn and betaaltermijn pass
WHEN the invorderingstermijn verstrijkt
THEN the system MUST:
- Calculate invorderingsrente per AWB 4:97 (wettelijke rente, typically 6% per annum)
- Rente calculation: amount × rente_percentage × (days / 365) from original payment date
- Record bedrag + rente in the Terugvordering.invorderingsrente_berekend field
- Send betaalplusherinnering (payment + rente reminder) to grantee
- If still unpaid after final betaaltermijn, escalate to deurwaarder via OpenConnector integration

---

## REQ-SUB-006: Subsidieregister-publication feed

**Purpose**: Expose all awarded and settled grants in a structured, machine-readable feed per Dutch open data requirements.

### REQ-SUB-006-A: Beschikking Publication
GIVEN een beschikking is onherroepelijk (bezwaartermijn verstreken without bezwaar being filed OR bezwaar rejected)
WHEN the daily register-publication job runt
THEN the system MUST include the subsidie in the feed with:
- Regeling name and juridische grondslag
- Ontvanger: for organizations, the legal entity name; for individuals, anonymized per AVG (GDPR) richtlijn VNG (e.g., "Particuliere perso­on" or PseudoID)
- Bedrag: verleend_bedrag (EUR)
- Looptijd: start and end dates (format: ISO 8601)
- Doel: project title and brief description from case
- Aanvraag datum and beschikking datum
- Status: "verleend"

### REQ-SUB-006-B: Vaststelling Status Update
GIVEN a vaststellingsbeschikking is taken (finalized and published)
WHEN the register-publication job runs
THEN the system MUST update the record from status "verleend" to "vastgesteld" and include:
- Werkelijke bedrag: vastgesteld_bedrag (final amount paid)
- Settlement date
- Any publicly visible remarks (e.g., if terugvordering was applied)

### REQ-SUB-006-C: Feed Format and Delivery
GIVEN an administrator configures the subsidieregister feed endpoint
WHEN `GET /api/subsidies/register/export` is called with optional filters (regeling, status, jaar, gemeente)
THEN the system MUST return JSON array matching VNG subsidieregister standard schema with:
- `@context`: JSON-LD context
- `@type`: "Collection" | "FeatureCollection"
- `items[]`: array of structured subsidy objects
- `totalItems`: count
- `dateModified`: last update timestamp
- Pagination support (limit, offset)

GIVEN the feed is published on a gemeente webste or centraal register
WHEN news organizations or research teams query the feed
THEN the data MUST be consumable in standard JSON and support SPARQL queries for linked data integration

---

## REQ-SUB-007: Bewijsstukken-management with bewaartermijn

**Purpose**: Manage evidence documents with type-specific validation, retention period tracking, and automatic archival handover.

### REQ-SUB-007-A: Document Type Detection and Retention Assignment
GIVEN a Bewijsstuk is uploaded bij een tussenrapportage
WHEN the document is ingested
THEN the system MUST:
- Automatisch detect bewijsstuk_type (e.g., "voortgangsrapport", "factuur") via content-sniffing or filename pattern, OR allow user to select from whitelist
- Retrieve bewaartermijn years from regeling config (e.g., "voortgangsrapport: 7 years post-settlement per Selectielijst 4.7")
- Calculate bewaartermijn_einde: case_end_date + bewaartermijn_jaren years
- Set archief_status = "actief"
- Record bestand_hash_sha256 for integrity tracking

### REQ-SUB-007-B: Archival Handover to Docudesk
GIVEN a subsidie-zaak is afgerond en de bewaartermijn voor all bewijsstukken is bereikt
WHEN the archief-trigger draait (nightly job checking bewaartermijn_einde dates)
THEN the system MUST:
- Bundle all bewijsstukken with their metadata into a manifest (CSV or JSON)
- Convert PDF-compatible documents to PDF/A format via Docudesk service
- Transfer the bundle to the Docudesk archief-handover with:
  - Archief source ID: SubsidieUitvoering UUID
  - Metadata: regeling, ontvanger, bedrag, dates
  - Retention code per Selectielijst (e.g., "4.7: vernietigen na 7 jaar")
- Mark archief_status = "gearchiveerd" after successful transfer

### REQ-SUB-007-C: Document Integrity and Provenance
GIVEN a Bewijsstuk is stored in Nextcloud Files
WHEN the document is linked to the subsidie
THEN the system MUST:
- Record file properties: bestandid (Nextcloud ID), bestand_hash_sha256, upload_timestamp, uploader user UID
- Verify SHA-256 hash on every read to detect unauthorized modification
- Lock bewijsstukken from editing once linked to vaststelling (immutability)
- Audit trail tracks all access (read, share, download) for BIO compliance

---

## REQ-SUB-008: Cofinanciering en EU-staatssteun checks

**Purpose**: Support grants with co-financing and ensure compliance with EU state-aid rules (de-minimis, AGVV, DAEB).

### REQ-SUB-008-A: De-Minimis Threshold Checking
GIVEN een aanvraag voor bedrag boven de de-minimis drempel (€300.000 per drie jaar per onderneming) per de-minimisverordening 1407/2013
WHEN de behandelaar neemt de aanvraag in toets
THEN the system MUST:
- Toon a required veld for staatssteun-rechtsgrond: enum [de-minimis, AGVV-artikel, notificatieplicht_EU]
- Call `StatesteunClassifier.checkDeMinimis(aanvrager_kvk_ref, requested_amount, 3_year_lookback)` to retrieve prior subsidies from the same onderneming
- If cumulatief amount across the three-year window would exceed €300.000, flag as AGVV or notification required
- UI displays warning: "Amount exceeds de-minimis threshold (€300k/3yr); AGVV classification required"

### REQ-SUB-008-B: AGVV Classification and TAM-Melding
GIVEN a beschikking falls under AGVV (Verordening 651/2014) with eligible artikel (e.g., art. 14 research, art. 17 training)
WHEN the beschikking is published
THEN the system MUST:
- Automatically call `StatesteunClassifier.classifyAGVV(...)` to confirm eligible artikel and conditions
- Generate AGVV-melding document per TAM register standard (Dutch: "Register ontheffingen staatssteun")
- Emit `AgvvMeldingReadyEvent` to OpenConnector for async transmission to ministry (EZK)
- Record TAM-melding reference and transmission timestamp in audit trail
- Include melding_id in the subsidieregister feed

GIVEN the AGVV melding is transmitted to the central register
WHEN the ministry system confirms receipt
THEN the system MUST mark the beschikking as "EU_reporting_complete"

### REQ-SUB-008-C: Cofinanciering Validation
GIVEN a beschikking has cofinanciering from multiple parties (gemeente, EU, sponsor, etc.)
WHEN the beschikking is created
THEN the system MUST validate:
- Sum of all cofinanciering bedragen + gemeente subsidie = 100% (or explicitly documented partial funding)
- Each cofinanciering_partij has clear identity and bedrag
- If EU co-financing: verify that EU regulations (e.g., EFSI, ERDF) are compatible with this subsidieregeling

GIVEN cofinanciering validation fails
WHEN the system rejects the beschikking
THEN the error message MUST specify: "Cofinanciering sum (€X) does not equal project total (€Y); please reconcile"

---

## REQ-SUB-009: Wijzigingsbeschikking workflow

**Purpose**: Support amendments to existing grant decisions with audit trail linking.

### REQ-SUB-009-A: Wijziging Request and Basis Selection
GIVEN a subsidie-ontvanger requests a verlenging van de projectperiode
WHEN de behandelaar initiates a wijzigingsbeschikking
THEN the system MUST:
- Retrieve the oorspronkelijke beschikking
- Create a new SubsidieBeschikking with beschikkingtype = "wijzigingsbeschikking"
- Set trekt_in_besluit = oorspronkelijke beschikking UUID
- Auto-populate all fields from the original beschikking (deep copy)
- Display diff view to the behandelaar showing what changes

### REQ-SUB-009-B: Amendment Change Tracking
GIVEN the behandelaar edits the wijzigingsbeschikking looptijd_eind from 2026-12-31 to 2027-12-31
WHEN the wijziging is saved
THEN the system MUST record:
- Original value: 2026-12-31
- New value: 2027-12-31
- Wijzigingsreden: text field (required) for the legal justification
- Behandelaar and timestamp

### REQ-SUB-009-C: Publication and Effect
GIVEN a wijzigingsbeschikking is onherroepelijk (bezwaartermijn verstreken)
WHEN it takes legal effect
THEN the system MUST:
- Update SubsidieUitvoering to the new conditions (new looptijd, updated voorschot-schema if modified, updated verplichtingen)
- Recalculate termijn-counters for any tussenrapportages affected by the nieuwe looptijd
- Recalculate voorschot-scheduled disbursements if the voorschot-schema was amended
- Send notification to grantee: "Uw subsidiebeschikking is gewijzigd; nieuw bedrag: €X, nieuwe einddatum: YYYY-MM-DD"
- Record the wijziging in subsidieregister feed with `previousDecisionId` reference

---

## REQ-SUB-010: Reporting and dashboards

**Purpose**: Provide standard management and accountability reports exported in CSV and PDF formats.

### REQ-SUB-010-A: Quarterly Financial Report
GIVEN the einde van een kwartaal nadert
WHEN de financieel controller het kwartaalrapport opvraagt via `GET /api/subsidies/reports/quarterly?quarter=Q1&year=2026`
THEN the system MUST return a PDF levering with:
- **Totaal verleend per regeling per year**: table with columns [Regeling, 2024, 2025, 2026, Totaal EUR]
- **Totaal uitgekeerd (voorschotten)**: cumulative amounts by month/quarter
- **Totaal vastgesteld**: sum of all settlements finalized in the period
- **Openstaande voorschotten**: planned but not-yet-disbursed amounts (due in next periods)
- **Lopende terugvorderingen**: count and total EUR amount of active clawback cases
- **Overdue verplichtingen**: count of unmet conditions at quarter-end
- **KPIs**: average processing time per regeling, percentage of cases on-time, percentage of appeals/bezwaren

### REQ-SUB-010-B: Audit Sampling and Dossier Export
GIVEN een accountant doet een steekproef-controle
WHEN deze een sample van 30 dossiers selecteert (e.g., stratified random sample by regeling and amount)
THEN the system MUST via `POST /api/subsidies/reports/audit-export` levering een ZIP-export containing:
- Per dossier folder:
  - `beschikking.pdf`: full description and formatted beschikking document
  - `bewijsstukken/`: all linked evidence documents (organized by type)
  - `audit_trail.csv`: all status changes, amendments, assessments, with timestamp, user, details
  - `metadata.json`: summary of case dates, amounts, conditions, settlements
- `manifest.csv`: index of all dossiers in the export with case ID, regeling, amount, status
- `report_metadata.json`: export timestamp, requested sample size, actual size, selection method

---
