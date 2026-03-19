# woo-case-type Specification

## Purpose
Provide a standard zaaktype template in Procest for handling WOO (Wet open overheid) requests. Pre-configured workflow covering the full lifecycle: intake, document search, assessment, redaction, and publication. Links to Docudesk anonymization for document redaction.

## Context
The Wet open overheid (WOO) replaced the Wet openbaarheid van bestuur (WOB) in 2022. Municipalities must handle transparency requests within strict deadlines. A WOO request follows a specific lifecycle that differs from regular cases: it requires searching across document systems, per-document assessment (openbaar/deels openbaar/geweigerd), redaction of privacy-sensitive information, and publication to a reading room. This zaaktype template provides the standard workflow out of the box.

## ADDED Requirements

### Requirement: WOO zaaktype template
The system MUST provide a pre-configured zaaktype for WOO verzoeken.

#### Scenario: Activate WOO zaaktype
- GIVEN a Procest admin configuring zaaktypes
- WHEN they activate the "WOO Verzoek" template from the template library
- THEN the zaaktype MUST be created with pre-configured stages, forms, and document types
- AND the admin MUST be able to customize the template before or after activation

#### Scenario: WOO zaaktype stages
- GIVEN the WOO Verzoek zaaktype
- THEN the following stages MUST be pre-configured in order:
  1. **Ontvangst** -- Request received, acknowledgement sent
  2. **Beoordeling ontvankelijkheid** -- Check if request meets formal requirements
  3. **Zoeken documenten** -- Search and collect relevant documents
  4. **Beoordelen documenten** -- Per-document assessment of disclosure
  5. **Lakken / Anonimiseren** -- Redact sensitive information
  6. **Besluit** -- Formal decision on disclosure
  7. **Publicatie** -- Publish approved documents to reading room
  8. **Afgehandeld** -- Case closed

### Requirement: WOO-specific intake form
The system MUST provide intake fields specific to WOO requests.

#### Scenario: WOO intake fields
- GIVEN a new WOO Verzoek case
- WHEN the case worker fills in the intake form
- THEN the form MUST include:
  - Verzoeker details (naam, contactgegevens, type: burger/journalist/organisatie)
  - Onderwerp (topic of the request, free text)
  - Periode (date range the request covers)
  - Bestuurlijke aangelegenheid (administrative matter)
  - Ontvangstdatum (receipt date, for deadline calculation)
  - Gewenste vorm (paper/digital/inspection)

### Requirement: Deadline tracking with WOO-specific timelines
The system MUST enforce WOO-mandated response deadlines.

#### Scenario: Calculate response deadline
- GIVEN a WOO request received on 2026-03-15
- WHEN the case is created
- THEN the system MUST calculate the response deadline as 4 weeks (28 calendar days): 2026-04-12
- AND a warning MUST appear at 2 weeks remaining
- AND an urgent alert MUST appear at 1 week remaining

#### Scenario: Deadline extension (verdaging)
- GIVEN a WOO case approaching its deadline
- WHEN the case worker records a deadline extension with reason
- THEN the deadline MUST be extended by 2 weeks (maximum one extension per WOO)
- AND the extension MUST be logged with reason in the audit trail
- AND the verzoeker MUST be notified of the extension (via email or Mijn Overheid)

### Requirement: Document assessment workflow
Each document in a WOO case MUST be individually assessed for disclosure.

#### Scenario: Assess document for disclosure
- GIVEN a WOO case in stage "Beoordelen documenten" with 15 collected documents
- WHEN the case worker opens the document assessment view
- THEN each document MUST be listable with assessment status
- AND the case worker MUST set each document to one of:
  - **Openbaar** -- Full disclosure
  - **Deels openbaar** -- Partial disclosure (requires redaction)
  - **Niet openbaar** -- Withheld, with mandatory weigeringsgrond (legal basis)

#### Scenario: Link weigeringsgrond to withheld document
- GIVEN a document assessed as "Niet openbaar"
- WHEN the case worker selects the legal basis
- THEN they MUST choose from the WOO Article 5.1/5.2 grounds:
  - 5.1.1: Eenheid van de Kroon
  - 5.1.2: Veiligheid van de Staat
  - 5.1.3: Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens
  - 5.1.5: Persoonlijke levenssfeer
  - (and other applicable grounds)
- AND the selected ground(s) MUST be stored with the document assessment

### Requirement: Redaction via Docudesk integration
Documents assessed as "Deels openbaar" MUST be routable to Docudesk for anonymization.

#### Scenario: Send document for redaction
- GIVEN a document assessed as "Deels openbaar"
- WHEN the case worker clicks "Anonimiseren"
- THEN the document MUST be sent to Docudesk's anonymization pipeline
- AND the case worker MUST be able to review and adjust detected entities before finalizing
- AND the anonymized version MUST be stored as a new document linked to the original

#### Scenario: Complete redaction stage
- GIVEN all "Deels openbaar" documents have been processed
- WHEN the case worker marks the redaction stage as complete
- THEN the system MUST verify that all documents needing redaction have anonymized versions
- AND the case MUST be advanceable to the "Besluit" stage

