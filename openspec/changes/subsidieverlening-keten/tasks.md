# Tasks

## Core Infrastructure & Data Model

- [ ] TASK-SUB-01: Add nine new schemas to `procest_register.json` (SubsidieRegeling, SubsidieAanvraag, SubsidieBeoordeling, SubsidieBeschikking, SubsidieUitvoering, Tussenrapportage, SubsidieVaststelling, Terugvordering, Bewijsstuk) and register config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [ ] TASK-SUB-02: Create database migrations to initialize all nine register schemas and ensure backward compatibility with existing procest zaak tables.
- [ ] TASK-SUB-03: Implement `SubsidieService` with CRUD operations, status-machine transitions (aanvraag → beoordeling → beschikking → uitvoering → vaststelling), termijn-binding to `termijnbewaking` engine, and verplichting (condition) tracking.
- [ ] TASK-SUB-04: Add unit tests for `SubsidieService` covering multi-year date math, status transition guards, termijn calculations, and audit trail logging.

## Subsidie Aanvraag & Beoordeling

- [ ] TASK-SUB-05: Implement `SubsidieBeoordelingService` with inhoudelijke and financiële assessment, scoring per regeling-criteria, advies composition, and optional external expert advice workflow.
- [ ] TASK-SUB-06: Build `SubsidieController` REST endpoints for:
  - GET/POST `/api/subsidies` (list and create aanvraag)
  - GET/PATCH `/api/subsidies/{id}` (retrieve/update single case)
  - POST `/api/subsidies/{id}/beoordelen` (submit assessment)
  - POST `/api/subsidies/{id}/beschikking/create` and `publish` (draft/publish decision)
- [ ] TASK-SUB-07: Add case-type definition and workflow template for `SubsidieAanvraag` with status types (Ontvangen, In Beoordeling, Beoordeeld, Beschikking Opgesteld, Verleend, Afgewezen, Ingetrokken).
- [ ] TASK-SUB-08: Implement termijn-counter binding on aanvraag creation via `SubsidieService.registerTermijnCounter(subsidieAanvraag, regelingTermijn)` calling the shared `TermijnbewakingEngine`.

## Subsidie Beschikking Lifecycle

- [ ] TASK-SUB-09: Implement `SubsidieBeschikkingService` with:
  - Voorschot-schema builder: CRUD operations on scheduled disbursements
  - Validation: sum of voorschotten equals verleend_bedrag
  - Verplichting (condition) management: CRUD with type, description, deadline, required bewijsstukken
  - Beschikkingsnummer auto-generation (format: e.g., SUB-2026-000001)
- [ ] TASK-SUB-10: Implement voorschot-schema conditional triggering: create `VoorschotReadyEvent` emitter that checks if all conditions (e.g., tussenrapportage_approved) are satisfied before signaling the financial back-office.
- [ ] TASK-SUB-11: Integrate with OpenConnector to emit `BetaalingsIntegratieEvent` for each ready voorschot, with status tracking (in_betaling, betaald) and ERP reconciliation ID.
- [ ] TASK-SUB-12: Create `BeschikkingSignatureService` to digitally sign beschikking PDF and record signing timestamp + signer identity in audit trail per security policy.

## Tussenrapportage Workflow

- [ ] TASK-SUB-13: Implement `TussenrapportageService` with:
  - Auto-creation based on regeling-defined frequentie (jaarlijks, halfjaarlijks, mijlpaal-based)
  - Status lifecycle: verwacht → ingediend → in_beoordeling → goedgekeurd | afgekeurd | gedeeltelijk_goedgekeurd
  - Bewijsstukken linking and type-specific validation
  - Assessment submission with beoordelaar assignment
- [ ] TASK-SUB-14: Implement tussenrapportage termijn-binding: on creation, register a new termijn-counter with deadline = rapportage_periode_eind + regeling-configured termijn_duur.
- [ ] TASK-SUB-15: Build TussenrapportageService.approveReport() method:
  - Update status to goedgekeurd and record beoordelaar + beoordelingsdatum
  - For each conditional voorschot dependent on this tussenrapportage, emit VoorschotReadyEvent
  - Update SubsidieUitvoering.status if all conditions met
