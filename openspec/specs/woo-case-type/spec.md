---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# woo-case-type Specification

## Purpose
Provides a pre-configured WOO-verzoek zaaktype template that municipalities activate from a template library and customize, defining the eight statutory processing stages, a WOO-specific intake form, and 28-day deadline tracking with statutory extension. It supports document collection and inventory, per-document disclosure assessment with mandatory weigeringsgronden, Docudesk redaction, formal besluit recording, publication to a reading room and PLOOI, bezwaar-period tracking, and WOO reporting.
## Requirements
### Requirement: WOO zaaktype template activation
The system MUST provide a pre-configured zaaktype template for WOO verzoeken that can be activated from a template library and customized per municipality.

#### Scenario: Activate WOO zaaktype from template library
- **GIVEN** a Dossiq admin navigating to the case type settings at `CaseTypeAdmin.vue`
- **WHEN** they open the template library and select "WOO Verzoek"
- **THEN** the system MUST create a new zaaktype object in OpenRegister using the `caseType` schema
- **AND** populate it with pre-configured status types, property definitions, document types, role types, result types, and decision types
- **AND** redirect the admin to `CaseTypeDetail.vue` for the new zaaktype

#### Scenario: Customize template before activation
- **GIVEN** an admin previewing the "WOO Verzoek" template
- **WHEN** they modify the template configuration (e.g., change a stage name, add a property)
- **THEN** the customizations MUST be applied to the created zaaktype
- **AND** the original template MUST remain unchanged for future activations

#### Scenario: Template includes version metadata
- **GIVEN** the WOO zaaktype template
- **THEN** it MUST include a version number (e.g., "1.0.0") and a last-updated date
- **AND** when Dossiq ships a template update, admins MUST be notified that a newer version is available
- **AND** updating MUST NOT overwrite customizations already applied to existing zaaktypes

#### Scenario: Template ships as JSON seed data
- **GIVEN** the Dossiq app installation
- **THEN** the WOO template MUST be stored as a JSON file in `lib/Settings/templates/woo-verzoek.json`
- **AND** the `InitializeSettings` repair step MUST register the template in the template registry
- **AND** the template format MUST follow the same structure as `dossiq_register.json`

#### Scenario: Multiple templates can coexist
- **GIVEN** the template library contains "WOO Verzoek" and other zaaktype templates
- **WHEN** an admin activates "WOO Verzoek"
- **THEN** it MUST NOT interfere with other activated templates or manually created zaaktypes
- **AND** each activated template MUST create its own independent set of status types and property definitions

### Requirement: WOO lifecycle stages
The WOO zaaktype MUST define eight ordered stages reflecting the statutory WOO processing flow.

#### Scenario: Pre-configured stage order
- **GIVEN** the activated WOO Verzoek zaaktype
- **THEN** the following status types MUST be created in the `statusType` schema in order:
  1. **Ontvangst** -- Request received, acknowledgement sent
  2. **Beoordeling ontvankelijkheid** -- Check formal requirements
  3. **Zoeken documenten** -- Search and collect relevant documents
  4. **Beoordelen documenten** -- Per-document disclosure assessment
  5. **Lakken / Anonimiseren** -- Redact sensitive information
  6. **Besluit** -- Formal decision on disclosure
  7. **Publicatie** -- Publish approved documents
  8. **Afgehandeld** -- Case closed (isFinal: true)
- **AND** the `StatusesTab.vue` MUST render these as a visual flow diagram

#### Scenario: Stage transitions enforce order
- **GIVEN** a WOO case in stage "Ontvangst"
- **WHEN** the case worker attempts to change the status
- **THEN** only "Beoordeling ontvankelijkheid" MUST be available as the next status
- **AND** skipping stages (e.g., going from "Ontvangst" directly to "Besluit") MUST be blocked unless the admin has configured skip-ahead for specific stages

#### Scenario: Stage-specific required actions
- **GIVEN** a WOO case in stage "Beoordelen documenten"
- **WHEN** the case worker attempts to advance to "Lakken / Anonimiseren"
- **THEN** the system MUST verify that all collected documents have been assessed (openbaar/deels openbaar/niet openbaar)
- **AND** block advancement if any document lacks an assessment

#### Scenario: Return to previous stage
- **GIVEN** a WOO case in stage "Besluit"
- **WHEN** the case worker determines that additional documents need assessment
- **THEN** they MUST be able to return the case to "Beoordelen documenten" with a mandatory reason
- **AND** the return MUST be logged in the audit trail via the `ActivityTimeline` component

