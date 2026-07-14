# Proposal: case-assistant-via-hermiq

## Why

Every major competitor in the case-management space ships an in-product
conversational AI assistant (Decos Joni, PinkRoccade AiConnect, Centric
Mynte); sovereign, explainable AI is the researched 2025-2026 battleground.
Procest already has `AiService` for discrete AI operations (classify /
extract / ask / summarize / suggest) with full audit logging, but no
conversational assistance on the case detail page.

FLEET RULE: AI functionality lives in HERMIQ — procest must NOT grow its own
assistant/LLM logic. Hermiq now ships the matching surface: `POST
/api/assistant/converse` (hermiq case-assistant-surface, hermiq PR #67,
merged), a tool-free, guardrail-filtered, session-persisting conversational
endpoint purpose-built for leaf apps. Procest becomes a THIN consumer: it
enriches a message with the case context the current user is already
authorized to read, forwards it, and renders the reply.

## What Changes

- Add `lib/Service/Assistant/HermiqAssistantClient.php`: the single thin
  HTTP boundary to Hermiq's local converse endpoint via
  `OCP\Http\Client\IClientService` (LibresignApiClient pattern), feature-
  gated on `IAppManager::isEnabledForUser('hermiq')`, service-account Basic
  Auth from app config, `http_errors: false` so Hermiq's specific error
  mapping (400/403/404/422 `guardrail_blocked`/503) is relayed, plus
  `HermiqAssistantException` carrying status + stable errorCode.
- Add `lib/Service/Assistant/CaseAssistantService.php`: loads the case via
  the standard OpenRegister read path (same authorization scoping as every
  other procest service — OR unavailable / unknown / unreadable all fail
  CLOSED to the same 404, hermiq#57 lesson), builds a bounded summary of
  ONLY the fields already shown on the CaseDetail page's own widgets (never
  documents/contacts/initiator PII; description truncated), persists the
  Hermiq session per (user, case) via `IConfig` user values, and records
  every exchange through the EXISTING `AiService` audit sink
  (`recordAssistantAuditEntry` forwarder → `recordAuditEntry`).
- Add `lib/Controller/AssistantController.php` (`@NoAdminRequired`):
  `GET /api/assistant/availability` (UI gate) + `POST /api/assistant/converse`.
- UI: `src/views/cases/components/CaseAssistantPanel.vue` chat panel
  (message list, textarea composer, loading state, NC CSS vars only),
  MOUNTED on the manifest CaseDetail page as a `custom` widget resolved via
  page slots (`widget-case-assistant` → `CaseAssistantPanel` in
  `src/registry.js`) — the InitiatorSection pattern. Availability-gated:
  renders NOTHING when Hermiq is absent/disabled.
- `src/services/assistantApi.js` (axios wrapper) +
  `src/utils/assistantHelpers.js` (pure, vitest-tested helpers: send guard,
  transcript factory, errorCode/status → message mapping).
- l10n: en+nl pairs for all new strings (ENGLISH keys).

## Impact

- Affected specs: new `case-assistant-via-hermiq` capability.
- Affected code: `lib/Service/Assistant/*`, `lib/Controller/AssistantController.php`,
  `lib/Service/AiService.php` (public audit forwarder only),
  `appinfo/routes.php`, `src/manifest.json` (CaseDetail widget+layout+slot),
  `src/registry.js`, `src/views/cases/components/CaseAssistantPanel.vue`,
  `src/services/assistantApi.js`, `src/utils/assistantHelpers.js`,
  `l10n/{en,nl}.{js,json}`, tests.
- NO new composer/npm deps. NO LLM/prompt logic in procest.
- The pre-existing `AiAssistantPanel.vue` (discrete ask/suggest/summarize
  operations via procest's own AiService) is untouched — different surface,
  different backend, both audited to the same oversight trail.