### Requirement: Decision and publication
The system MUST support the formal WOO decision and document publication.

#### Scenario: Record WOO decision
- GIVEN a WOO case in stage "Besluit"
- WHEN the case worker records the decision
- THEN the decision MUST include: besluitdatum, summary, per-document disposition list
- AND a formal besluit document MUST be generated (links to besluiten-management spec)

#### Scenario: Publish to reading room
- GIVEN a completed WOO case with decision
- WHEN the case worker triggers publication
- THEN all "Openbaar" documents and anonymized "Deels openbaar" documents MUST be published
- AND the publication MUST include the decision document, inventarislijst, and all disclosed documents
- AND the publication MUST be accessible via a public URL (reading room)

## Dependencies
- Docudesk anonymization pipeline (for document redaction)
- Besluiten-management spec (for formal decision registration)
- Mijn Overheid integration (for verzoeker notification)
- Document management within case (zaakdossier)

---

### Current Implementation Status

**Not implemented.** No WOO-specific case type template, workflow, or UI exists in the Procest codebase.

**Existing foundations:**
- **Case type configuration**: `src/views/settings/CaseTypeAdmin.vue`, `CaseTypeDetail.vue`, `CaseTypeList.vue`, and `src/views/settings/tabs/StatusesTab.vue` provide admin UI for creating and configuring case types with status diagrams. A WOO zaaktype could be created through this UI manually.
- **Status types**: The `statusType` schema in `procest_register.json` supports ordered statuses with `isFinal`, `notifyInitiator`, `notificationText` properties. The 8 WOO stages could be configured as status types.
- **Document types**: The `documentType` schema supports `name`, `direction` (incoming/outgoing/internal), `requiredAtStatus`. WOO document types could be configured.
- **Property definitions**: The `propertyDefinition` schema supports custom fields per case type. WOO intake fields (onderwerp, periode, bestuurlijke aangelegenheid, etc.) could be defined.
- **Decision management**: The `decision` and `decisionType` schemas exist in `procest_register.json`. ZGW BRC controller (`lib/Controller/BrcController.php`) provides decision API endpoints.
- **Deadline tracking**: `src/views/cases/components/DeadlinePanel.vue` provides deadline display with processing deadline calculation from case type.
- **Duration helpers**: `src/utils/durationHelpers.js` supports ISO 8601 duration parsing for deadline calculations.

**Not implemented (WOO-specific):**
- No pre-configured WOO zaaktype template shipped with the app
- No WOO-specific intake form with verzoeker, onderwerp, periode fields
- No WOO deadline calculation (4 weeks + optional 2-week extension)
- No per-document assessment workflow (openbaar/deels openbaar/niet openbaar)
- No weigeringsgrond (WOO Art. 5.1/5.2) selection UI
- No Docudesk integration for document redaction/anonymization
- No decision document generation
- No inventarislijst (document inventory) generation
- No publication to reading room
- No template library concept (activate a template to create a zaaktype)

### Standards & References

- **Wet open overheid (WOO)**: Dutch transparency law (2022), replacing WOB. Key deadlines: 4 weeks response time, max 2-week extension (Art. 4.4).
- **WOO Article 5.1/5.2**: Legal grounds for withholding documents (eenheid van de Kroon, veiligheid Staat, persoonlijke levenssfeer, etc.).
- **ZGW APIs**: Cases, documents, and decisions follow ZGW patterns. ZTC defines the zaaktype structure.
- **GEMMA**: WOO processing is a standard use case in the GEMMA reference architecture.
- **Platform Openheid Overheid (PLOOI)**: National publication platform for WOO documents (may be relevant for publication step).
- **AVG/GDPR**: Document redaction/anonymization requirements for personal data.
- **Archiefwet**: Archival requirements for WOO decisions and disclosed documents.

### Specificity Assessment

- **Mostly implementable** as a configuration + custom components on top of existing case type infrastructure. The spec clearly defines the WOO stages, intake fields, assessment options, and deadline rules.
- **Missing details:**
  - How is the WOO zaaktype template packaged and activated? (JSON template? Seed data in repair step? Admin UI "template library"?)
  - How does the per-document assessment UI work? (Inline in case detail? Separate assessment view? Table with dropdowns?)
  - How does Docudesk integration work technically? (API call? File share? n8n workflow?)
  - What is the "reading room" publication mechanism? (Public URL? OpenCatalogi? External platform like PLOOI?)
  - How is the inventarislijst generated? (Auto-generated document listing all documents with their assessment status?)
- **Open questions:**
  - Should the WOO template be hard-coded in the app or configurable via the admin UI?
  - Should deadline extension be a separate action or a field on the case?
  - How does the document assessment interact with Nextcloud's file management?
  - Should WOO-specific fields be propertyDefinitions or first-class case fields?
  - Is Docudesk integration via direct API or via OpenConnector?