#### Scenario: Initiator notification per stage
- **GIVEN** a WOO stage with `notifyInitiator: true` configured on the status type
- **WHEN** the case transitions to that stage
- **THEN** the system MUST send a notification to the verzoeker using the `notificationText` from the status type
- **AND** the notification MUST be recorded in the case timeline

### Requirement: WOO-specific intake form
The system MUST provide intake fields specific to WOO requests as property definitions on the zaaktype.

#### Scenario: Required intake fields
- **GIVEN** a new WOO Verzoek case being created via `CaseCreateDialog.vue`
- **WHEN** the case worker fills in the intake form
- **THEN** the form MUST include these property definitions:
  - **Verzoeker naam** (string, required)
  - **Verzoeker contactgegevens** (email/phone, required)
  - **Verzoeker type** (enum: burger/journalist/organisatie, required)
  - **Onderwerp** (text, required -- topic of the request)
  - **Periode van** / **Periode tot** (date range the request covers)
  - **Bestuurlijke aangelegenheid** (text -- administrative matter)
  - **Ontvangstdatum** (date, required -- receipt date for deadline calculation)
  - **Gewenste vorm** (enum: papier/digitaal/inzage, default: digitaal)

#### Scenario: Intake form validation
- **GIVEN** a case worker submitting the WOO intake form
- **WHEN** required fields are missing
- **THEN** the system MUST highlight missing fields with validation errors
- **AND** block case creation until all required fields are filled

#### Scenario: Verzoeker linked to contact record
- **GIVEN** a WOO intake with verzoeker details
- **WHEN** the case is created
- **THEN** the verzoeker MUST be linked as a role (using the `role` schema with roleType "Verzoeker")
- **AND** if the verzoeker matches an existing contact in OpenRegister, the system MUST suggest linking to the existing record

#### Scenario: Intake auto-generates ontvangstbevestiging task
- **GIVEN** a WOO case is created with ontvangstdatum filled
- **WHEN** the case enters the "Ontvangst" stage
- **THEN** a task MUST be auto-created: "Verstuur ontvangstbevestiging" with a 2-day deadline
- **AND** the task MUST reference the verzoeker's contact details

#### Scenario: Intake from external channel
- **GIVEN** a WOO request arrives via email, post, or a web form through OpenConnector
- **WHEN** the request is routed to Dossiq
- **THEN** the system MUST pre-fill the intake form with available data from the incoming message
- **AND** flag any missing required fields for manual completion

### Requirement: WOO deadline tracking and extension
The system MUST enforce WOO-mandated response deadlines with support for statutory extension.

#### Scenario: Calculate initial response deadline
- **GIVEN** a WOO request with ontvangstdatum "2026-03-15"
- **WHEN** the case is created
- **THEN** the system MUST set the case deadline to 28 calendar days: "2026-04-12"
- **AND** display the deadline in the `DeadlinePanel.vue` component
- **AND** store the deadline as an ISO 8601 duration (P28D) on the case type's processing time

#### Scenario: Warning thresholds
- **GIVEN** a WOO case with a deadline of "2026-04-12"
- **WHEN** 14 days remain (2026-03-29), the `DeadlinePanel` MUST show a yellow warning
- **AND** when 7 days remain (2026-04-05), the panel MUST show a red urgent alert
- **AND** when the deadline has passed, the panel MUST show "Termijn verlopen" in red

#### Scenario: Request deadline extension (verdaging)
- **GIVEN** a WOO case approaching its deadline with extensionCount === 0
- **WHEN** the case worker clicks "Request Extension" in the `DeadlinePanel`
- **THEN** a dialog MUST appear requesting a mandatory reason for the extension
- **AND** the deadline MUST be extended by 14 calendar days (P14D)
- **AND** the extension MUST be logged in the audit trail
- **AND** the verzoeker MUST be notified of the extension with the reason

#### Scenario: Only one extension allowed
- **GIVEN** a WOO case that has already been extended once (extensionCount === 1)
- **THEN** the "Request Extension" button MUST be disabled
- **AND** the `DeadlinePanel` MUST show "Verdaging: reeds verdaagd"

