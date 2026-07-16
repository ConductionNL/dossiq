# Design: woo-llm-anonymisation (procest)

## Context

`WOORedactionService` at HEAD is a queue/hand-off orchestrator only — it
never detects PII itself. `AiService` already carries a deterministic
regex-based PII pattern set (`PII_PATTERNS`), used to scrub prompts before
they leave this app, but nothing surfaces those matches as reviewable spans,
and nothing catches free-text categories (names, addresses, medical/
financial mentions) regex cannot. This change adds an LLM-ASSISTED layer —
never a replacement for the deterministic floor, never a silent auto-
redaction — reusing Hermiq's `woo-llm-anonymisation` detect-pii surface
(built first, separately, in hermiq's own repo) exactly the way
`case-assistant-via-hermiq` reuses Hermiq's `converse()` endpoint.

## Decision 1: the rules floor is a FLOOR, not a suggestion

`AiService::detectDeterministicPiiSpans()` exposes the SAME `PII_PATTERNS`
constant `stripPiiIfEnabled()` already uses (bsn, iban, phone, postcode) as
position-addressable spans. `WOOAnonymisationAssistService::buildProposal()`
ALWAYS computes these first, independently of whether Hermiq is available or
what it returns. `mergeSpansRulesFloor()` then unions the LLM's spans on
top: every rule span is copied into the result untouched; an LLM span is
only ever ADDED (and only when it does not exactly duplicate a rule span's
`[start, end, category)` triple — malformed/out-of-range LLM spans are
dropped outright, never trusted). There is no code path — success, partial
failure, empty LLM response, or a maliciously "corrective" LLM response —
that can remove or shrink a rule-detected span from the merged result. This
is asserted directly by `testRulesFloorInvariantSurvivesAdversarialLlmSpans()`
in `WOOAnonymisationAssistServiceTest`.

## Decision 2: fail-closed, always

`buildProposal()` wraps the Hermiq call in try/catch: `HermiqAssistantException`
(covers Hermiq unavailable, service-account misconfiguration, transport
failure, guardrail block, and malformed-model-output — Hermiq's
`detect-pii` maps ALL of these to a coded `HermiqAssistantException` via
`HermiqAnonymisationClient::decodeResponse()`) degrades to
`source: 'rules_only_fallback'` with the caught message surfaced as
`llmError` — the call NEVER throws past this boundary, NEVER returns a
partial/guessed result, and the returned proposal's `status` is ALWAYS
`pending_review`, never anything that could be mistaken for
"anonymised"/"approved". `reviewProposal()` is the ONLY place `status` can
become `approved`, and that requires an explicit human `decision: 'approve'`
call — there is no code path from `proposeSpans()` directly to "approved" or
to `WOORedactionService::queueForRedaction()`.

## Decision 3: human-in-the-loop is structural, not a UI convention

`reviewProposal()` requires an existing `redactionProposal` with
`status === 'pending_review'` (throws otherwise) — a proposal that was never
generated, or was already reviewed, cannot be re-approved by replaying a
request. Approval REQUIRES a `reviewerId` (the authenticated caller, matching
`requireCaseMutationAccess()`'s existing per-case authorization) and is
recorded (`reviewedBy`, `reviewedAt`) on the SAME record the proposal lives
on — one document, one attempt, one auditable reviewer. Only `approve`
reaches `WOORedactionService::queueForRedaction()`; `reject` discards the
proposal and the pre-existing manual/Docudesk fallback proceeds exactly as
it always has, completely unaffected by this feature's presence or absence.

## Decision 4: authorization boundary — no new document-read path

Neither `proposeSpans()` nor the controller reads document content itself.
`text` is a caller-supplied request parameter, gated behind the SAME
`requireCaseMutationAccess()` guard `bulkAssess()`/`extendDeadline()`/
`createDecision()` already enforce (admin, or a member of
`procest-gebruikers`). This mirrors `CaseAssistantService`'s Decision 1
(fail-closed to the caller's own authorization boundary rather than building
a NEW document-content-read path) — text extraction/rendering is
out-of-scope for this change; the UI dialog's textarea is pre-filled from
whatever text the case worker already has on screen (the document's
existing preview/extracted-text surface), never fetched server-side on the
caller's behalf.

## Decision 5: audit — every proposal call, not just approvals

`proposeSpans()` records ONE `AiService::recordAssistantAuditEntry()` call
per invocation — `type: 'anonymisation'`, `action: 'proposal'` — regardless
of whether the LLM layer succeeded, failed, or was unavailable, so the
oversight trail (`/api/ai/audit`, `AiAuditExportController`) captures every
attempt, not just successful ones. The raw document text is NEVER recorded
verbatim (`prompt` carries only a character count) — the existing audit
sink already stores `prompt` fields for other AI operations, and repeating
full document text (exactly the PII this feature exists to protect) into a
second, longer-retained store would be a regression, not an improvement in
oversight.

## Decision 6: byte vs. character offsets — a documented, bounded gap

`detectDeterministicPiiSpans()` uses `preg_match_all(..., PREG_OFFSET_CAPTURE)`,
which reports BYTE offsets; Hermiq's `detectPii()` instructs the model to
report CHARACTER offsets. For pure-ASCII spans (BSN, phone, postcode, IBAN —
everything the regex floor catches) these are identical. For multi-byte
text (Dutch diacritics near an LLM-detected `person`/`address` span) the two
coordinate systems can drift by a few positions. This is a documented,
bounded UI-highlighting precision issue, NOT a correctness/security defect:
the rules-floor invariant (Decision 1) does not depend on coordinate
alignment between the two sources — every rule span is preserved by
identity, not by cross-referencing LLM offsets. Fixing this precisely
(switching the regex path to `mb_*`-based scanning) is deferred as
follow-up work; flagged here rather than silently shipped unremarked.

## Risks / Trade-offs

- The UI dialog's textarea is a manual/pre-filled text surface, not a live
  document-content extraction pipeline — acceptable for this change's scope
  (LLM-assist gating + human review + audit + rules floor), documented as a
  deliberate boundary (Decision 4), not a hidden gap.
- `WOORedactionService::queueForRedaction()`'s Docudesk branch is itself
  still a "hook point" (no real Docudesk API call exists yet) — this
  feature's hand-off carries `redactionProposal` metadata into that SAME
  hook, unchanged; it neither fixes nor worsens that pre-existing gap.

## Migration Plan

None — additive only. Requires the SAME `hermiq_service_uid`/
`hermiq_service_app_password` app-config service account
`case-assistant-via-hermiq` already documents (shared, not duplicated).
