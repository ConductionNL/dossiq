---
kind: feature
---

# AI oversight log: real audit querying, export, and an oversight surface

## Why

The 2026-07 market research is unambiguous: sovereign, *explainable* AI is the 2025-2026 zaaksysteem battleground (PinkRoccade AiConnect: "Veilig. Uitlegbaar. Beheersbaar."), the EU AI Act binds transparency obligations from 2 Aug 2026 and Article 14 demands human oversight with log retention, and Dutch municipalities register impactful algorithms in the Algoritmeregister. Procest already records AI activity properly — `AiService::recordAuditEntry()` writes `aiAuditEntry` objects (type, model, prompt, suggestion, confidence, userAction, actualValue, reason, userId) to OpenRegister, and `ai#recordAction` captures the human decision — but the oversight side is hollow:

- `AiController::auditIndex()` is a **stub**: it echoes the filters back with "Audit trail query — implement with OpenRegister object listing" and never queries anything.
- There is no export for accountability reporting (Algoritmeregister evidence, internal audit, FRIA support).
- There is no UI where a functioneel beheerder or auditor can inspect AI activity.

## What changes

- **Implement `auditIndex()` for real**: query the `aiAuditEntry` objects from OpenRegister (register + `ai_audit_entry_schema` config, same resolution as `recordAuditEntry`) with `caseId`/`type` filters and limit/offset paging, newest first. Move the query into `AiService` (e.g. `listAuditEntries()`) so the controller stays thin.
- **New export endpoint** `GET /api/ai/audit/export` (CSV; `format=json` optional) on a new `AiAuditExportController`, RBAC-gated exactly like `ParaferingAuditExportController` (`auditors`/`secretariaat`/`beheerders`/`admin` groups or NC admin).
- **Oversight surface**: manifest `index` page "AI oversight" at `/settings/ai-oversight` over `(procest, aiAuditEntry)` with columns type/action/model/userAction/userId (+ a detail page for the full prompt/suggestion payload), following the Parafeerroutes index-page pattern — config-only UI.
- **Suggestion-time logging completeness**: verify each AI operation (classify, extract, ask, summarize, suggestRouting, suggestNext) records an audit entry at suggestion time; add the call where missing so the log is complete (Art. 14 log-retention posture).
- Tests: PHPUnit for the real `auditIndex` (mocked ObjectService), the export controller (RBAC allow/deny, CSV shape), and logging-completeness; manifest validation for the new pages.

## Impact

Backend: one stub replaced by a real implementation (no route changes for it), one new controller + route, possible small additions inside AiService operations (logging calls). Frontend: manifest-only. The audit log may contain prompt/suggestion content derived from case data — the oversight page lives under settings and the export is group-gated; the index endpoint keeps its existing authenticated posture while the raw listing through OR inherits OR RBAC on the schema.

## Capabilities

### New Capabilities
- `ai-oversight-log` — every AI suggestion and the human decision on it is queryable, exportable (RBAC-gated CSV), and inspectable in an oversight UI, supporting EU AI Act Art. 14 human-oversight and Algoritmeregister accountability.
