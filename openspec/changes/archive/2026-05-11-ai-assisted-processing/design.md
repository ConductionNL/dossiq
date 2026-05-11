# Design: ai-assisted-processing

## Architecture Overview

AI-assisted processing adds an AI layer to Procest's case management. All AI interactions flow through n8n workflows via MCP, with results surfaced in the Vue frontend for human confirmation. An immutable audit trail records every AI suggestion.

```
CaseDetail.vue
├── AiAssistantPanel.vue (sidebar panel for Q&A, suggestions, summaries)
│   ├── AiChatHistory.vue (conversation thread)
│   ├── AiSuggestionCard.vue (accept/reject suggestion)
│   └── AiConfidenceBadge.vue (confidence indicator)
├── AiClassifyDialog.vue (document classification modal)
├── AiExtractDialog.vue (data extraction results modal)
└── AiSummaryPanel.vue (auto-generated case/document summaries)

AdminRoot.vue
└── AiSettingsTab.vue (AI configuration: toggles, model, health)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/AiService.php` | AI orchestration service — delegates to n8n MCP for classification, extraction, Q&A, summarization; strips PII from prompts; records audit entries |
| `lib/Controller/AiController.php` | API endpoints: classify document, extract data, ask question, summarize, get suggestions, get audit log |
| `src/views/cases/components/AiAssistantPanel.vue` | Collapsible AI assistant panel in case detail sidebar — Q&A input, conversation history, suggestion cards |
| `src/views/cases/components/AiClassifyDialog.vue` | Modal showing classification suggestion with confidence, confirm/modify/reject actions |
| `src/views/cases/components/AiExtractDialog.vue` | Modal showing extracted fields with per-field confidence indicators, bulk/individual confirm |
| `src/views/cases/components/AiSuggestionCard.vue` | Reusable card for displaying an AI suggestion with accept/reject buttons and confidence badge |
| `src/views/cases/components/AiConfidenceBadge.vue` | Visual confidence indicator: high (green, >0.85), medium (orange, 0.60-0.85), low (red, <0.60) |
| `src/views/cases/components/AiSummaryPanel.vue` | Panel showing auto-generated summaries (case, document, timeline) |
| `src/views/settings/tabs/AiSettingsTab.vue` | Admin settings tab: global toggle, per-feature toggles, model config, health check button, DPIA acknowledgement |
| `src/services/aiApi.js` | Frontend API service for AI endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `aiAuditEntry` schema |
| `lib/Service/SettingsService.php` | Add AI config keys (`ai_enabled`, `ai_model_type`, `ai_model_url`, `ai_model_name`, `ai_api_key`, `ai_feature_*` toggles, `ai_dpia_acknowledged`) |
| `src/views/cases/CaseDetail.vue` | Import and render AiAssistantPanel in sidebar, add AI action buttons to document list |
| `src/views/settings/AdminRoot.vue` | Add AiSettingsTab to settings tabs |
| `src/router/index.js` | No changes needed (settings route already exists) |
| `appinfo/routes.php` | Add AI API routes |

## Design Decisions

### DD-01: n8n MCP for AI Orchestration

**Decision**: AI model calls go through n8n workflows via the n8n MCP server, not directly from PHP to the LLM API.

**Rationale**: n8n provides visual workflow editing, retry logic, model switching, and prompt management without code changes. Municipalities can customize AI prompts and add processing steps without developer involvement.

### DD-02: AI Audit Trail as OpenRegister Objects

**Decision**: Store AI audit entries as OpenRegister objects with schema `aiAuditEntry`, not in a separate database table.

**Rationale**: Consistent with the existing data model. OpenRegister provides built-in audit trails, search, and retention management. Audit entries inherit the case's retention policy.

### DD-03: PII Stripping Before AI Calls

**Decision**: The `AiService` strips BSN, financial data, and health information from prompts before sending to the AI model.

**Rationale**: GDPR/AVG data minimization principle. Even for local models, reducing PII in prompts reduces risk. The stripping is configurable (can be disabled for local models via settings).

### DD-04: Human-in-the-Loop Enforced by UI

**Decision**: AI suggestions are always presented as proposals requiring explicit user confirmation. No auto-apply.

**Rationale**: Algoritmeregister compliance requires human oversight for AI-assisted decisions. The UI enforces this by showing modals with confirm/reject actions.

## OpenRegister Schema: aiAuditEntry

```json
{
  "slug": "aiAuditEntry",
  "title": "AI Audit Entry",
  "type": "object",
  "required": ["type", "model", "timestamp"],
  "properties": {
    "type": { "type": "string", "enum": ["classification", "extraction", "qa", "summary", "routing", "decision_support"] },
    "action": { "type": "string", "enum": ["suggested", "accepted", "rejected", "modified"] },
    "caseId": { "type": "string", "description": "Reference to the case" },
    "documentId": { "type": "string", "description": "Reference to the document (if applicable)" },
    "model": { "type": "string", "description": "AI model identifier (e.g., ollama/llama3.1)" },
    "prompt": { "type": "string", "description": "The prompt sent to the AI (for audit)" },
    "suggestion": { "type": "object", "description": "The AI suggestion payload" },
    "confidence": { "type": "number", "description": "Confidence score 0.0-1.0" },
    "userAction": { "type": "string", "description": "What the user did (accepted/rejected/modified)" },
    "actualValue": { "type": "object", "description": "The value actually applied (may differ from suggestion)" },
    "reason": { "type": "string", "description": "User's reason for rejection/modification" },
    "userId": { "type": "string", "description": "Nextcloud user ID" },
    "timestamp": { "type": "string", "format": "date-time" },
    "responseTimeMs": { "type": "integer", "description": "AI model response time in milliseconds" }
  }
}
```

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/ai/classify` | Classify a document (body: caseId, documentId) |
| POST | `/api/ai/extract` | Extract data from document(s) (body: caseId, documentId?) |
| POST | `/api/ai/ask` | Ask a knowledge base question (body: caseId, question) |
| POST | `/api/ai/summarize` | Generate summary (body: caseId, type: case/document/timeline, documentId?) |
| POST | `/api/ai/suggest-routing` | Get case routing suggestion (body: caseId) |
| POST | `/api/ai/suggest-next` | Get next-step suggestion (body: caseId) |
| GET | `/api/ai/audit` | Get AI audit trail (query: caseId?, type?, limit, offset) |
| GET | `/api/ai/settings` | Get AI settings |
| POST | `/api/ai/settings` | Update AI settings |
| POST | `/api/ai/health` | Test AI model connectivity |
