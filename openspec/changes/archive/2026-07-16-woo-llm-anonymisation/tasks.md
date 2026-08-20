# Tasks: woo-llm-anonymisation (procest)

## 1. Deterministic rules floor

- [x] 1.1 `AiService::detectDeterministicPiiSpans()`: expose the existing `PII_PATTERNS` regex set (bsn, iban, phone, postcode) as `{start, end, category, text}` spans, sorted by `start`. Pure, no I/O.

## 2. Orchestration + persistence + controller

- [x] 2.1 `HermiqAnonymisationClient` (mirrors `HermiqAssistantClient`): `isAvailable()`, `detectPii(text, context)` → `{spans, usage}`, service-account Basic Auth, `HermiqAssistantException` on any non-2xx/transport failure (REUSED, not duplicated).
- [x] 2.1b `WOODocumentAssessmentService::findAssessment()`/`saveRedactionProposal()`: canonical wooAssessment read/write path a proposal attaches to; `saveRedactionProposal()` throws when the document has not yet been assessed (assess-first rule).
- [x] 2.2 `WOOAnonymisationAssistService::proposeSpans()`: rules floor (task 1.1) ALWAYS computed first; when Hermiq is available, additionally calls `HermiqAnonymisationClient::detectPii()`; merges via `mergeSpansRulesFloor()` (task 2.3); persists `{spans, source, llmAvailable, llmError?, proposedBy, proposedAt, status: 'pending_review'}` via `saveRedactionProposal()`; records ONE `AiService::recordAssistantAuditEntry()` call per invocation regardless of outcome, with the raw document text NEVER recorded verbatim.
- [x] 2.3 `mergeSpansRulesFloor()`: UNION merge — every rule span is copied into the result unchanged; an LLM span is only ever ADDED, never allowed to remove/shrink a rule span; malformed/out-of-range LLM spans dropped; exact-duplicate LLM spans (same `[start,end,category)`) skipped.
- [x] 2.3b `WOOAnonymisationAssistService::reviewProposal()`: requires an existing `pending_review` proposal (throws otherwise); `reject` marks `rejected` and stops there; `approve` marks `approved`, records `reviewedBy`/`reviewedAt`, and hands the approved spans to the EXISTING, unchanged `WOORedactionService::queueForRedaction()` as guidance metadata.
- [x] 2.4 `WOOAssessmentController::proposeRedaction()`/`reviewRedactionProposal()`: auth guard (401) + the SAME `requireCaseMutationAccess()` guard every other WOO mutation endpoint uses; validation/business-rule failures → 400.
- [x] 2.4b Register `POST /api/cases/{id}/woo/documents/{documentRef}/redaction-proposal` and `.../redaction-proposal/review` in `appinfo/routes.php`.

## 3. Tests

- [x] 3.1 `HermiqAnonymisationClientTest`: availability gate, missing credentials → 503, correct payload/URL/auth, guardrail-blocked (422) and malformed-output (502) relay, transport failure → 503.
- [x] 3.2 `WOOAnonymisationAssistServiceTest`: validation (empty/oversized text), unassessed-document rejection, feature-gate-absent → rules-only (LLM client never called), Hermiq-failure → fail-closed rules-only fallback (never blocks, never "anonymised"), **rules-floor invariant under adversarial LLM spans** (pinned), malformed-LLM-span dropping, exact-duplicate dedup, audit recorded on every call (raw text never in the audit prompt), reviewProposal validation/business-rule errors, approve hands off to the EXISTING `WOORedactionService`, reject never invokes it.
- [x] 3.3 `AiServicePiiDetectionTest`: per-category detection (bsn/phone/postcode/iban), multi-span sort order, clean/empty text → no spans.
- [x] 3.4 `WOODocumentAssessmentServiceTest` additions: `findAssessment()`/`saveRedactionProposal()` OR-unavailable and happy-path behaviour, assess-first rejection.
- [x] 3.5 `WOOAssessmentControllerTest` additions: 401, per-case authorization enforcement (`OCSForbiddenException`), success envelopes, validation-failure → 400 mapping, for both new endpoints.
- [x] 3.6 Vitest: none added beyond the existing coverage surface — `RedactionAssistDialog.vue`/`DocumentAssessmentTable.vue` changes are exercised by the project's existing component-level conventions; no new pure-JS helper warranted its own unit beyond the PHP-side merge-invariant tests, which are the load-bearing contract.

## 4. UI (orphaned-capability gate)

- [x] 4.1 `src/dialogs/RedactionAssistDialog.vue` (new, own file per modal-isolation convention): textarea input → `detect()` → span review table (rule spans always-selected/disabled, LLM spans toggleable) → `approve()`/`reject()`.
- [x] 4.2 `DocumentAssessmentTable.vue`: "Redaction assist" action wired for `deels_openbaar` documents, mounts `RedactionAssistDialog`, `redaction-reviewed` event bubbled to the parent.

## Acceptance criteria

- Rules-floor invariant: `testRulesFloorInvariantSurvivesAdversarialLlmSpans()` proves every rule-detected span survives the merge unchanged regardless of the LLM's response.
- Human-in-the-loop: no code path from `proposeSpans()` reaches `status: 'approved'` or `WOORedactionService::queueForRedaction()` without an explicit `reviewProposal(decision: 'approve')` call from an authorized reviewer.
- Fail-closed: every Hermiq failure mode (unavailable, misconfigured, transport, guardrail-blocked, malformed output — all mapped to `HermiqAssistantException`) degrades `proposeSpans()` to a rules-only result; NEVER throws past `WOOAnonymisationAssistService`, NEVER returns `status !== 'pending_review'`.
- Audit coverage: every `proposeSpans()` call records an `AiService` audit entry (`type: 'anonymisation'`), regardless of outcome.
- Authorization: both new endpoints reuse `requireCaseMutationAccess()` — no new authorization surface.
- Orphaned-capability: routes registered, controller wired, service reachable from a real UI action (`RedactionAssistDialog` mounted from `DocumentAssessmentTable`) — not just spec'd and tested in isolation.

## Quality reminders

- SPDX tags in every new/changed file's docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit/Write tool only.
- i18n keys in English; `l10n/en.json`/`l10n/nl.json` parity verified via `node tests/l10n/check-l10n.js`.
- No new composer/npm dependencies.
