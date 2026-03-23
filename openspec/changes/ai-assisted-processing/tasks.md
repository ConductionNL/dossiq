# Tasks: AI-Assisted Processing

## Implementation Tasks

### Backend: Schema & Settings

- [ ] **T01**: Add `aiAuditEntry` schema to `procest_register.json` — Fields: type (enum), action (enum), caseId, documentId, model, prompt, suggestion (object), confidence (number), userAction, actualValue (object), reason, userId, timestamp (datetime), responseTimeMs (integer). Add slug-to-config mapping in `SettingsService.php` for `ai_audit_entry_schema`.

- [ ] **T02**: Add AI config keys to `SettingsService.php` — Add to `CONFIG_KEYS` array: `ai_enabled`, `ai_model_type` (local/cloud), `ai_model_url`, `ai_model_name`, `ai_api_key`, `ai_feature_classification`, `ai_feature_extraction`, `ai_feature_qa`, `ai_feature_summary`, `ai_feature_routing`, `ai_feature_decision_support`, `ai_dpia_acknowledged`, `ai_pii_stripping`. Add corresponding SLUG_TO_CONFIG_KEY entries for the new schema.

### Backend: Services & Controllers

- [ ] **T03**: Create `lib/Service/AiService.php` — Methods: `classifyDocument(string $caseId, string $documentId): array`, `extractData(string $caseId, ?string $documentId): array`, `askQuestion(string $caseId, string $question): array`, `summarize(string $caseId, string $type, ?string $documentId): array`, `suggestRouting(string $caseId): array`, `suggestNextStep(string $caseId): array`, `getAuditLog(array $filters): array`, `testHealth(): array`. Each method: (1) checks if AI is enabled and the specific feature toggle, (2) builds a prompt with case context, (3) strips PII if configured, (4) calls n8n workflow via MCP, (5) records an aiAuditEntry, (6) returns the result. Use `ContainerInterface` to get OpenRegister services. Log all AI calls.

- [ ] **T04**: Create `lib/Controller/AiController.php` — REST endpoints for all AI operations. Methods: `classify(IRequest)`, `extract(IRequest)`, `ask(IRequest)`, `summarize(IRequest)`, `suggestRouting(IRequest)`, `suggestNext(IRequest)`, `auditIndex(IRequest)`, `getSettings(IRequest)`, `updateSettings(IRequest)`, `healthCheck(IRequest)`. Each method validates input, delegates to `AiService`, returns JSONResponse. Extends `Controller`. Inject `AiService`, `SettingsService`, `LoggerInterface`.

- [ ] **T05**: Add AI routes to `appinfo/routes.php` — Add 10 routes: POST classify, POST extract, POST ask, POST summarize, POST suggest-routing, POST suggest-next, GET audit, GET ai/settings, POST ai/settings, POST ai/health.

### Frontend: API Service

- [ ] **T06**: Create `src/services/aiApi.js` — Export functions: `classifyDocument(caseId, documentId)`, `extractData(caseId, documentId)`, `askQuestion(caseId, question)`, `summarize(caseId, type, documentId)`, `suggestRouting(caseId)`, `suggestNext(caseId)`, `getAuditLog(filters)`, `getAiSettings()`, `updateAiSettings(settings)`, `testAiHealth()`. All use `axios` with `generateUrl('/apps/procest/api/ai/...')`.

### Frontend: Reusable Components

- [ ] **T07**: Create `src/views/cases/components/AiConfidenceBadge.vue` — Props: `confidence` (Number, 0.0-1.0), `size` (String, 'small'|'medium', default 'small'). Computed: level (high >0.85, medium 0.60-0.85, low <0.60), color (green/orange/red), label (e.g., "87%"). Renders a colored badge with percentage text. WCAG: includes aria-label "Confidence: 87% (high)".

- [ ] **T08**: Create `src/views/cases/components/AiSuggestionCard.vue` — Props: `suggestion` (Object: { type, value, confidence, explanation }), `loading` (Boolean), `readonly` (Boolean). Slots: default (suggestion content). Emits: `@accept(suggestion)`, `@reject(suggestion, reason)`, `@modify(suggestion, newValue)`. Template: card with suggestion content, AiConfidenceBadge, explanation text, action buttons (Accept green, Reject red, Modify blue). Reject shows a text input for reason. Uses `CnDetailCard` wrapper.

