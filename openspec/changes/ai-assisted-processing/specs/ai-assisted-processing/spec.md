---
status: proposed
---

# ai-assisted-processing Specification

## Purpose
Enable AI-assisted case processing in Procest using the existing MCP (Model Context Protocol) integration. AI capabilities include document classification and data extraction, knowledge base Q&A (RAG) for case worker support, decision support suggestions, case routing recommendations, and auto-summarization. AI assists human case workers rather than making autonomous decisions -- every AI suggestion requires human confirmation.

## Context
AI-assisted processing is an emerging capability in modern case management platforms. Flowable's Agentic AI integrates orchestrator, knowledge, document, and utility AI agents directly into the CMMN engine with full audit trails. Our MCP integration with n8n provides the foundation for similar capabilities without requiring a proprietary AI engine -- n8n workflows orchestrate AI model calls while Procest surfaces the results in the case worker UI. This spec defines how AI capabilities surface in Procest, following the human-in-the-loop principle mandated by Dutch government AI governance (Algoritmeregister).

## Requirements

### Requirement 1: Document classification with zaaktype and metadata suggestion
When documents are uploaded to a case or arrive unclassified, AI MUST suggest classification with confidence scoring.

#### Scenario 1.1: Classify incoming document by type
- GIVEN a PDF document uploaded to case `zaak-1`
- WHEN the case worker clicks "AI classificeren" on the document in the case detail view
- THEN the system MUST send the document content to the configured AI model via an n8n workflow triggered through MCP
- AND return a suggested `documentType` (from the case type's configured document types) with a confidence score (0.0-1.0)
- AND return suggested metadata fields (date, sender, subject) extracted from the document content
- AND the case worker MUST confirm or modify the suggestion before it is applied to the `caseDocument` record

#### Scenario 1.2: Route unclassified document to correct case
- GIVEN a document arrives via OpenConnector without case linkage
- WHEN the case worker triggers "AI routeren" on the document
- THEN the AI MUST analyze the document content and compare it against active cases in the register
- AND return up to 5 candidate cases ranked by relevance score
- AND each candidate MUST show the case title, identifier, zaaktype, and relevance explanation
- AND the case worker MUST select the correct case to link the document

#### Scenario 1.3: Auto-suggest classification on upload
- GIVEN AI auto-classification is enabled in app settings
- WHEN a document is uploaded to a case
- THEN the system MUST automatically trigger classification in the background
- AND display the suggestion as a dismissable banner on the document: "AI suggests: Bezwaarschrift (87% confidence)"
- AND the suggestion MUST expire after 7 days if not acted upon

#### Scenario 1.4: Classification model selection per zaaktype
- GIVEN different zaaktypes may benefit from different classification prompts
- WHEN an admin configures AI classification for a specific zaaktype
- THEN they MUST be able to specify a custom system prompt that includes zaaktype-specific document type descriptions
- AND the default prompt MUST use the document type names and descriptions from the zaaktype configuration

#### Scenario 1.5: Classification handles non-text documents
- GIVEN a scanned image document (TIFF/JPEG) is uploaded
- WHEN the case worker triggers "AI classificeren"
- THEN the system MUST first perform OCR (via Docudesk or the AI model's vision capabilities)
- AND then classify the extracted text
- AND indicate to the case worker that OCR was used with the OCR confidence level

### Requirement 2: Data extraction from documents to case fields
AI MUST read document content and suggest field values for the case or related objects.

#### Scenario 2.1: Extract structured data from application document
- GIVEN a permit application PDF attached to case `zaak-1` with zaaktype `omgevingsvergunning`
- WHEN the case worker triggers "AI extractie"
- THEN the system MUST extract key-value pairs from the document content
- AND map them to the case's property definitions (e.g., `applicant_name`, `address`, `requested_activity`)
- AND present the extracted values as pre-filled suggestions in the case form (editable, not auto-saved)
- AND the case worker MUST review and confirm each extracted value before it is saved

#### Scenario 2.2: Confidence indicators per extracted field
- GIVEN AI extracts 10 fields from a document
- WHEN presenting results to the case worker
- THEN each field MUST show a confidence indicator: high (>0.85), medium (0.60-0.85), low (<0.60)
- AND low-confidence fields MUST be visually highlighted with an orange border for careful review
- AND the case worker MUST explicitly confirm low-confidence fields (not just bulk-accept)

#### Scenario 2.3: Extraction from multiple documents
- GIVEN a case with 5 uploaded documents
- WHEN the case worker triggers "AI extractie" on the case level (not a single document)
- THEN the AI MUST analyze all documents and merge extracted fields, preferring the highest-confidence value when conflicts occur
- AND conflicting values MUST be flagged for manual resolution with source document references

#### Scenario 2.4: Extraction template per zaaktype
- GIVEN a zaaktype with specific property definitions
- WHEN AI extraction runs
- THEN the extraction prompt MUST include the zaaktype's property definitions as the target schema
- AND only extract fields that match defined properties (no arbitrary key-value extraction)

#### Scenario 2.5: Extraction preserves source reference
- GIVEN an extracted field value "Jan de Vries" for property "applicant_name"
- THEN the extraction result MUST include the source document name, page number, and surrounding text snippet
- AND this reference MUST be viewable by the case worker when hovering over the extracted value

### Requirement 3: Knowledge base Q&A (RAG) for case worker support
RAG-based Q&A MUST allow case workers to ask questions about policies, procedures, and regulations relevant to their case.

#### Scenario 3.1: Ask a policy question in case context
- GIVEN a case worker handling an `omgevingsvergunning` case
- WHEN they open the AI assistant panel and ask "Wat zijn de maximale bouwhoogtes in zone B?"
- THEN the system MUST search relevant policy documents in the knowledge base via RAG
- AND return an answer with source citations (document name, page/section, direct quote)
- AND the answer MUST be scoped to the municipality's own policy documents first, then national regulations

#### Scenario 3.2: No answer available -- refuse to hallucinate
- GIVEN a case worker asks a question with no relevant documents in the knowledge base
- THEN the system MUST respond with "Geen relevante informatie gevonden in de kennisbank"
- AND suggest: "Voeg relevante beleidsdocumenten toe aan de kennisbank"
- AND MUST NOT generate a plausible-sounding but unsourced answer

#### Scenario 3.3: Knowledge base population from case documents
- GIVEN an admin enables "auto-index case documents" for a zaaktype
- WHEN documents are uploaded to cases of that type
- THEN policy documents (beleidsstukken, verordeningen) MUST be automatically indexed in the RAG knowledge base
- AND case-specific documents (citizen applications, personal data) MUST NOT be indexed unless explicitly marked as policy documents

#### Scenario 3.4: Context-aware answers
- GIVEN a case worker asks "Hoeveel tijd heb ik nog voor een besluit?"
- WHEN the AI assistant has access to the current case's deadline information
- THEN the answer MUST include the specific deadline date and days remaining from the case data
- AND cite the relevant legal basis for the deadline (e.g., WOO Art. 4.4 for WOO cases)

#### Scenario 3.5: Conversation history within case
- GIVEN a case worker has asked 3 questions in the AI assistant for case `zaak-1`
- WHEN they ask a follow-up question
- THEN the system MUST include the previous questions and answers as conversation context
- AND the conversation history MUST be stored on the case for audit and handover purposes

### Requirement 4: Decision support and next-action suggestions
AI MUST analyze case state and history to suggest what the case worker should do next.

#### Scenario 4.1: Suggest next step based on case state
- GIVEN case `zaak-1` has status `intake_complete` and all required documents are uploaded
- WHEN the case worker opens the case
- THEN the AI assistant panel MAY show: "Alle intake documenten zijn aanwezig. Overweeg de zaak naar beoordelingsfase te verplaatsen."
- AND the suggestion MUST be dismissable and non-blocking
- AND the suggestion MUST include a one-click action to execute the suggested step

#### Scenario 4.2: Flag potential deadline issues
- GIVEN case `zaak-1` has a bezwaartermijn ending in 3 days and no decision recorded
- WHEN the case worker opens the case
- THEN the AI MUST flag: "Bezwaartermijn verloopt over 3 dagen -- besluit is mogelijk nodig"
- AND the flag MUST appear as a prominent warning in the case detail header
- AND link to the relevant deadline information in the `DeadlinePanel`

#### Scenario 4.3: Summarize case for handover
- GIVEN a case worker requests "AI samenvatting" for case `zaak-1`
- WHEN the AI processes the case data (status history, documents, notes, tasks)
- THEN it MUST generate a structured summary with: current status, key dates, open tasks, recent activity, and recommended next steps
- AND the summary MUST be savable as a case note in the `ActivityTimeline`

#### Scenario 4.4: Similar case detection
- GIVEN a new case is created with certain properties (zaaktype, subject, applicant)
- WHEN the case worker triggers "Vergelijkbare zaken zoeken"
- THEN the AI MUST search for similar completed cases based on content similarity
- AND return up to 5 similar cases with their outcomes (resultaat) and processing time
- AND the case worker MUST be able to view the similar cases for reference

#### Scenario 4.5: Workload balancing suggestions
- GIVEN a team has 50 active cases distributed across 5 case workers
- WHEN a manager views the team dashboard
- THEN the AI MAY suggest workload redistribution: "Medewerker A heeft 15 zaken (3 urgent), medewerker B heeft 5. Overweeg herverdeling."
- AND the suggestion MUST be based on case count, urgency, and estimated complexity

### Requirement 5: Case auto-summarization
AI MUST generate human-readable summaries of case content for quick orientation.

#### Scenario 5.1: Auto-summary on case open
- GIVEN a case with more than 5 documents and 10 timeline entries
- WHEN the case worker opens the case for the first time (or after 7+ days)
- THEN the system MAY display an auto-generated summary panel at the top of the case detail
- AND the summary MUST cover: what the case is about, current status, key dates, and what needs attention

#### Scenario 5.2: Document summary
- GIVEN a 25-page policy document attached to a case
- WHEN the case worker clicks "AI samenvatting" on the document
- THEN the system MUST generate a 3-5 sentence summary of the document
- AND display it inline below the document title in the case document list

#### Scenario 5.3: Timeline summary for long-running cases
- GIVEN a case with 50+ timeline entries spanning 6 months
- WHEN the case worker clicks "Tijdlijn samenvatting"
- THEN the AI MUST generate a chronological summary highlighting key events (status changes, decisions, escalations)
- AND the summary MUST be displayable as a collapsed panel above the full timeline

### Requirement 6: AI interaction audit trail
Every AI suggestion, acceptance, and rejection MUST be recorded for accountability and Algoritmeregister compliance.

#### Scenario 6.1: Audit trail for accepted suggestion
- GIVEN AI suggests `documentType: "bezwaarschrift"` for a document with confidence 0.92
- WHEN the case worker accepts the suggestion
- THEN an audit entry MUST be created in the case's activity log with:
  - `type`: `ai.suggestion.accepted`
  - `model`: the AI model identifier (e.g., "ollama/llama3.1")
  - `suggestion`: the original suggestion payload
  - `confidence`: 0.92
  - `user`: the case worker who accepted
  - `timestamp`: ISO 8601 datetime

#### Scenario 6.2: Audit trail for rejected suggestion
- GIVEN AI suggests routing a document to case `zaak-1`
- WHEN the case worker rejects the suggestion and manually assigns to `zaak-2`
- THEN an audit entry MUST record:
  - `type`: `ai.suggestion.rejected`
  - `suggestion`: `{"case": "zaak-1", "confidence": 0.78}`
  - `actual`: `{"case": "zaak-2"}`
  - `reason`: optional free-text reason from the case worker
  - `user`: the case worker

#### Scenario 6.3: Audit trail for RAG Q&A
- GIVEN a case worker asks a question via the knowledge base
- THEN an audit entry MUST record the question, the answer, the source documents cited, and the model used
- AND this MUST be queryable for Algoritmeregister reporting

#### Scenario 6.4: Aggregate AI usage reporting
- GIVEN an admin requests AI usage statistics
- THEN the system MUST provide: total suggestions made, acceptance rate, rejection rate, average confidence scores, most common suggestion types, and per-model usage breakdown

#### Scenario 6.5: Audit entries are immutable
- GIVEN an AI audit trail entry has been created
- THEN it MUST NOT be editable or deletable by any user
- AND it MUST be retained for at least the case's archival retention period

### Requirement 7: AI case routing recommendations
AI MUST suggest the best case worker or team for incoming cases based on expertise and workload.

#### Scenario 7.1: Route new case to specialist
- GIVEN a new WOO case arrives via intake
- WHEN the case is created and AI routing is enabled
- THEN the AI MUST analyze the case subject and recommend a case worker with WOO expertise
- AND the recommendation MUST factor in current workload (number of active cases per worker)
- AND the case worker MUST confirm assignment

#### Scenario 7.2: Route based on geographic area
- GIVEN a case related to a specific neighborhood or address
- WHEN AI routing analyzes the case
- THEN it MUST consider geographic assignment rules (wijkteam, gebiedsteam) if configured
- AND suggest the case worker responsible for that area

#### Scenario 7.3: Escalation routing
- GIVEN a case that has been stalled for more than its expected processing time
- WHEN the AI detects the stall during periodic analysis
- THEN it MUST suggest escalation to a senior case worker or manager
- AND include the stall duration and potential reasons in the suggestion

### Requirement 8: AI features opt-in and configuration
AI features MUST be individually toggleable per municipality, with support for local and cloud AI models.

#### Scenario 8.1: Disable all AI features
- GIVEN an admin navigates to Procest app settings
- WHEN they toggle "AI-ondersteuning" to disabled
- THEN no AI buttons, panels, or suggestions MUST appear in the case worker UI
- AND no case data MUST be sent to any AI model
- AND the toggle MUST take effect immediately without requiring app restart

#### Scenario 8.2: Configure local AI model (Ollama)
- GIVEN AI features are enabled
- WHEN an admin configures the AI model as a local Ollama instance (e.g., `http://ollama:11434`)
- THEN all AI requests MUST be routed to the local model
- AND the admin MUST be able to select the specific model (e.g., llama3.1, mistral, qwen2.5)
- AND document content MUST NOT leave the Nextcloud server network

#### Scenario 8.3: Configure cloud AI model
- GIVEN AI features are enabled
- WHEN an admin configures an external AI model (OpenAI, Azure OpenAI, Anthropic)
- THEN the system MUST display a warning: "Zaakgegevens worden naar een externe dienst verzonden. Zorg dat dit past binnen uw verwerkingsovereenkomst."
- AND the admin MUST explicitly acknowledge the privacy implications
- AND the configuration MUST store the API key securely via Nextcloud's credential store

#### Scenario 8.4: Feature-level toggles
- GIVEN AI features are globally enabled
- THEN the admin MUST be able to individually toggle:
  - Document classification (on/off)
  - Data extraction (on/off)
  - Knowledge base Q&A (on/off)
  - Decision support suggestions (on/off)
  - Auto-summarization (on/off)
  - Case routing (on/off)
- AND each feature MUST work independently

#### Scenario 8.5: AI model health monitoring
- GIVEN an AI model is configured
- THEN the settings page MUST show the model connection status (connected/error)
- AND a "Test verbinding" button MUST send a test prompt and display the response time
- AND if the model is unreachable, AI features MUST gracefully degrade (hide AI buttons, show "AI niet beschikbaar" on hover)

### Requirement 9: Privacy and data protection for AI processing
AI processing MUST comply with AVG/GDPR and BIO requirements for government data.

#### Scenario 9.1: Data minimization in AI prompts
- GIVEN the system sends case data to an AI model for classification
- THEN only the minimum necessary data MUST be included in the prompt (document content, not full case history)
- AND BSN, financial data, and health information MUST be stripped from prompts unless explicitly required for the task

#### Scenario 9.2: DPIA requirement tracking
- GIVEN AI features are enabled for the first time
- THEN the system MUST display a warning: "AI-verwerking van zaakgegevens vereist een Data Protection Impact Assessment (DPIA)"
- AND the admin MUST acknowledge this requirement
- AND the acknowledgement MUST be logged

#### Scenario 9.3: Data retention for AI interactions
- GIVEN AI interaction data (prompts, responses) is stored for audit purposes
- THEN the retention period MUST match the case's archival retention period
- AND when a case is destroyed per retention policy, associated AI audit data MUST also be destroyed

## Dependencies
- n8n MCP server (for AI workflow orchestration)
- OpenRegister MCP (for case data access)
- Ollama or external LLM provider (for AI model inference)
- Docudesk (for OCR of scanned documents)
- OpenConnector (for document ingestion from external sources)
- Nextcloud AI integration (`OCP\TextProcessing`) as potential alternative backend

---

### Current Implementation Status

**Not yet implemented.** No AI-related services, controllers, or Vue components exist in the Procest codebase. The MCP integration infrastructure exists at the workspace level (`.mcp.json` with n8n-mcp and OpenRegister MCP), but Procest itself has no AI document classification, data extraction, knowledge base Q&A, or decision support functionality.

**Foundation available:**
- The n8n MCP server is configured at the workspace level, providing workflow orchestration that could trigger AI pipelines.
- OpenRegister MCP provides data access that AI tools could query.
- The `objectStore` pattern (`src/store/modules/object.js`) with `auditTrailsPlugin` provides the audit infrastructure that AI interaction logging would use.
- `ActivityTimeline.vue` supports activity entries with type, description, user, and date -- extensible for AI audit entries.
- Nextcloud's `OCP\TextProcessing\IManager` provides a native AI abstraction that could serve as an alternative to direct MCP calls.

**Partial implementations:** None.

### Standards & References

- **MCP (Model Context Protocol)**: Anthropic's standard for LLM tool integration -- the foundation for AI features.
- **GDPR / AVG**: AI processing of citizen data requires Data Protection Impact Assessment (DPIA), especially for document classification containing PII.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Government security baseline applies to AI model endpoints and data handling.
- **Algoritmeregister**: Dutch government requirement to register algorithmic decision-making systems. All AI features that influence case outcomes must be registered.
- **Common Ground**: AI services should be deployable as Common Ground components (API-first, layered architecture).
- **WCAG AA**: AI suggestion UI must be accessible, including screen reader announcements for suggestions.
- **Flowable Agentic AI**: Reference architecture for integrating AI agents into CMMN case management (orchestrator, knowledge, document, utility agents).
- **CMMN 1.1**: AI suggestions map to SentryEvents that can trigger case plan items.
