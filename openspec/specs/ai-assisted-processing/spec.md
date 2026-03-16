# ai-assisted-processing Specification

## Purpose
Enable AI-assisted case processing in Procest using the existing MCP (Model Context Protocol) integration. AI capabilities include document classification and data extraction, knowledge base Q&A (RAG) for case worker support, decision support suggestions, and routing recommendations. AI assists human case workers rather than making autonomous decisions -- every AI suggestion requires human confirmation.

AI-assisted processing is an emerging capability in modern case management and data platforms, with approaches ranging from agentic AI integrated into process models with orchestrator and utility agents, AI assistants for content operations, AI field types, and AI-powered extraction from documents. Our MCP integration provides the foundation -- this spec defines how AI capabilities surface in the Procest case worker UI.

## Requirements

### Requirement: Document classification MUST suggest zaaktype and metadata
When documents are uploaded to a case or arrive unclassified, AI suggests classification.

#### Scenario: Classify an incoming document
- GIVEN a PDF document is uploaded to case `zaak-1`
- WHEN the case worker triggers "AI classify" on the document
- THEN the system MUST send the document content to the AI via MCP
- AND return a suggested `documenttype` with confidence score
- AND return suggested metadata fields (e.g., `date`, `sender`, `subject`)
- AND the case worker MUST confirm or modify the suggestion before it is applied

#### Scenario: Route unclassified document to correct case
- GIVEN a document arrives via OpenConnector without case linkage
- WHEN the AI analyzes the document content
- THEN it MUST suggest one or more candidate cases ranked by relevance
- AND the case worker MUST select the correct case from the suggestions

### Requirement: Data extraction MUST populate case fields from documents
AI reads document content and suggests field values for the case or related objects.

#### Scenario: Extract structured data from a permit application
- GIVEN a scanned permit application PDF is attached to case `zaak-1`
- WHEN the case worker triggers "AI extract"
- THEN the system MUST extract key-value pairs from the document
- AND map them to the case schema fields (e.g., `applicant_name`, `address`, `requested_activity`)
- AND present the extracted values as suggestions in the case form
- AND the case worker MUST review and confirm each extracted value

#### Scenario: Extraction confidence indicators
- GIVEN AI extracts 10 fields from a document
- WHEN presenting results to the case worker
- THEN each field MUST show a confidence indicator (high/medium/low)
- AND low-confidence fields MUST be visually highlighted for careful review

### Requirement: Knowledge base Q&A MUST answer case worker questions
RAG-based Q&A allows case workers to ask questions about policies, procedures, and regulations relevant to their case.

#### Scenario: Ask a policy question in case context
- GIVEN a case worker is handling a `omgevingsvergunning` case
- WHEN they ask "What are the maximum building heights in zone B?"
- THEN the system MUST search relevant policy documents via RAG
- AND return an answer with source citations (document name, page/section)
- AND the answer MUST be scoped to the municipality's own policy documents

#### Scenario: No answer available
- GIVEN a case worker asks a question with no relevant documents in the knowledge base
- THEN the system MUST respond with "No relevant information found" rather than hallucinating an answer
- AND suggest uploading relevant policy documents to the knowledge base

### Requirement: Decision support MUST suggest next actions
AI analyzes case state and history to suggest what the case worker should do next.

#### Scenario: Suggest next step based on case state
- GIVEN case `zaak-1` has status `intake_complete` and all required documents are uploaded
- WHEN the case worker opens the case
- THEN the AI MAY suggest "All intake documents are present. Consider moving to assessment phase."
- AND the suggestion MUST be dismissable and non-blocking

#### Scenario: Flag potential issues
- GIVEN case `zaak-1` has a `bezwaartermijn` ending in 3 days and no decision has been recorded
- WHEN the case worker opens the case
- THEN the AI MUST flag "Bezwaartermijn ends in 3 days -- decision may be needed"
- AND link to the relevant deadline information

### Requirement: All AI interactions MUST be audited
Every AI suggestion, acceptance, and rejection is recorded for accountability.

#### Scenario: Audit trail for accepted suggestion
- GIVEN AI suggests `documenttype: "bezwaarschrift"` for a document
- WHEN the case worker accepts the suggestion
- THEN an audit trail entry MUST record:
  - `action`: `ai.suggestion.accepted`
  - `model`: the AI model used
  - `suggestion`: the original suggestion
  - `user`: the case worker who accepted it

#### Scenario: Audit trail for rejected suggestion
- GIVEN AI suggests routing a document to case `zaak-1`
- WHEN the case worker rejects and manually assigns to `zaak-2`
- THEN an audit trail entry MUST record:
  - `action`: `ai.suggestion.rejected`
  - `suggestion`: `{"case": "zaak-1"}`
  - `actual`: `{"case": "zaak-2"}`
  - `user`: the case worker

### Requirement: AI features MUST be opt-in and configurable
Not all municipalities want AI features. They must be individually toggleable.

#### Scenario: Disable AI features
- GIVEN an admin disables AI-assisted processing in app settings
- THEN no AI buttons or suggestions MUST appear in the case worker UI
- AND no data MUST be sent to AI models

#### Scenario: Configure AI model
- GIVEN AI features are enabled
- WHEN an admin configures the AI model endpoint (local Ollama or external API)
- THEN all AI requests MUST use the configured model
- AND the configuration MUST support both local (privacy-preserving) and cloud models

### Current Implementation Status

**Not yet implemented.** No AI-related services, controllers, or Vue components exist in the Procest codebase. The MCP integration infrastructure exists at the workspace level (`.mcp.json` with n8n-mcp and OpenRegister MCP), but Procest itself has no AI document classification, data extraction, knowledge base Q&A, or decision support functionality.

**Foundation available:**
- The n8n MCP server is configured at the workspace level, providing workflow orchestration that could trigger AI pipelines.
- OpenRegister MCP provides data access that AI tools could query.
- The `objectStore` pattern (`src/store/modules/object.js`) with `auditTrailsPlugin` provides the audit infrastructure that AI interaction logging would use.

**Partial implementations:** None.

### Standards & References

- **MCP (Model Context Protocol)**: Anthropic's standard for LLM tool integration -- the foundation for AI features.
- **GDPR / AVG**: AI processing of citizen data requires Data Protection Impact Assessment (DPIA), especially for document classification containing PII.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Government security baseline applies to AI model endpoints and data handling.
- **Algoritmeregister**: Dutch government requirement to register algorithmic decision-making systems.
- **Common Ground**: AI services should be deployable as Common Ground components (API-first, layered architecture).
- **WCAG AA**: AI suggestion UI must be accessible.

### Specificity Assessment

This spec is at a conceptual level -- suitable for roadmap planning but not implementation-ready.

**What's missing:**
- No UI wireframes or component specifications for the AI suggestion interface.
- No specification of which MCP tools/prompts would be used for each AI capability.
- No data model for AI suggestions, confidence scores, or audit entries.
- No specification of the n8n workflow structure for AI pipelines.
- No performance requirements (latency for AI responses, timeout handling).
- "Via MCP" is vague -- needs concrete tool names, parameters, and response schemas.
- Knowledge base (RAG) assumes a document corpus but doesn't specify how documents enter the knowledge base.

**Open questions:**
1. Which LLM models are supported (Ollama models, OpenAI, Azure OpenAI)?
2. What is the maximum document size for classification/extraction?
3. How does the knowledge base corpus get populated -- manual upload or automatic from case documents?
4. Should AI suggestions be cached or computed on-demand each time?
5. What is the privacy boundary -- can document content leave the Nextcloud instance?