#### Scenario: Extension resets warning thresholds
- **GIVEN** a WOO case with original deadline "2026-04-12" extended to "2026-04-26"
- **THEN** the warning threshold MUST recalculate to 14 days before "2026-04-26"
- **AND** the urgent alert threshold MUST recalculate to 7 days before "2026-04-26"

### Requirement: Document collection and inventory
The system MUST support searching, collecting, and inventorying documents relevant to a WOO request.

#### Scenario: Add documents to WOO dossier
- **GIVEN** a WOO case in stage "Zoeken documenten"
- **WHEN** the case worker searches Nextcloud files or external document systems
- **THEN** found documents MUST be linkable to the WOO case as case documents (using the `caseDocument` schema)
- **AND** each document MUST receive a sequential inventory number

#### Scenario: Document inventory list (inventarislijst)
- **GIVEN** a WOO case with 25 collected documents
- **WHEN** the case worker views the document inventory
- **THEN** the system MUST display a table with columns: volgnummer, documentnaam, datum, afzender, beoordeling, weigeringsgrond
- **AND** the inventory MUST be exportable as a PDF/CSV for inclusion in the WOO decision

#### Scenario: Bulk document upload
- **GIVEN** a WOO case in stage "Zoeken documenten"
- **WHEN** the case worker uploads multiple documents at once via drag-and-drop
- **THEN** all documents MUST be linked to the case with sequential inventory numbers
- **AND** duplicate detection MUST warn if a document with the same filename already exists in the dossier

#### Scenario: Document source tracking
- **GIVEN** a document added to a WOO dossier
- **THEN** the system MUST record the source system (Nextcloud Files, email, external DMS) and the search query or context that led to the document
- **AND** this metadata MUST be included in the inventarislijst

#### Scenario: Mark document collection complete
- **GIVEN** a WOO case in stage "Zoeken documenten"
- **WHEN** the case worker marks document collection as complete
- **THEN** the system MUST verify at least one document has been collected
- **AND** advance the case to "Beoordelen documenten"

### Requirement: Per-document disclosure assessment
Each document in a WOO case MUST be individually assessed for disclosure with mandatory legal basis for withheld documents.

#### Scenario: Three-way assessment per document
- **GIVEN** a WOO case in stage "Beoordelen documenten" with 15 collected documents
- **WHEN** the case worker opens the document assessment view
- **THEN** each document MUST have a dropdown with options:
  - **Openbaar** -- Full disclosure
  - **Deels openbaar** -- Partial disclosure (requires redaction)
  - **Niet openbaar** -- Withheld (requires weigeringsgrond)
- **AND** unassessed documents MUST be visually distinct (grey/pending state)

#### Scenario: Mandatory weigeringsgrond for withheld documents
- **GIVEN** a document assessed as "Niet openbaar"
- **WHEN** the case worker saves the assessment
- **THEN** they MUST select one or more legal grounds from WOO Article 5.1/5.2:
  - 5.1.1a: Eenheid van de Kroon
  - 5.1.1b: Veiligheid van de Staat
  - 5.1.2a: Internationale betrekkingen
  - 5.1.2b: Economische of financiele belangen van de Staat
  - 5.1.2c: Opsporing en vervolging van strafbare feiten
  - 5.1.2d: Inspectie, controle en toezicht
  - 5.1.2e: Eerbiediging van de persoonlijke levenssfeer
  - 5.1.2f: Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens
  - 5.1.2g: Onevenredige bevoordeling of benadeling
  - 5.1.2i: Goed functioneren van de Staat
  - 5.2.1: Persoonlijke beleidsopvattingen
  - 5.2.2: Persoonlijke beleidsopvattingen (intern beraad, geanonimiseerd mogelijk)
- **AND** the weigeringsgrond MUST be stored as metadata on the document assessment

#### Scenario: Weigeringsgrond for partially disclosed documents
- **GIVEN** a document assessed as "Deels openbaar"
- **THEN** the case worker MUST also select the applicable weigeringsgrond(en) for the redacted portions
- **AND** these grounds MUST appear in the inventarislijst and decision document

#### Scenario: Assessment progress indicator
- **GIVEN** 15 documents in a WOO dossier with 10 assessed and 5 pending
- **THEN** the case detail MUST show a progress indicator: "10/15 documenten beoordeeld"
- **AND** the progress MUST be visible in both the case detail and the case list overview

