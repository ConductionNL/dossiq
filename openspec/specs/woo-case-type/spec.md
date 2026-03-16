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