- [ ] TASK-SUB-16: Implement partial-approval workflow: allow status = gedeeltelijk_goedgekeurd with required-corrections text; permit resubmission and track amendment count in audit trail.

## Settlement & Terugvordering

- [ ] TASK-SUB-17: Implement `VaststellingService` with:
  - Settlement form handling: werkelijke kosten, realisatie van verplichtingen (compare against verplichting register)
  - Accountantsverklaring requirement check (mandatory if beschikking verleend_bedrag > drempel per regeling)
  - Final bedrag calculation
  - Overpayment detection: if werkelijke_kosten < totaal_voorschotten, set trigger_terugvordering = true
- [ ] TASK-SUB-18: Implement automatic `TerugvorderingService.createClawbackCase()` on vaststellingsbeschikking finalization:
  - Create Terugvordering object with bedrag = overpayment
  - Bind termijn-counters for bezwaartermijn (6 weeks) and betaaltermijn (4 weeks)
  - Set status = "opgelegd"
  - Require manager approval before publication
- [ ] TASK-SUB-19: Implement terugvordering inning tracking with:
  - Betaalherinneringen sending (email/portal notification)
  - Payment recording (partial or full) with reconciliation ID from ERP
  - Invorderingsrente calculation per AWB 4:97 (wettelijke rente, ~6% per annum) if unpaid after termijnen
  - Escalation to deurwaarder via OpenConnector if no payment after final termijn
- [ ] TASK-SUB-20: Add unit tests for terugvordering math: overpayment calculation, rente accrual, termijn dates with Dutch holiday handling.

## Evidence Document Management

- [ ] TASK-SUB-21: Implement `BewijsstukService` with:
  - Upload handler: CRUD on Bewijsstuk objects linked to SubsidieAanvraag, Tussenrapportage, or SubsidieVaststelling
  - Type detection/selection: whitelist of bewijsstuk_type values per phase
  - Bewaartermijn assignment: lookup from regeling config or Selectielijst defaults
  - SHA-256 hash computation and verification on read
- [ ] TASK-SUB-22: Implement document immutability for linked bewijsstukken: prevent edit/delete once linked to vaststelling; audit all read/share/download access per BIO.
- [ ] TASK-SUB-23: Build Docudesk integration for archival handover:
  - Create manifest (CSV/JSON) with bewijsstukken metadata on bewaartermijn_einde
  - Convert to PDF/A format via Docudesk service
  - Submit bundle to Docudesk with retention code (e.g., "4.7: vernietigen na 7 jaar")
  - Mark archief_status = "gearchiveerd" after successful transfer
- [ ] TASK-SUB-24: Implement BewijsstukService.linkToVerplichting() to associate evidence with specific conditions in beschikking; auto-surface matching bewijsstukken in TussenrapportageDetail component.

## EU Staatssteun Compliance

- [ ] TASK-SUB-25: Implement `StatesteunClassifier` service with:
  - De-minimis threshold checking: lookback 3 years on aanvrager KvK/BSN, cumulative amount validation against €300k per de-minimisverordening 1407/2013
  - AGVV classification: check eligible artikel (e.g., art. 14 research, art. 17 training) and conditions
  - DAEB (Diensten van Algemeen Economisch Belang) detection per Besluit 2012/21/EU
- [ ] TASK-SUB-26: Implement cofinanciering validation service:
  - Validate sum of cofinanciering bedragen + gemeente subsidy = project total
  - Detect EU co-financing and cross-check against EU regeling compatibility rules
  - Block beschikking creation if validation fails with specific error
- [ ] TASK-SUB-27: Build TAM-melding generation for AGVV-classified subsidies:
  - Auto-generate melding document per TAM register standard
  - Emit `AgvvMeldingReadyEvent` to OpenConnector for async transmission to ministry
  - Record melding_id and transmission timestamp in audit trail
- [ ] TASK-SUB-28: Implement `AanvragerHistoryLookup` to query prior subsidies from same KvK/BSN within 3-year window; cache results with hourly TTL.

## Amendment & Special Workflows

- [ ] TASK-SUB-29: Implement `WijzigingsbeschikkingService` to:
  - Create wijzigingsbeschikking from oorspronkelijke beschikking with beschikkingtype = "wijzigingsbeschikking"
  - Deep-copy original fields and permit selective amendments
  - Track all changes (field, old_value, new_value) in audit trail
  - Require wijzigingsreden (legal justification) for each change
