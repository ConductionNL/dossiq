## 1. Backend service

- [x] 1.1 Create `lib/Service/BulkStatusTransitionService.php` (SPDX + @spec tags per repo convention): constructor takes `StatusTransitionService` + `LoggerInterface`. `preview(array $caseIds, string $transitionId): array` — per case: call the engine's availability/guard evaluation WITHOUT writes (read `StatusTransitionService` first: reuse `getAvailableTransitions()` and, if the engine exposes guard evaluation separately, use it; otherwise derive "ready/blocked" from availability + guard check the same way `execute()` would, but read-only — verify what the engine actually exposes and follow it). Returns `['results' => [caseId => ['status' => 'ready'|'blocked'|'error', 'reasons' => [...]]], 'summary' => [...]]`. `execute(array $caseIds, string $transitionId, ?string $comment, )`: validates 1..100 ids; loops `execute()` per case catching `GuardFailedException` (→ failed + reasons) and per-case Throwables (→ error, logged); returns per-case outcomes + summary. NEVER writes outside the engine.
- [x] 1.2 Unit tests `tests/Unit/Service/BulkStatusTransitionServiceTest.php` (mock StatusTransitionService; follow existing service-test conventions in tests/Unit/Service/): happy path, mixed guard failure, per-case exception isolation (one throw doesn't abort the rest), 0/101 ids rejected, preview performs no execute calls.

## 2. Backend controller + routes

- [x] 2.1 Add `bulkPreview()` and `bulkExecute()` to `lib/Controller/StatusTransitionController.php`, mirroring the existing `execute()` style (auth check, readJsonBody, error mapping, logging, @NoAdminRequired docblock annotations exactly like siblings, @spec tag to this change's spec). Body: `{caseIds: string[], transitionId: string, comment?: string}`.
- [x] 2.2 Register routes in `appinfo/routes.php` next to the other statusTransition routes: `['name' => 'statusTransition#bulkPreview', 'url' => '/api/cases/bulk-transition/preview', 'verb' => 'POST']` and `['name' => 'statusTransition#bulkExecute', 'url' => '/api/cases/bulk-transition/execute', 'verb' => 'POST']`. Check for collisions with `/api/cases/...` patterns (e.g. zaakdossier `{caseId}` routes) — literal segment `bulk-transition` must resolve; place before parameterized conflicts if any.
- [x] 2.3 Controller unit tests `tests/Unit/Controller/StatusTransitionControllerBulkTest.php`: 401 unauthenticated, 400 missing transitionId, 400 empty/oversized ids, happy path passes through to service, guard-fail mapping. Follow existing controller-test conventions.

## 3. Frontend

- [x] 3.1 Create `src/utils/bulkTransitionHelpers.js`: pure functions — `toggleSelection(selection, caseId, columnId)` implementing column-scoped selection (cross-column select resets to the new case), `buildPreviewPayload(selection, transitionId)`, `buildExecutePayload(selection, transitionId, comment)`, `summarizeResults(results)` (counts + failed list). These carry ALL logic the components use.
- [x] 3.2 Vitest `tests/vitest/bulkTransitionHelpers.spec.js` covering the helper functions (incl. cross-column reset, empty selection, result summarization).
- [x] 3.3 Board wiring: in `src/views/workflow-board/` add selection mode — `CaseCard.vue` gets an NcCheckboxRadioSwitch (visible in selection mode or on hover, with aria-label), `WorkflowBoard.vue` holds `selection` state via the helper, renders a bulk-actions bar (reuse the visual pattern of `src/views/cases/components/BulkActionsBar.vue` — count + "Change status…" + "Cancel") when selection non-empty.
- [x] 3.4 Create `src/dialogs/BulkTransitionDialog.vue` (NcDialog, own file per modal isolation): props = selected case ids + column's available transitions (WorkflowBoard already knows column transitions — verify how columns get transitions and reuse; if the board does not have them, fetch available transitions for ONE selected case via the existing `/api/case/{caseId}/available-transitions`). Flow: pick transition → auto-run preview (POST preview) → render per-case ready/blocked list → Execute button (disabled when 0 ready) → POST execute → per-case results view. All fetch logic through a small api module or inline axios consistent with how the board already calls transition endpoints (check CaseCard/WorkflowBoard for the existing http client pattern).
- [x] 3.5 i18n: all labels via `t('procest', ...)` with English source strings.

## 4. Verify

- [x] 4.1 `vendor/bin/phpunit -c phpunit-unit.xml --filter 'BulkStatusTransition|StatusTransitionControllerBulk'` green, then FULL `vendor/bin/phpunit -c phpunit-unit.xml` — no new failures vs the green baseline (1100 tests OK as of change zgw-openapi-publication).
- [x] 4.2 `USE_LOCAL_LIB=false npx vitest run tests/vitest/bulkTransitionHelpers.spec.js` then full `npm test` — no regressions.
- [x] 4.3 `USE_LOCAL_LIB=false npm run build` compiles (board + dialog changes).
- [x] 4.4 eslint on all new/changed src files; phpcs on new PHP files (report pre-existing violations separately, fix only what you introduced).
- [x] 4.5 `openspec validate case-bulk-status-transition` passes.

## Acceptance Criteria

- Bulk preview/execute endpoints live behind the engine's single write path with per-case guard evaluation, 100-id cap, partial-failure reporting.
- Board selection is column-scoped with reset-on-cross-column; dialog previews before execute and reports per-case outcomes.
- All new tests green; full PHP + JS suites show no new failures; build compiles.

## Quality Checklist

- Modal in its own file under `src/dialogs/` (NcDialog).
- NcCheckboxRadioSwitch / NcSelect accessibility props set.
- No new dependencies; http client + store patterns consistent with the board's existing code.
- SPDX + @spec on new PHP; i18n keys English.
