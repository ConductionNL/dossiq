# Tasks: case-assistant-via-hermiq

## 1. Hermiq client (thin HTTP boundary)

- [x] 1.1 Create `lib/Service/Assistant/HermiqAssistantClient.php` (SPDX docblock): `isAvailable()` via `IAppManager::isEnabledForUser('hermiq')`; `converse(?sessionId, message, context)` POSTing to `/index.php/apps/hermiq/api/assistant/converse` with service-account Basic Auth (`hermiq_service_uid`/`hermiq_service_app_password` app config) and `http_errors: false`.
- [x] 1.2 Create `lib/Service/Assistant/HermiqAssistantException.php` carrying the HTTP status + Hermiq's stable `errorCode` (e.g. `guardrail_blocked`) so callers never match on message text. Disabled Hermiq / missing credentials / transport failure → 503 without any HTTP call where applicable.

## 2. Case-context enrichment (fail closed)

- [x] 2.1 Create `lib/Service/Assistant/CaseAssistantService.php`: validate message (non-empty, ≤ 4000 chars → 400); load the case via `SettingsService::getObjectService()` + `find()` — OR unavailable, unknown case, and unreadable case ALL fail closed to the same 404, never calling Hermiq (design.md Decision 1).
- [x] 2.2 `buildCaseSummary()`: whitelist ONLY CaseDetail-widget fields (title, identifier, mb-truncated description, caseType, status, confidentiality, startDate, deadline, isFinalStatus) — never documents/contacts/initiator PII (Decision 2).
- [x] 2.3 Session continuity: per-(user, case) Hermiq session UUID in `IConfig` user values (Decision 3).
- [x] 2.4 Record every exchange via the existing `AiService` audit sink — add the thin public `recordAssistantAuditEntry()` forwarder onto the existing private `recordAuditEntry()` writer (Decision 6).

## 3. Controller + routes

- [x] 3.1 Create `lib/Controller/AssistantController.php` (`@NoAdminRequired`): `availability()` (UI gate) + `converse()` mapping coded failures to 400/401/403/404/422 (+`errorCode`)/503 with translated messages.
- [x] 3.2 Register `GET /api/assistant/availability` + `POST /api/assistant/converse` in `appinfo/routes.php`.

## 4. UI (panel MOUNTED on the case detail surface)

- [x] 4.1 Create `src/views/cases/components/CaseAssistantPanel.vue`: transcript list (user/assistant bubbles), NcTextField composer + NcButton send, NcLoadingIcon loading state, `role="alert"` error line, NC CSS vars only. Availability-gated on mount — renders NOTHING when Hermiq is absent (Decision 5).
- [x] 4.2 Create `src/services/assistantApi.js` (availability probe fails closed to hidden; converse POST) + `src/utils/assistantHelpers.js` (pure: `canSend` mirroring the backend 4000-char cap, `makeTranscriptEntry`, `assistantErrorMessage` keyed off errorCode/status).
- [x] 4.3 Mount it for real (orphaned-capability rule): `CaseAssistantPanel` registry entry (kind `widget`) in `src/registry.js`; `case-assistant` custom widget + layout cell on the manifest CaseDetail page; `"widget-case-assistant": "CaseAssistantPanel"` in the page's `slots` (InitiatorSection pattern).
- [x] 4.4 l10n: all new strings in `l10n/en.{js,json}` + Dutch pairs in `l10n/nl.{js,json}` (ENGLISH keys); `node tests/l10n/check-l10n.js` green.

## 5. Verify

- [x] 5.1 PHPUnit (CI way, php:8.3-cli + composer install): `HermiqAssistantClientTest` (availability gate, no-HTTP-when-disabled/unconfigured, payload/auth shape, sessionId omission, 422 errorCode relay, transport 503), `CaseAssistantServiceTest` (400s, three-way fail-closed 404, summary whitelist incl. PII exclusion, session continuity, audit recording, mb truncation), `AssistantControllerTest` (401/400/404/422+errorCode/503/success).
- [x] 5.2 vitest: `tests/vitest/assistantHelpers.spec.js` (send guard incl. length cap, transcript factory, error mapping keyed off errorCode/status).
- [x] 5.3 `node tests/validate-manifest.js` PASS; full PHPUnit suite green; `npm run build` green; phpcs/phpmd/psalm/phpstan clean on new files.

## Acceptance criteria

- The chat panel renders on the CaseDetail page when Hermiq is installed+enabled, and renders NOTHING (no error chrome) when it is not.
- A conversational turn round-trips procest → Hermiq → procest within one request; follow-ups keep continuity via the per-(user, case) session.
- The context sent to Hermiq NEVER contains fields beyond the CaseDetail-widget whitelist; a case the user cannot read yields 404 before any Hermiq call.
- Every exchange appears in the existing AI oversight trail (`/api/ai/audit`).
- A Hermiq guardrail block surfaces as a distinct, translated message keyed off `errorCode: guardrail_blocked`.

## Quality reminders

- SPDX docblocks (EUPL-1.2, 2026 Conduction B.V.); `@spec` tags on every new method.
- No sed/awk/scripts on code; Edit/Write only. No new composer/npm deps.
- No LLM/prompt logic in procest — Hermiq owns the conversation (fleet rule).
