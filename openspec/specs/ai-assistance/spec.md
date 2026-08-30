---
status: done
retrofit: true
---

# AI Assistance Specification

## Purpose

@e2e exclude AI assistance is a V1 backend feature; no dedicated Playwright-testable UI surface in the current build.

Provide a human-in-the-loop AI surface for casehandlers: classify documents, extract structured data, answer knowledge-base questions in case context, summarize cases / documents / timelines, suggest routing and next steps, record every user response to every AI suggestion in an Algoritmeregister-compliant audit trail, and operate behind global + per-feature enablement flags so deployments can roll out one capability at a time. PII is stripped before content leaves the dossiq process. (The dossiq-side MCP tool provider for the AI orchestrator is specified separately under `mcp-integration`.)

## Requirements

### REQ-001: AI REST surface for classify / extract / ask / summarize / suggestRouting / suggestNext

The system SHALL expose six `@NoAdminRequired` JSON endpoints on `AiController` — `classify`, `extract`, `ask`, `summarize`, `suggestRouting`, `suggestNext` — that validate required parameters, resolve the calling user (or `'anonymous'`), delegate to `AiService`, and return the service result as JSON.

#### Scenario: Required parameters

- WHEN `classify` is called without `caseId` or `documentId`, OR `ask` without `caseId` or `question`, OR `summarize` / `suggestRouting` / `suggestNext` without `caseId`
- THEN the controller SHALL return HTTP 400 `{error: '<param> [and <param>] are required'}`

#### Scenario: Summarize type whitelist

- WHEN `summarize` is called with `type` outside `['case', 'document', 'timeline']`
- THEN the controller SHALL return HTTP 400 `{error: 'type must be one of: case, document, timeline'}` (default `type` is `'case'`)

#### Scenario: Anonymous fallback

- WHEN no session user is present
- THEN `getCurrentUserId()` SHALL return `'anonymous'` and downstream service calls SHALL still proceed

### REQ-002: Human-in-the-loop action recording and audit-trail listing

The system SHALL expose `recordAction` (record what the user did with a given AI suggestion: accepted / rejected / overridden / etc.) and `auditIndex` (list AI audit entries filterable by `caseId`, `type`, `limit`, `offset`) endpoints — these underpin Algoritmeregister-style traceability for every AI suggestion.

#### Scenario: recordAction required fields

- WHEN `recordAction` is called without `caseId`, `type`, or `userAction`
- THEN the controller SHALL return HTTP 400 `{error: 'caseId, type, and userAction are required'}`

#### Scenario: recordAction payload

- WHEN `recordAction` is called with all required fields plus optional `suggestion`, `actualValue`, `reason`
- THEN the controller SHALL forward all six to `AiService::recordUserAction(caseId, type, userAction, suggestion, actualValue, reason, userId)`

#### Scenario: auditIndex query

- WHEN `auditIndex` is called
- THEN the controller SHALL build `{caseId, type, limit (default 50), offset (default 0)}` and return `{success: true, filters: array_filter($filters), message: ...}`

#### Notes

- `auditIndex` currently returns a placeholder message `'Audit trail query — implement with OpenRegister object listing'` — the real OR listing is observed-but-stubbed at the controller layer. Audit-record persistence inside `AiService::recordUserAction` is the canonical write path; reads need to be wired to OR before production rollout.

### REQ-003: Global + per-feature AI enablement flags

The system SHALL gate every AI call behind a global `ai_enabled` IAppConfig flag and a per-feature `ai_feature_<name>` flag, where `<name>` is one of `classification`, `extraction`, `qa`, `summary`, `routing`, `decision_support`. Both flags hold the string `'1'` for enabled.

#### Scenario: Global off short-circuits everything

- WHEN `isEnabled()` returns `false`
- THEN `isFeatureEnabled(<any>)` SHALL also return `false` without inspecting the per-feature flag

#### Scenario: Per-feature toggle

- WHEN `isEnabled()` returns `true` but `ai_feature_<name>` is not `'1'`
- THEN `isFeatureEnabled(<name>)` SHALL return `false`

#### Notes

- The flag-format is string-typed `'1'` not boolean — caller code must match this convention.

### REQ-004: Dutch PII stripping before model dispatch

The system SHALL strip four Dutch PII patterns from any content sent to an AI model: BSN (9-digit number), IBAN (NL/EU layout), Dutch phone numbers (`0xxxxxxxxx` or `+31xxxxxxxxx`), and postcode (`9999 AA`).

#### Scenario: PII pattern set

- WHEN content is prepared for the AI model
- THEN every match of the four regexes in `PII_PATTERNS` SHALL be replaced with a redacted placeholder before transmission
- AND the originals SHALL not be transmitted to the model

#### Notes

- The patterns are deliberately broad (e.g. any 9-digit number triggers `bsn`); the safe default prefers redaction false-positives over leaking real PII. Tightening must keep the redaction-first bias.

### REQ-005: Settings management and AI-model health check

The system SHALL expose `getSettings`, `updateSettings`, and `healthCheck` JSON endpoints that surface the current AI configuration, accept settings updates via `SettingsService::updateSettings`, and report on AI-model connectivity / latency via `AiService::testHealth`.

#### Scenario: getSettings

- WHEN `getSettings` is called
- THEN the controller SHALL return `AiService::getAiSettings()` verbatim

#### Scenario: updateSettings

- WHEN `updateSettings` is called with a request body
- THEN the controller SHALL pass `$this->request->getParams()` to `SettingsService::updateSettings($data)` and return its result

#### Scenario: healthCheck

- WHEN `healthCheck` is called
- THEN the controller SHALL return `AiService::testHealth()` verbatim — this is the operator-facing probe for the n8n MCP backend

#### Notes

- `getSettings`, `updateSettings`, and `healthCheck` do NOT carry `@NoAdminRequired` and are intended for admin use. Routes wiring SHOULD enforce that (current routes file is the source of truth for admin-gating).
