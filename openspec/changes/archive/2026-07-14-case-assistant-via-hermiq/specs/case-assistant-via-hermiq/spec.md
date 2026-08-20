# case-assistant-via-hermiq (delta)

Procest consumes Hermiq's case-assistant-surface as a thin client: an
availability-gated chat panel on the case detail page, fail-closed case-
context enrichment, and audit coverage through the existing AI oversight
trail. Procest carries NO LLM/prompt logic (fleet rule: AI lives in Hermiq).

## ADDED Requirements

### Requirement: Conversational assistant on the case detail page
The system MUST render a chat panel on the CaseDetail page that lets an
authenticated user ask free-form questions about the case and shows the
assistant's replies, delegating the conversation itself to Hermiq's
`POST /api/assistant/converse` endpoint.

#### Scenario: User asks a question about a case
- **GIVEN** an authenticated user viewing a case they may read, with the
  hermiq app installed and enabled
- **WHEN** they submit a question in the case-assistant panel
- **THEN** the system MUST forward the message plus the bounded case-context
  summary to Hermiq and MUST render the returned reply in the transcript

#### Scenario: Follow-up question keeps conversational continuity
- **GIVEN** a user who already exchanged a message on this case
- **WHEN** they send a follow-up question
- **THEN** the system MUST reuse the stored per-(user, case) Hermiq session
  so the assistant sees the prior turns

### Requirement: Feature-gated on Hermiq availability
The system MUST hide the assistant panel entirely when the hermiq app is not
installed or not enabled for the user (`IAppManager::isEnabledForUser`), and
MUST treat a failing availability probe as unavailable (fail closed to
hidden) — never a permanently-erroring panel.

#### Scenario: Hermiq is not installed
- **GIVEN** an instance without the hermiq app
- **WHEN** a user opens a case detail page
- **THEN** the case-assistant panel MUST NOT render
- **AND** no converse call is possible (the backend returns 503 for a direct
  API call)

### Requirement: Fail-closed case-context enrichment
The system MUST load the case through the standard OpenRegister read path
(inheriting its authorization scoping) before any Hermiq call, and MUST map
"OpenRegister unavailable", "case not found", and "case not readable by this
user" to the SAME 404 response — never distinguishable, never falling back
to an unauthorized or context-less Hermiq call.

#### Scenario: User requests the assistant on a case they cannot read
- **GIVEN** a case the requesting user is not authorized to read
- **WHEN** they POST to `/api/assistant/converse` with that caseId
- **THEN** the system MUST return 404 and MUST NOT call Hermiq

#### Scenario: OpenRegister is unavailable
- **GIVEN** the OpenRegister app is unavailable
- **WHEN** a user posts a converse request
- **THEN** the system MUST return the same 404 — refusal, not a context-less
  answer (a check that defaults open on service failure is fail-open)

### Requirement: Bounded context — only fields the user already sees
The system MUST build the case context sent to Hermiq from a whitelist of
the fields the CaseDetail page's own data widgets render (title, identifier,
truncated description, caseType, status, confidentiality, startDate,
deadline, isFinalStatus) and MUST NOT include document contents, contacts,
or initiator personal data.

#### Scenario: Case has initiator PII and documents
- **GIVEN** a case carrying `initiatorDisplayName` and linked documents
- **WHEN** a converse request is enriched
- **THEN** the forwarded `contextData` MUST NOT contain initiator fields or
  document data

### Requirement: Assistant exchanges appear in the AI oversight trail
The system MUST record every assistant exchange through the existing
`AiService` audit sink (same register/schema as the discrete AI operations),
with `type: assistant`, the caseId, the requesting userId, the prompt, and
the reply — so the existing oversight page and audit export cover the
conversational surface with no second audit mechanism.

#### Scenario: Exchange is audited
- **GIVEN** a successful converse round-trip
- **WHEN** the reply is returned to the user
- **THEN** an audit entry for the exchange MUST exist in the AI audit
  register, retrievable via the existing `/api/ai/audit` endpoint

### Requirement: Guardrail refusals surface distinctly
The system MUST relay Hermiq's 422 guardrail block as a distinct, translated
user-facing message, keyed off the stable `errorCode: guardrail_blocked` —
never off backend message text.

#### Scenario: Message blocked by the organisation's guardrail policy
- **GIVEN** Hermiq refuses a turn with 422 `guardrail_blocked`
- **WHEN** the panel receives the failure
- **THEN** it MUST show the guardrail-specific message (not the generic
  unavailable message) and MUST leave the transcript otherwise intact