- [ ] TASK-SUB-30: On wijzigingsbeschikking publication:
  - Update SubsidieUitvoering to new conditions (looptijd, voorschot-schema, verplichtingen)
  - Recalculate termijn-counters for affected tussenrapportages (if looptijd changed)
  - Recalculate voorschot-scheduled dates (if schema amended)
  - Mark oorspronkelijke beschikking as "ingetrokken" (superseded)
  - Publish amended record in subsidieregister feed with previousDecisionId reference

## Frontend Components

- [ ] TASK-SUB-31: Create `src/views/subsidies/SubsidieAanvraagList.vue` with:
  - Table: aanvraagnummer, regeling, status, behandelaar, startdate, deadline
  - Filters: by regeling, status, handler, date range
  - Overdue items pinned in red at top
  - Bulk actions: assign, mark as seen
- [ ] TASK-SUB-32: Create `src/views/subsidies/SubsidieAanvraagDetail.vue` with:
  - Header: aanvraagnummer, status badge, regeling, created date
  - Tabbed interface: AanvraagTab, BeschikkingTab, TussenrapportageTab, VaststellingTab, TerugvorderingTab, BewijsstukkenTab
  - Status timeline showing all transitions with timestamps
  - Activity feed showing all changes and assessments
- [ ] TASK-SUB-33: Create `src/views/subsidies/SubsidieBeschikkingForm.vue` with:
  - Form fields: verleend_bedrag, looptijd (start/end dates), beschikkingtype selector
  - Embedded `VoorschotSchemaBuilder` component for scheduling disbursements
  - Embedded `VerplichtingenTracker` component for condition CRUD
  - Validation feedback for voorschot-schema sum check
  - Preview of beschikking document before publication
- [ ] TASK-SUB-34: Create `src/views/subsidies/TussenrapportageDetail.vue` with:
  - Intake form: rapportage_periode_start/eind (read-only), inhoudelijke_voortgang textarea, financiele_verantwoording JSON editor
  - Bewijsstukken uploader inline
  - Verplichting-status pane showing required bewijsstukken for each condition
  - Assessment panel: beoordelaar notes, approval/rejection buttons, partial-approval with corrections
  - Notification preview before sending to applicant
- [ ] TASK-SUB-35: Create `src/views/subsidies/VaststellingForm.vue` with:
  - Intake form: werkelijke_kosten_totaal input, realisatie_verplichtingen accordion (per verplichting with status and evidence)
  - Accountantsverklaring file upload (required if verleend_bedrag exceeds drempel)
  - Auto-calculation: overpayment amount, terugvordering flag
  - Preview of vaststellingsbeschikking document
  - Approval workflow with manager gate before finalization
- [ ] TASK-SUB-36: Create `src/components/VoorschotSchemaBuilder.vue` (reusable) with:
  - Table: planned_date, bedrag, voorwaarde (dropdown: "unconditional", "after tussenrapportage {id}", etc.)
  - Add/remove rows with drag-drop reordering
  - Real-time sum display; highlight if not equal to verleend_bedrag
  - Validation feedback on blur
- [ ] TASK-SUB-37: Create `src/components/VerplichtingenTracker.vue` (reusable) with:
  - Accordion: per verplichting, show description, status dropdown, required_bewijsstukken list
  - Bewijsstukken list per verplichting with file previews/download links
  - Status override for unmet conditions at vaststelling (with waiver motivering required)
  - Add/remove verplichting rows
- [ ] TASK-SUB-38: Create `src/views/subsidies/SubsidieRegisterDashboard.vue` (manager view) with:
  - KPI cards: total verleend this year, total vastgesteld, openstaande voorschotten (EUR), active terugvorderingen (EUR & count)
  - Bar chart: verleend per regeling (last 12 months)
  - Line chart: cumulative distribution per month
  - Table: pending approvals (wijzigingsbeschikkingen, terugvorderingen, high-risk cases)
  - Overdue alerts: late tussenrapportages, overdue termijnen, unpaid terugvorderingen

## Integration & APIs

