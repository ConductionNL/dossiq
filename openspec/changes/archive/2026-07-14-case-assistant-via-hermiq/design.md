# Design: case-assistant-via-hermiq

## Context

Hermiq's `case-assistant-surface` (PR #67, merged 2026-07-14) provides
`POST /api/assistant/converse` — `{sessionId?, message, context: {app,
objectType, objectRef, contextData}}` → `{sessionId, reply, usage}` — a
tool-free-by-construction, guardrail-filtered conversational endpoint whose
grounding is EXCLUSIVELY the caller-supplied `contextData`. That contract
makes the caller responsible for one thing above all: **never send data the
requesting user cannot already read**.

## Goals / Non-Goals

- Goal: conversational case assistance on the CaseDetail page with zero
  procest-side LLM/prompt logic (fleet rule: AI lives in Hermiq).
- Goal: fail-closed context enrichment — the assistant can only ever be as
  informed as the requesting user.
- Goal: the existing AI oversight trail (`AiService` audit entries,
  `/api/ai/audit`, `AiAuditExportController`) covers this surface too.
- Non-goal: document contents, contacts, initiator PII in the context.
- Non-goal: replacing the pre-existing discrete-operation `AiAssistantPanel`.

## Decisions

### Decision 1: fail-closed case loading — one indistinguishable 404

`CaseAssistantService::loadReadableCase()` uses the standard
`SettingsService::getObjectService()` + `find(id, register, schema)` read
path every other procest service uses, so OpenRegister's own multitenancy /
RBAC scoping applies unchanged. Three distinct failures — OR not installed,
case does not exist, case not readable by this user — all map to the SAME
404 with the same message, so the endpoint cannot be used to probe for the
existence of cases the caller cannot see (mirrors Hermiq's own 404-not-403
IDOR convention, and the hermiq#57 lesson that a check which defaults open
on "service unavailable" is fail-open: OR being unavailable here yields
REFUSAL, not a context-less answer).

### Decision 2: bounded, widget-parity context — nothing the page doesn't already show

`buildCaseSummary()` whitelists exactly the fields the CaseDetail page's own
"Core case data" / "Process" widgets render (title, identifier, truncated
description, caseType, status, confidentiality, startDate, deadline,
isFinalStatus). Whitelist, not blacklist: a future schema field is EXCLUDED
by default. Description is `mb_substr`-truncated to 500 chars. Documents,
contacts, and initiator fields are deliberately absent — the panel's empty
state tells the user answers are based only on case data they can already
see.

### Decision 3: session continuity via IConfig user values, keyed per (user, case)

Hermiq owns the conversation (Conversation/Message objects, org-scoped,
ownership-guarded server-side). Procest only remembers WHICH Hermiq session
belongs to (user, case): `IConfig::setUserValue('procest',
'assistant_session_{caseId}', sessionId)`. Per-user by construction — one
user can never resume another's session through this surface, and Hermiq
independently 403s a foreign sessionId (defense in depth). No new OR schema.

### Decision 4: service-account auth to Hermiq

Hermiq's converse endpoint requires an authenticated NC session. Server-to-
server local HTTP does not inherit the browser session, so the client uses a
configured service account (`hermiq_service_uid` /
`hermiq_service_app_password` app-config, Basic Auth) — the exact
LibresignApiClient precedent. Unconfigured credentials → 503 with a clear
message (panel shows "currently unavailable"), never a half-authenticated
call. The per-user authorization boundary REMAINS procest-side (Decision 1)
+ per-user session keys (Decision 3): the service account only relays
already-authorized context. Trade-off documented: Hermiq-side conversations
are owned by the service account, which is acceptable because procest never
exposes Hermiq's own conversation-listing UI, only its own per-(user,case)
session pointers.

### Decision 5: availability gate hides, never breaks

`GET /api/assistant/availability` returns
`IAppManager::isEnabledForUser('hermiq')`. The panel probes it on mount and
renders NOTHING when false (or when the probe itself fails — fail closed to
hidden). Follows the LibresignSigningAdapter absent-peer-app convention:
absence is a clean degradation, not an error state.

### Decision 6: audit through the existing sink

Every exchange is recorded via `AiService::recordAssistantAuditEntry()` — a
thin public forwarder onto the existing private `recordAuditEntry()` writer
(same register/schema, same shape: `type: 'assistant'`, `action:
'conversation'`, caseId, model `hermiq`, prompt, reply, userId, timestamp,
responseTimeMs). The oversight page and the audit export pick these up with
zero changes.

## Risks / Trade-offs

- Hermiq's endpoint contract could drift; every route/payload/field name is
  isolated to `HermiqAssistantClient` (one-file correction, the
  LibresignApiClient discipline).
- The manifest CaseDetail page is edited concurrently by other builds;
  the widget/layout/slot additions are three small, append-shaped edits to
  minimise merge conflict surface.

## Migration Plan

None — additive only. Admin setup: create a service account, generate an
app password, set `hermiq_service_uid` + `hermiq_service_app_password` via
`occ config:app:set`.