#### Scenario: Bulk assessment for similar documents
- **GIVEN** multiple documents that share the same assessment (e.g., all internal meeting notes are "Niet openbaar" under 5.2.1)
- **WHEN** the case worker selects multiple documents and applies a bulk assessment
- **THEN** all selected documents MUST receive the same assessment and weigeringsgrond
- **AND** each individual document MUST still be editable afterwards

### Requirement: Redaction via Docudesk integration
Documents assessed as "Deels openbaar" MUST be routable to Docudesk for AI-assisted anonymization with human review.

#### Scenario: Send document for redaction
- **GIVEN** a document assessed as "Deels openbaar"
- **WHEN** the case worker clicks "Anonimiseren"
- **THEN** the document MUST be sent to Docudesk's anonymization pipeline via OpenConnector or direct API call
- **AND** the case worker MUST be able to specify which entity types to detect (names, BSN, addresses, phone numbers)

#### Scenario: Review AI-detected entities
- **GIVEN** Docudesk returns a document with 23 detected entities highlighted
- **WHEN** the case worker opens the redaction review
- **THEN** each detected entity MUST be shown with its type (naam, BSN, adres) and the proposed redaction
- **AND** the case worker MUST be able to accept, reject, or modify each proposed redaction
- **AND** manually add redactions that the AI missed

#### Scenario: Store anonymized version
- **GIVEN** the case worker finalizes the redaction review
- **WHEN** they confirm the redactions
- **THEN** the anonymized document MUST be stored as a new file in Nextcloud Files linked to the case
- **AND** the original (unredacted) document MUST be preserved but marked as "niet openbaar"
- **AND** the link between original and anonymized version MUST be tracked

#### Scenario: Batch redaction
- **GIVEN** 8 documents assessed as "Deels openbaar"
- **WHEN** the case worker selects "Batch anonimiseren"
- **THEN** all 8 documents MUST be sent to Docudesk simultaneously
- **AND** the system MUST track progress per document and notify when each is ready for review

#### Scenario: Complete redaction stage gate
- **GIVEN** a WOO case in stage "Lakken / Anonimiseren"
- **WHEN** the case worker attempts to advance to "Besluit"
- **THEN** the system MUST verify that ALL documents assessed as "Deels openbaar" have finalized anonymized versions
- **AND** block advancement if any document is still pending redaction

### Requirement: WOO decision (besluit)
The system MUST support formal WOO decision recording with per-document disposition.

#### Scenario: Record WOO decision
- **GIVEN** a WOO case in stage "Besluit"
- **WHEN** the case worker opens the decision form
- **THEN** the form MUST include:
  - **Besluitdatum** (date, defaults to today)
  - **Besluit samenvatting** (text summary of the decision)
  - **Per-document disposition list** (auto-populated from assessments)
- **AND** the decision MUST be stored using the `decision` schema with decisionType "WOO Besluit"

#### Scenario: Generate decision document (beschikking)
- **GIVEN** a completed WOO decision
- **WHEN** the case worker clicks "Genereer beschikking"
- **THEN** the system MUST generate a PDF decision letter containing:
  - Municipality header and logo
  - Verzoeker details
  - Summary of the request (onderwerp, periode)
  - Decision per document (openbaar/deels openbaar/niet openbaar with weigeringsgrond)
  - Legal basis and bezwaarclausule
  - Inventarislijst as appendix
- **AND** the generated document MUST be stored as a case document

#### Scenario: Include bezwaarclausule
- **GIVEN** a generated WOO beschikking
- **THEN** the document MUST include a standard bezwaarclausule informing the verzoeker of their right to object within 6 weeks
- **AND** the bezwaar deadline MUST be calculated and stored on the case for tracking

#### Scenario: Decision approval workflow
- **GIVEN** a WOO decision drafted by a case worker
- **WHEN** the case requires approval (configured per zaaktype)
- **THEN** the decision MUST be routed to an authorized approver (manager/bestuurder)
- **AND** the approver MUST be able to approve, reject with comments, or request changes
- **AND** only approved decisions can advance the case to "Publicatie"

#### Scenario: Decision notification to verzoeker
- **GIVEN** an approved WOO decision
- **WHEN** the case worker sends the decision
- **THEN** the beschikking MUST be sent to the verzoeker via their preferred channel (email, post, or Mijn Overheid Berichtenbox)
- **AND** the sending MUST be recorded in the audit trail

### Requirement: Publication to reading room
The system MUST support publication of WOO documents to a publicly accessible reading room.