- [ ] TASK-SUB-39: Implement subsidieregister feed generator:
  - Endpoint: `GET /api/subsidies/register/export?status=verleend&status=vastgesteld&year=2026&gemeente=Amsterdam`
  - Output JSON per VNG subsidieregister and Wet open overheid standards
  - Support pagination (limit, offset)
  - Anonymize individual applicants per GDPR richtlijn VNG
  - Include JSON-LD `@context` for linked data integration
- [ ] TASK-SUB-40: Implement quarterly reporting endpoint:
  - `GET /api/subsidies/reports/quarterly?quarter=Q1&year=2026`
  - Generate PDF with tables: totaal verleend per regeling, totaal uitgekeerd, openstaande voorschotten, terugvorderingen, KPIs
  - Support CSV export of underlying data
- [ ] TASK-SUB-41: Implement audit-export endpoint:
  - `POST /api/subsidies/reports/audit-export` with sample selection parameters
  - Return ZIP with stratified random dossier sample (30-50 cases)
  - Per dossier: beschikking PDF, bewijsstukken folder, audit_trail.csv, metadata.json
  - Include manifest.csv and report_metadata.json
- [ ] TASK-SUB-42: Add notification endpoints for:
  - Tussenrapportage prompts (auto-sent at regeling-defined offset before deadline)
  - Terugvordering betaalherinneringen (at days +7, +21, +35 post-publication)
  - Termijn escalation alerts (T-2 weeks, T-0 days)
  - Use existing procest notification router with email fallback

## Configuration & Admin UI

- [ ] TASK-SUB-43: Create admin UI (under Settings → Subsidies):
  - Regeling CRUD: edit regeling_naam, juridische_grondslag, plafond, looptijd, doelgroep, beoordelingscriteria_template, tussenrapportage_frequentie, accountantsverklaring_drempel
  - Bewijsstuk retention template config: per type and source (aanvraag, tussenrapportage, vaststelling), retention years
  - Cofinanciering validation rules: required parties, percentage distributions
  - Notification schedule config: tussenrapportage reminders (days before deadline), terugvordering betaalherinneringen
  - Export format selection: JSON, CSV for subsidieregister feed
- [ ] TASK-SUB-44: Implement settings persistence to `procest_register.json` (register-level) and `SettingsService` (tenant-level) per multi-tenancy model.

## i18n & Documentation

- [ ] TASK-SUB-45: Add Dutch + English i18n strings for:
  - All status types, field labels, button labels, error messages
  - Notification templates: tussenrapportage prompts, terugvordering reminders, termijn alerts
  - Report titles and column headers
  - API error messages with resolution hints
- [ ] TASK-SUB-46: Create user documentation (Dutch):
  - Guide: subsidy aanvraag flow (from applicant perspective)
  - Guide: subsidy handler workflow (intake, assessment, beschikking, tussenrapportage management, vaststelling)
  - Guide: terugvordering inning and escalation
  - FAQ: frequently misunderstood AWB rules (e.g., bezwaartermijn calculation, verplichting tracking)

## Testing & Quality Assurance

- [ ] TASK-SUB-47: Add integration tests for:
  - Multi-year date calculations and termijn-counter binding
  - Voorschot-schema validation and conditional triggering
  - Overpayment detection and automatic terugvordering case creation
  - De-minimis lookback and AGVV classification
  - Bewijsstukken archival workflow and Docudesk integration
- [ ] TASK-SUB-48: Add end-to-end tests (browser-based) for:
  - Complete aanvraag → beschikking → tussenrapportage → vaststelling → terugvordering flow
  - Wijzigingsbeschikking amendment workflow
  - Frontend form validation and component interaction
- [ ] TASK-SUB-49: Performance testing for:
  - Subsidieregister feed generation with 10k+ records (pagination, JSON serialization)
  - Audit-export ZIP generation with 50 dossiers and 500+ documents
  - De-minimis lookback query on 100k+ prior subsidies (caching strategy)
- [ ] TASK-SUB-50: Security review:
  - GDPR compliance: personal data encryption, purpose limitation, subject access rights, anonymization in reporting
  - Input validation: prevent SQL/LDAP injection, file upload exploit
  - Authorization: role-based access to sensitive fields (bewijsstukken, personal data)
  - Audit trail immutability: no-update-no-delete on audit records
