# Tasks: hours-onto-humaniq-leaf

Tier: V1. Kind: config. One file changes. Every checkbox is one implementation
task; the criteria under a task are plain bullets.

## 1. The placement

- [x] 1.1 `src/manifest.json` page `CaseDetail`, widget `case-kpis-hours`: replace
  `type: stats-block` with `type: integration` and `integrationId:
  humaniq-hours`. Delete `content.entries` and `requiredApp`. Keep `id`, `title`
  and `icon`.
  - `python3 -c "import json;json.load(open('src/manifest.json'))"` exits 0
  - `git diff` touches this widget and nothing else
  - `@spec openspec/specs/case-hours-via-humaniq-leaf/spec.md`
- [x] 1.2 Rewrite the widget `_note`: hours live in humaniq per ADR-107 decision
  6, this is a placement rather than a cross-app register query, and a manifest
  query against an absent app's register renders `0`, which is what a real zero
  renders (ADR-113). No em-dash, sentence case.
- [x] 1.3 Assert the widget id is unchanged and the `layout` entry naming it is
  untouched.
  - `grep -n case-kpis-hours src/manifest.json` matches the widget and one layout
    entry, and the layout entry is absent from `git diff`

## 2. Verification

- [ ] 2.1 Re-run the greps as gates, with the searched-file count asserted
  non-zero: no widget declares `"register": "humaniq"`, no `"type":
  "integration"` widget declares `requiredApp`, and no line added by this change
  contains an em-dash.
- [ ] 2.2 `npm run check:manifest`; read the exit code, and compare the findings
  against the same run on `development` so a pre-existing failure is not read as
  a new one.
- [ ] 2.3 [blocked: humaniq `hours-leaf-for-any-object` shipping the
  `humaniq-hours` bundle] Verify on a live instance with humaniq enabled: the
  widget renders the leaf in its cell, the total matches hours booked through the
  Log hours action, and the same instance with humaniq disabled renders no hours
  surface rather than `0`.
- [x] 2.4 Add the e2e coverage the delta scenarios name. It landed in a file of
  its own, `tests/e2e/case-hours-leaf.spec.ts`, rather than beside the tile
  assertions: the two halves of the scenario need opposite instances, and that
  split is the file's whole shape.
  - The absence half runs on CI and asserts REQ-HRS-001: the page renders its own
    widgets, no `hq-hours-widget` and no `Hours booked` heading, and it makes no
    request to humaniq at all.
  - The journey half is registered only under `DOSSIQ_E2E_HUMANIQ=1`, and each
    half verifies that flag against the live OCS apps list in `beforeAll`. No
    `test.skip()`, because a skipped test reads like a passed one.
  - Stale assertions retired: `COLUMN_TITLES` in
    `tests/e2e/case-detail-kpis-and-tabs.spec.ts` required the `Hours booked`
    heading, which no longer renders where humaniq is absent, and would have
    failed every CI run. Its KNOWN GAP comment, and two comments in
    `tests/e2e/case-requester.spec.ts` calling the widget a stats-block, now say
    what is true.
  - 🔴 The journey half is UNPROVEN. It is written against the leaf's published
    `data-testid` contract and has never run, because the bundle is not shipped
    yet. Task 2.3 is what turns it green.