#### Scenario: Prepare publication package
- **GIVEN** a completed WOO case with an approved decision
- **WHEN** the case worker triggers "Publicatie voorbereiden"
- **THEN** the system MUST assemble a publication package containing:
  - The decision document (beschikking)
  - The inventarislijst
  - All documents assessed as "Openbaar"
  - Anonymized versions of "Deels openbaar" documents
- **AND** the package MUST exclude all "Niet openbaar" documents and original (unredacted) versions

#### Scenario: Publish to public URL
- **GIVEN** a prepared publication package
- **WHEN** the case worker triggers "Publiceren"
- **THEN** the documents MUST be published to a publicly accessible URL (reading room)
- **AND** the URL MUST be shareable and not require authentication
- **AND** the publication MUST include a cover page with the case summary and inventarislijst

#### Scenario: Publish to PLOOI
- **GIVEN** PLOOI (Platform Open Overheidsinformatie) integration is configured
- **WHEN** the case worker triggers publication
- **THEN** the system MUST also push the publication package to PLOOI via its API
- **AND** store the PLOOI reference identifier on the case

#### Scenario: Publication audit trail
- **GIVEN** a WOO publication
- **THEN** the audit trail MUST record: publication date, published documents list, public URL, and the user who triggered publication
- **AND** any subsequent corrections or retractions MUST also be logged

#### Scenario: Retract published document
- **GIVEN** a published WOO dossier
- **WHEN** a court ruling or new assessment requires retraction of a specific document
- **THEN** the case worker MUST be able to retract individual documents from the reading room
- **AND** the retraction MUST be logged with reason and the reading room MUST show "Ingetrokken" for that document

### Requirement: Bezwaar (objection) period tracking
The system MUST track the objection period after a WOO decision and handle incoming objections.

#### Scenario: Calculate bezwaar deadline
- **GIVEN** a WOO decision sent on "2026-04-15"
- **THEN** the system MUST calculate the bezwaar deadline as 6 weeks: "2026-05-27"
- **AND** display the bezwaar deadline in the case detail after the decision stage

#### Scenario: Register incoming bezwaar
- **GIVEN** a WOO case in the bezwaar period
- **WHEN** an objection is received from the verzoeker
- **THEN** the case worker MUST be able to register the bezwaar with: ontvangstdatum, bezwaargronden (text), and the objecting party's details
- **AND** the case MUST be reopened or a linked bezwaar case MUST be created

#### Scenario: Bezwaar period expired without objection
- **GIVEN** a WOO case whose bezwaar deadline has passed with no objection registered
- **THEN** the system MUST mark the case as "Onherroepelijk" (irrevocable)
- **AND** the case MAY be automatically archived per the configured retention policy

#### Scenario: Bezwaar notification to case worker
- **GIVEN** a WOO case approaching the end of its bezwaar period (7 days remaining)
- **THEN** the system MUST notify the assigned case worker
- **AND** the notification MUST appear in the Nextcloud notification center

#### Scenario: Bezwaar extends case lifecycle
- **GIVEN** a bezwaar is registered on a WOO case
- **THEN** the case status MUST change to "Bezwaar in behandeling"
- **AND** new tasks MUST be created for the bezwaar handling workflow (hoorplicht, heroverweging, beslissing op bezwaar)

### Requirement: WOO reporting and statistics
The system MUST provide reporting on WOO case processing for management oversight and statutory reporting.

#### Scenario: WOO dashboard widget
- **GIVEN** the Dossiq dashboard
- **THEN** a "WOO Overzicht" widget MUST display:
  - Total active WOO cases
  - Cases approaching deadline (< 7 days)
  - Cases past deadline
  - Average processing time (completed cases)
  - Cases per stage distribution

#### Scenario: Annual WOO report data
- **GIVEN** the admin requests an annual WOO report for 2026
- **THEN** the system MUST export:
  - Total WOO requests received
  - Total processed within deadline vs. past deadline
  - Breakdown by assessment type (openbaar/deels openbaar/niet openbaar)
  - Most common weigeringsgronden
  - Average processing time

#### Scenario: Per-case statistics
- **GIVEN** a completed WOO case
- **THEN** the case detail MUST show:
  - Total processing time (days)
  - Number of documents assessed per category
  - Number of documents redacted
  - Whether deadline was met (ja/nee)
  - Whether extension was used (ja/nee)

