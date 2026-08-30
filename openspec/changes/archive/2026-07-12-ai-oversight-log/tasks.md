## 1. Real audit querying

- [x] 1.1 Add `AiService::listAuditEntries(array $filters, int $limit, int $offset): array` — resolve register + `ai_audit_entry_schema` config exactly like `recordAuditEntry()`, query OR ObjectService for the entries (find the right listing method by reading how other procest services list OR objects — e.g. grep for `findAll`/`searchObjects`/`getObjects` usages in lib/Service/), apply caseId/type filters, newest-first ordering, limit (default 50, clamp to 200) + offset. Return `['entries' => [...], 'total' => ?, 'limit' => ..., 'offset' => ...]` (include total only if the OR API provides it cheaply).
- [x] 1.2 Replace the stub body of `AiController::auditIndex()` with a call to `listAuditEntries()`, keeping the auth check and adding error handling in the style of the sibling methods. Update its `@spec` tag to this change's spec.
- [x] 1.3 PHPUnit `tests/Unit/Service/AiServiceAuditListTest.php` (mock the container/ObjectService the same way existing AiService tests do — check tests/Unit for AiService precedent; if none, follow the closest container-mocking pattern): filters applied, clamping, unconfigured register → empty result + warning (no throw). Plus a controller test asserting the stub message is gone and paging params pass through.

## 2. Export endpoint

- [x] 2.1 Create `lib/Controller/AiAuditExportController.php` modeled directly on `lib/Controller/ParaferingAuditExportController.php` (same ALLOWED_GROUPS list, same RBAC check, same SPDX/@spec conventions): `export()` streams CSV (header row = aiAuditEntry schema fields incl. created timestamp; values flattened, arrays JSON-encoded) via the entries from `AiService::listAuditEntries()` (no pagination cap for export — iterate pages internally or raise the limit; bound total at e.g. 10000 rows to avoid memory blowups, documented). Support `?format=json` returning the raw entries array.
- [x] 2.2 Route: `['name' => 'aiAuditExport#export', 'url' => '/api/ai/audit/export', 'verb' => 'GET']` placed with the other ai# routes (literal segment after /api/ai/audit — verify no conflict with `ai#auditIndex`).
- [x] 2.3 PHPUnit `tests/Unit/Controller/AiAuditExportControllerTest.php`: allowed group → CSV content-type + header row; admin fallback allowed; plain user → 403; format=json shape.

## 3. Oversight surface (manifest)

- [x] 3.1 Add manifest `index` page `AiOversight` (`/settings/ai-oversight`, title "AI oversight") over `register: procest, schema: aiAuditEntry`, columns: `type`, `action`, `model`, `userAction`, `userId`; row action View → `AiOversightDetail`. Add `detail` page `AiOversightDetail` (`/settings/ai-oversight/:id`) following an existing settings detail page's structure (e.g. ParafeerrouteDetail) showing the entry fields incl. prompt/suggestion/reason.
- [x] 3.2 Wire the pages into the settings menu the same way other settings index pages appear (check `src/manifest.json` menu / `src/menu-layout.json` for how Parafeerroutes/WmsLayers are grouped) so the page is reachable.
- [x] 3.3 Run manifest validation (`npm run check:manifest`).

## 4. Suggestion-time logging completeness

- [x] 4.1 Read `lib/Service/AiService.php` and list which public AI operations (classify, extract, ask, summarize, suggestRouting, suggestNext) call `recordAuditEntry()` at suggestion time. Add the call (type-appropriate entry: type, action='suggested', model, prompt, suggestion, confidence, userId) where missing — mirror the fields the existing call sites use.
- [x] 4.2 PHPUnit coverage asserting each operation path triggers the audit writer (mock the container's ObjectService and assert saveObject called with the right schema config), following however the existing AiService tests stub the model call (if the model call itself is hard to stub, test at the smallest seam that proves the logging call is on the path — document the seam chosen).

## 5. Verify

- [x] 5.1 `vendor/bin/phpunit -c phpunit-unit.xml --filter 'AiService|AiAudit|AiController'` green (run in the procest-php83 docker image as before), then FULL unit suite — no new failures vs the 1120-green baseline.
- [x] 5.2 Full `npm test` (209 baseline) — no regressions; `npm run check:manifest` passes.
- [x] 5.3 eslint/phpcs/phpstan/psalm clean on new/changed files (pre-existing violations reported, not fixed unless introduced).
- [x] 5.4 `openspec validate ai-oversight-log` passes.

## Acceptance Criteria

- `GET /api/ai/audit` returns real OR-backed entries with filters + paging; stub message eliminated.
- Export endpoint streams CSV/JSON, gated to auditors/secretariaat/beheerders/admin + NC admin; 403 otherwise.
- AI oversight index + detail pages reachable under settings; manifest valid.
- All six AI operations record suggestion-time audit entries; tests prove it.
- Full PHP + JS suites: no new failures.

## Quality Checklist

- No new dependencies; RBAC gate identical to the parafering export precedent.
- SPDX + @license/@copyright + @spec on new/changed PHP.
- No stub code remains (hydra stub-scan clean on touched files).
- i18n keys English; manifest titles sentence-case.
