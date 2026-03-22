# Proposal: ai-assisted-processing

## Summary

Implement AI-assisted case processing for Procest — document classification, data extraction, knowledge base Q&A (RAG), decision support suggestions, case auto-summarization, AI routing, and a full AI audit trail. All AI operations follow the human-in-the-loop principle: AI suggests, humans confirm.

## Motivation

AI-assisted processing is the #1 emerging differentiator in case management tenders. The MCP integration with n8n provides the orchestration foundation, but Procest currently has zero AI-related UI components, services, or schemas. This change implements the V1 foundation: AI settings management (per-feature toggles, model configuration), the AI assistant panel on the case detail view, document classification and data extraction triggers, and a complete audit trail for Algoritmeregister compliance.

## Affected Projects

- [ ] Project: `procest` — Add AI services, settings UI, case detail AI panel, audit trail schemas

## Scope

### In Scope (V1)

- **AI Settings** (REQ 8): Global toggle, per-feature toggles (6 features), model configuration (local Ollama / cloud), health monitoring, DPIA acknowledgement
- **Document Classification** (REQ 1): "AI classificeren" button on case documents, confidence scoring, classification suggestion UI with confirm/reject
- **Data Extraction** (REQ 2): "AI extractie" button, extracted field suggestions with confidence indicators (high/medium/low), per-field confirm
- **Knowledge Base Q&A** (REQ 3): AI assistant panel on case detail, question input, answer with source citations, conversation history
- **Decision Support** (REQ 4): Next-step suggestions, deadline warnings, case summarization, similar case detection
- **Auto-Summarization** (REQ 5): Document summary, timeline summary, case overview summary
- **AI Audit Trail** (REQ 6): Immutable audit entries for all AI interactions (suggestions, acceptances, rejections), aggregate reporting
- **AI Routing** (REQ 7): Case worker recommendation based on expertise and workload
- **Privacy** (REQ 9): Data minimization in prompts, BSN/PII stripping, DPIA tracking

### Out of Scope

- Auto-classification on upload (background processing — needs queue infrastructure)
- Knowledge base population and RAG indexing (needs Docudesk search integration)
- Workload balancing visualization on team dashboard
- Custom system prompt per zaaktype (admin UI — can use text field in settings)

## Approach

1. **Backend**: New `AiService` PHP class that orchestrates AI calls via n8n MCP workflows. New `AiSettingsController` for AI configuration management. AI audit trail stored as OpenRegister objects with schema `aiAuditEntry`.
2. **Frontend**: New `AiAssistantPanel.vue` component on case detail sidebar. New AI settings tab in admin settings. Document and extraction suggestion modals.
3. **Schemas**: Add `aiAuditEntry` schema to `procest_register.json`. Add AI config keys to `SettingsService`.
4. **Integration**: AI features triggered by user action (button click), routed through n8n workflows via MCP, results displayed in UI for human confirmation.

## Cross-Project Dependencies

- **n8n MCP server**: Orchestrates AI model calls via workflows
- **OpenRegister MCP**: Provides case data for AI context
- **Ollama / LLM provider**: Model inference (configured in settings)
- **Docudesk**: OCR for scanned documents (REQ 1, Scenario 1.5)