### Frontend: AI Dialogs

- [ ] **T09**: Create `src/views/cases/components/AiClassifyDialog.vue` — Props: `caseId` (String), `documentId` (String), `show` (Boolean). Emits: `@close`, `@applied(classification)`. On open: calls `classifyDocument()`, shows loading spinner, then shows suggestion with: suggested document type (NcSelect to modify), confidence badge, extracted metadata (date, sender, subject as editable fields). Confirm button applies classification to the caseDocument record. Reject button records rejection in audit trail.

- [ ] **T10**: Create `src/views/cases/components/AiExtractDialog.vue` — Props: `caseId` (String), `documentId` (String, optional), `show` (Boolean). Emits: `@close`, `@applied(fields)`. On open: calls `extractData()`, shows loading. Results: table of extracted fields with columns: field name, extracted value (editable input), confidence badge, source reference (tooltip with document name + page). Low-confidence fields (<0.60) have orange border and require individual confirmation. "Apply selected" button saves confirmed fields to case properties.

### Frontend: AI Assistant Panel

- [ ] **T11**: Create `src/views/cases/components/AiAssistantPanel.vue` — Displayed in CaseDetail sidebar when AI is enabled. Sections: (1) Q&A: text input + send button, conversation history (question/answer pairs with source citations), (2) Suggestions: list of AiSuggestionCards for next-step suggestions, deadline warnings, routing recommendations, (3) Summary: collapsible auto-generated case summary. Data: fetches suggestions on mount via `suggestNext()`. Q&A: calls `askQuestion()` on submit, appends to conversation. Conversation stored per-case in component state.

- [ ] **T12**: Create `src/views/cases/components/AiSummaryPanel.vue` — Props: `caseId` (String), `type` (String: 'case'|'document'|'timeline'), `documentId` (String, optional). On mount or button click: calls `summarize()`. Displays summary text in a collapsible panel. "Opslaan als notitie" button saves summary as a case activity entry.

### Frontend: Settings

- [ ] **T13**: Create `src/views/settings/tabs/AiSettingsTab.vue` — Sections: (1) Global toggle: "AI-ondersteuning" switch, (2) Model config: radio (local/cloud), URL input, model name select, API key (password field, only for cloud), (3) Feature toggles: 6 switches (classification, extraction, Q&A, summary, routing, decision support), (4) Privacy: PII stripping toggle, DPIA acknowledgement checkbox with warning text, (5) Health: "Test verbinding" button showing model status (connected/error) and response time. Load settings on mount via `getAiSettings()`, save on change via `updateAiSettings()`. Cloud model shows privacy warning: "Zaakgegevens worden naar een externe dienst verzonden."

### Integration

- [ ] **T14**: Integrate AI panel into `CaseDetail.vue` — Import `AiAssistantPanel`, render in sidebar section when AI is enabled (check settings). Add "AI classificeren" button to document list items. Add "AI extractie" button to case header actions. Wire dialog components (AiClassifyDialog, AiExtractDialog) with show/hide state.

- [ ] **T15**: Integrate AI settings tab into `AdminRoot.vue` — Import `AiSettingsTab`, add as tab in settings navigation. Tab visible to admins only.

## Verification Tasks

- [ ] **V01**: AI settings page loads and saves all configuration options
- [ ] **V02**: Per-feature toggles hide/show corresponding AI buttons in case detail
- [ ] **V03**: Document classification returns suggestion with confidence score
- [ ] **V04**: Data extraction shows per-field confidence with color coding
- [ ] **V05**: Q&A returns answers with source citations
- [ ] **V06**: Audit trail records all AI interactions (suggestions, accepts, rejects)
- [ ] **V07**: PII stripping removes BSN patterns from prompts when enabled
- [ ] **V08**: Cloud model configuration shows privacy warning
- [ ] **V09**: Health check button tests model connectivity
- [ ] **V10**: DPIA acknowledgement is required and logged
