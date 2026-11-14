## 1. Manifest

- [x] 1.1 Delete the `StatusRecords` page from `src/manifest.json`.
- [x] 1.2 Delete the `StatusRecordsMenu` menu entry from `src/manifest.json`.
- [x] 1.3 Remove the `StatusRecordsMenu → AnalyticsGroup` relocation from `src/menu-layout.json`.
- [x] 1.4 Add a "Change history" `audit-trail` sidebar tab to `CaseDetail` (`id: history`, `widgets: [{ type: 'audit-trail' }]`) and remove the equivalent `case-audit` body widget + its layout entry.

## 2. Tests / sweep

- [x] 2.1 Sweep `tests/` for `StatusRecords` references and update assertions to expect the page's absence (`tests/e2e/spec-coverage/settings-pages.spec.ts`).

## 3. Verify

- [x] 3.1 `USE_LOCAL_LIB=false npm run build` compiles with the page/menu removed.
- [x] 3.2 `openspec validate retire-status-history-page` passes.

## Acceptance Criteria

- No `StatusRecords` page or `StatusRecordsMenu` entry remains in the manifest.
- The case's change history is reachable only via the `CaseDetail` "Change history" audit-trail tab.
- No e2e spec navigates to the retired standalone page.

## Quality Checklist

- Config-only change; no PHP, schema, or component edits.
- i18n source strings stay English.
- Existing audit-trail tab reused as-is.
