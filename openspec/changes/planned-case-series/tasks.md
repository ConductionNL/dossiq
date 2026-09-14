# Tasks: planned-case-series

Tier: V1. Kind: config plus two PHP files. Row 1.8.

## 1. The document

- [x] 1.1 `lib/Service/Flow/PlannedFollowUpDocument.php`: accept a
  recurrence (`none|monthly|quarterly|halfYearly|yearly`) and an end
  (`until` date or `count`); write the cron fields per D-1; keep `runAs`.
  - unit pair: each recurrence to its cron fields; none unchanged
  - `@spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md`
- [x] 1.2 `isSpent(document, firedCount, today)` on the document class (D-2).
  - unit: count reached, date passed, neither set

## 2. The sweep

- [x] 2.1 `lib/BackgroundJob/PlannedFollowUpSweepJob.php`: switch off only
  when `isSpent()`; record the reason on the flow.
  - unit over a flow stub: single fires once; series survives; spent series off

## 3. The page

- [x] 3.1 `src/manifest.json` `#CaseDetail` action `plan-follow-up`: the
  recurrence and end fields on the form.
- [x] 3.2 `#CaseDetail` Related tab: series row with next occurrence, the
  occurrences underneath (filter `handoffSource`), Stop series action.
  - `tests/vitest/caseActionsMenu.spec.js` extended

## 4. Verify

- [ ] 4.1 `tests/e2e/planned-case-series.spec.ts` covering the three cited
  scenarios; `openspec validate --change planned-case-series --strict`.
