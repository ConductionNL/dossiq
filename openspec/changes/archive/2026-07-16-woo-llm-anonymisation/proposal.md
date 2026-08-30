---
kind: code
---

# Proposal: woo-llm-anonymisation

## Why

Six Dutch municipalities (led by Gemeente Hoeksche Waard, Computable Award
2025) now ship "Anonimiseren met LLM" / "Anonimiseren bij de bron" directly
into their case-management stack, and xxllnc acquired DataMask specifically
for AI-assisted document anonymisation feeding Woo publication. At HEAD,
procest's `WOORedactionService` does not detect anything itself — it only
orchestrates a hand-off to Docudesk (a "hook point" awaiting Docudesk's own
service interface) or a manual "upload a redacted version" fallback.
`AiService` already carries a deterministic regex-based PII pattern set
(`PII_PATTERNS`: bsn, iban, phone, postcode) used to scrub outbound prompts,
but nothing surfaces those matches for review, and nothing catches the
categories regex cannot — names, addresses, free-text medical/financial
mentions — which is exactly where an LLM-assisted pass adds value.

Per the fleet rule (AI functionality lives in Hermiq, never grown locally in
leaf apps) the LLM-assisted detection itself was built in Hermiq first (see
hermiq's `woo-llm-anonymisation` change, merged separately,
`POST /api/assistant/detect-pii`). This proposal is the thin procest-side
consumer: an ASSIST layered on top of the existing `WOORedactionService`,
never a replacement, always human-reviewed before anything downstream
happens.

## What Changes

- `AiService::detectDeterministicPiiSpans()`: a new public method exposing
  the SAME `PII_PATTERNS` regex set as position-addressable spans instead of
  scrubbed text — the deterministic "rules floor" this feature is built
  around.
- `HermiqAnonymisationClient`: a new thin HTTP client (mirrors
  `HermiqAssistantClient` exactly) calling Hermiq's
  `POST /api/assistant/detect-pii`.
- `WOOAnonymisationAssistService`: orchestrates — runs the rules floor,
  optionally asks Hermiq for additional spans, merges by UNION (rules floor
  spans are ALWAYS present in the result, unchanged, regardless of what the
  LLM returned — asserted by a pinned unit test), persists the proposal on
  the document's existing wooAssessment record as `pending_review`, and only
  on explicit human `approve` hands the reviewed spans to the EXISTING,
  UNCHANGED `WOORedactionService::queueForRedaction()` as guidance metadata.
  FAIL-CLOSED: any Hermiq error/timeout/guardrail-block degrades to a
  rules-only proposal with a clear signal — never blocks, never silently
  treated as "anonymised".
- `WOODocumentAssessmentService`: two new methods
  (`findAssessment()`/`saveRedactionProposal()`) — the canonical
  wooAssessment read/write path this feature reuses rather than duplicating.
- `WOOAssessmentController`: two new endpoints,
  `POST /api/cases/{id}/woo/documents/{documentRef}/redaction-proposal` and
  `.../redaction-proposal/review`, gated by the SAME
  `requireCaseMutationAccess()` guard every other WOO mutation endpoint uses.
- UI: `RedactionAssistDialog.vue` (new, `src/dialogs/`) + a wired action in
  `DocumentAssessmentTable.vue` for `deels_openbaar` documents — genuinely
  invocable end-to-end, not an orphaned capability.
- Feature-gated on Hermiq availability (`IAppManager`) — absent → existing
  rule-based-only behaviour, unchanged, panel still functions.

## Impact

- Affected specs: new `woo-llm-anonymisation` capability (procest side).
- Affected code: `lib/Service/AiService.php`,
  `lib/Service/Assistant/HermiqAnonymisationClient.php`,
  `lib/Service/WOOAnonymisationAssistService.php`,
  `lib/Service/WOODocumentAssessmentService.php`,
  `lib/Controller/WOOAssessmentController.php`, `appinfo/routes.php`,
  `src/dialogs/RedactionAssistDialog.vue`,
  `src/views/cases/components/DocumentAssessmentTable.vue`.
- NOT in scope: the redaction execution itself (blacking out a PDF/DOCX) —
  `WOORedactionService`'s Docudesk hand-off / manual fallback is unchanged;
  this feature only informs it with a human-reviewed proposal.
- NOT in scope: document text extraction — the dialog accepts caller-visible
  text via the existing document read path the case worker already has
  access to (see design.md for the exact boundary).
