# woo-llm-anonymisation Specification

## Purpose
TBD - created by archiving change woo-llm-anonymisation. Update Purpose after archive.
## Requirements
### Requirement: Deterministic PII spans are exposed, not just scrubbed
The system MUST provide a method returning the existing PII regex pattern
set's matches as position-addressable spans (`{start, end, category, text}`)
rather than only scrubbing them in place.

#### Scenario: Text containing a BSN-shaped number is scanned
- **GIVEN** text containing a 9-digit number
- **WHEN** `AiService::detectDeterministicPiiSpans()` is called
- **THEN** the system MUST return a span with `category: 'bsn'` and correct
  `start`/`end` offsets into the input text

### Requirement: The deterministic rules floor is never removable by the LLM layer
The system MUST guarantee that every span detected by the deterministic
rules floor is present, unchanged, in the final merged redaction proposal —
regardless of what an LLM-assisted layer returns, fails to return, or
appears to contradict.

#### Scenario: The LLM-assisted layer returns no spans at all
- **GIVEN** the deterministic rules floor detected 2 spans for a document
- **WHEN** Hermiq's detection call returns an empty spans array
- **THEN** the merged proposal MUST still contain both original rule spans,
  unchanged

#### Scenario: The LLM-assisted layer returns a span overlapping a rule span
- **GIVEN** the deterministic rules floor detected a `bsn` span at `[0,9)`
- **WHEN** Hermiq's detection call returns a different, overlapping span at
  the same general location
- **THEN** the merged proposal MUST still contain the original rule span at
  `[0,9)`, unchanged, alongside the LLM span

### Requirement: LLM-assisted detection is fail-closed
The system MUST NOT let any failure of the LLM-assisted layer (Hermiq
unavailable, misconfigured service account, transport failure, guardrail
block, or malformed model output) block, error, or return a partial result
from a redaction-proposal request — it MUST degrade to a rules-only
proposal with a clear signal (`llmAvailable`, `llmError`).

#### Scenario: Hermiq is unavailable
- **GIVEN** Hermiq is not installed/enabled on this instance
- **WHEN** a redaction proposal is requested for an assessed document
- **THEN** the system MUST return a proposal containing only the
  deterministic rule spans, with `source: 'rules_only'` and
  `llmAvailable: false`

#### Scenario: Hermiq's detection call fails
- **GIVEN** Hermiq is available but the detect-pii call raises a coded
  failure (any HTTP status)
- **WHEN** a redaction proposal is requested
- **THEN** the system MUST return a proposal containing only the
  deterministic rule spans, with `source: 'rules_only_fallback'` and a
  non-empty `llmError`
- **AND** the document's assessment MUST NOT be marked anything other than
  `pending_review`

### Requirement: Redaction proposals require explicit human review before any hand-off
The system MUST NOT hand a redaction proposal's spans to the redaction
execution pipeline (`WOORedactionService::queueForRedaction()`) without an
explicit, authenticated reviewer decision of `approve` on a proposal
currently in `pending_review` status.

#### Scenario: A proposal is generated but never reviewed
- **GIVEN** a `pending_review` redaction proposal exists for a document
- **WHEN** no review decision has been recorded
- **THEN** `WOORedactionService::queueForRedaction()` MUST NOT have been
  called for that document as a result of this feature

#### Scenario: A reviewer approves a proposal
- **GIVEN** a `pending_review` redaction proposal
- **WHEN** an authorized reviewer calls the review endpoint with
  `decision: 'approve'`
- **THEN** the system MUST mark the proposal `approved`, record the
  reviewer and timestamp, and hand the approved spans to
  `WOORedactionService::queueForRedaction()` as guidance metadata — the
  existing redaction pipeline itself is unchanged by this hand-off

#### Scenario: A reviewer rejects a proposal
- **GIVEN** a `pending_review` redaction proposal
- **WHEN** an authorized reviewer calls the review endpoint with
  `decision: 'reject'`
- **THEN** the system MUST mark the proposal `rejected` and MUST NOT call
  `WOORedactionService::queueForRedaction()`

#### Scenario: A decision is attempted on a document with no pending proposal
- **GIVEN** a document with no `pending_review` redaction proposal (never
  requested, or already reviewed)
- **WHEN** a review decision is submitted
- **THEN** the system MUST reject the request and MUST NOT change the
  document's assessment

### Requirement: Every redaction-proposal call is audited
The system MUST record one audit entry (via the existing `AiService` audit
sink) for every `proposeSpans()` call, regardless of outcome, without
recording the raw document text verbatim.

#### Scenario: A redaction proposal is requested
- **GIVEN** any valid `proposeSpans()` call (success, rules-only, or
  fail-closed fallback)
- **WHEN** it completes
- **THEN** the system MUST have recorded exactly one `type: 'anonymisation'`
  audit entry via `AiService::recordAssistantAuditEntry()`
- **AND** the entry's `prompt` field MUST NOT contain the submitted document
  text verbatim

### Requirement: Redaction-proposal endpoints reuse the existing case-mutation authorization
The system MUST gate both the proposal and review endpoints behind the SAME
per-case mutation authorization check every other WOO assessment-mutating
endpoint uses.

#### Scenario: A non-admin, non-group user requests a redaction proposal
- **GIVEN** a user who is not an admin and not a member of
  `procest-gebruikers`
- **WHEN** they call the redaction-proposal endpoint for a case
- **THEN** the system MUST reject the request with a forbidden error and
  MUST NOT call the underlying assist service

### Requirement: Redaction assistance requires an existing document assessment
The system MUST reject a redaction-proposal request for a document that has
not yet received a disclosure-classification assessment.

#### Scenario: A proposal is requested before the document is assessed
- **GIVEN** a document with no wooAssessment record for the given case
- **WHEN** a redaction proposal is requested
- **THEN** the system MUST reject the request and MUST NOT create a
  redaction proposal

