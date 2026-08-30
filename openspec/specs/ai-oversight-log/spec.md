# ai-oversight-log Specification

## Purpose
TBD - created by archiving change ai-oversight-log. Update Purpose after archive.
## Requirements
### Requirement: Audit entries are queryable

`GET /api/ai/audit` SHALL return the recorded `aiAuditEntry` objects from OpenRegister — filterable by `caseId` and `type`, paged via `limit` (default 50, max 200) and `offset`, newest first. The stub response SHALL be gone.

#### Scenario: Filtered audit listing

- **GIVEN** recorded audit entries for two cases
- **WHEN** a behandelaar requests `GET /api/ai/audit?caseId=<case-a>`
- **THEN** only case A's entries return, newest first, with paging metadata

@e2e exclude Covered by PHPUnit with mocked ObjectService; no stable UI fixture for AI entries in e2e env.

#### Scenario: Stub removed

- **WHEN** the audit endpoint is called
- **THEN** the response contains real entries (or an empty list) — never the placeholder message "implement with OpenRegister object listing"

@e2e exclude Asserted by PHPUnit (stub-scan-style assertion on the controller behaviour).

### Requirement: RBAC-gated export

`GET /api/ai/audit/export` SHALL stream the audit log as CSV (all schema fields; `format=json` optional), and SHALL be restricted to members of `auditors`, `secretariaat`, `beheerders`, `admin` groups or NC admins — the same gate as the parafering audit export. Unauthorized users receive 403.

#### Scenario: Auditor exports CSV

- **GIVEN** a user in the `auditors` group
- **WHEN** they request the export
- **THEN** they receive a `text/csv` download containing one row per audit entry with type, action, caseId, model, confidence, userAction, reason, userId and timestamps

@e2e exclude PHPUnit covers RBAC and CSV shape; export endpoints have no browser UI beyond a link.

#### Scenario: Unauthorized export denied

- **GIVEN** an authenticated user in none of the allowed groups
- **WHEN** they request the export
- **THEN** the response is 403 and no data is returned

@e2e exclude Same rationale.

### Requirement: Oversight surface

The manifest SHALL declare an "AI oversight" `index` page at `/settings/ai-oversight` over `(dossiq, aiAuditEntry)` with a detail page showing the full entry (prompt, suggestion, human decision), following the existing settings index-page pattern.

#### Scenario: Oversight page lists AI activity

- **GIVEN** an authorized user opens Settings → AI oversight
- **THEN** a list of AI audit entries renders with type, action, model, userAction and user columns
- **AND** opening a row shows the full prompt/suggestion/decision detail

@e2e exclude Config-only manifest addition rendered by the OR AppHost (page host covered by nc-vue/OR suites); manifest consistency asserted by schema validation + vitest if a manifest test harness exists.

### Requirement: Suggestion-time logging completeness

Every AI operation exposed by `AiController` (classify, extract, ask, summarize, suggestRouting, suggestNext) SHALL record an audit entry at suggestion time, including at minimum: type, model, prompt reference, suggestion, confidence, userId.

#### Scenario: Summarize is logged

- **GIVEN** AI assistance is configured
- **WHEN** a user requests a case summary
- **THEN** an `aiAuditEntry` is recorded for the summarize operation before the response returns

@e2e exclude PHPUnit asserts each AiService operation path invokes the audit writer (mocked ObjectService), independent of a live model.

