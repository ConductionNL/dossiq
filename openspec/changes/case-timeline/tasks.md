# Tasks: case-timeline

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets. Depends on `case-header` for the
tab order it amends (task 4.1).

## 1. One history tab

- [ ] 1.1 `src/manifest.json` page `CaseDetail` (`pages[4]`)
  `config.sidebar.tabs`: remove the entry `id: version-history`
  (`component: VersionHistoryLeafTab`); keep `audit` (label History, icon
  History, `widgets: [{ type: "audit" }]`) first; add a `_note` naming
  placement row A05. Touch no other page's sidebar and leave the
  `VersionHistoryLeafTab` entry in `src/registry.js`, which 13 other pages
  resolve.
  - `@spec openspec/changes/case-timeline/specs/case-dashboard-view/spec.md`
  - `npm run check:manifest` exits 0 (`tests/validate-manifest.js`)
  - unit test in `tests/vitest/manifestCaseTimeline.spec.js`: `CaseDetail`
    has exactly one sidebar tab with widget type `audit`, none with id
    `version-history` or component `VersionHistoryLeafTab`; every other
    page's `sidebar.tabs.length` equals its value on `development`
- [ ] 1.2 `l10n/nl.json`: History reads Geschiedenis if the key is not
  there yet; load the `writing` skill first.
- [ ] 1.3 `grep -rn 'Version history\|version-history' tests/e2e` found no
  assertion on the `CaseDetail` Version history tab (the only hit,
  `tests/e2e/spec-coverage/document-zaakdossier.spec.ts:79`, is the
  concept-document version history under `document-zaakdossier`, not the
  sidebar). Re-run the grep before merging; if a spec has since started
  asserting the tab on `CaseDetail`, change it to assert the tab is gone.

## 2. Writes first

- [ ] 2.1 Verify live that the Action and User filters on the History tab
  load and narrow the list (the baseline saw both on Loading); if they do
  not, file the defect against nextcloud-vue `CnAuditTrailTab` with the
  request that failed, and record the issue number here.
  - `@spec openspec/changes/case-timeline/specs/case-dashboard-view/spec.md`
- [ ] 2.2 [blocked: nextcloud-vue an `actions` (or equivalent) preset prop
  on the `audit` sidebar widget so the tab opens on create, update and
  delete, placement section 3 row A05] Set it in the `audit` tab entry
  once it lands; until then the tab opens unfiltered and this task stays
  open.

## 3. Export and the merged feed

- [ ] 3.1 [blocked: nextcloud-vue an Export action on `CnAuditTrailTab`
  writing the filtered rows as CSV, placement section 3 row A05] Enable it
  in the `audit` tab entry once it lands; until then there is no export
  and this task stays open.
- [ ] 3.2 [blocked: openregister activity leaf, a merged per-object feed
  of audit, document, note and mail events, placement section 3 row A05]
  Replace `widgets: [{ type: "audit" }]` with the activity widget once it
  ships; until then the History tab shows the audit trail over writes only
  and this task stays open.

## 4. The body strip

- [ ] 4.1 `openspec/changes/case-header`: drop Timeline (`case-timeline`)
  from the tab order in `proposal.md` (What changes, A33), `design.md`
  (D3), `tasks.md` (4.1 and 5.1) and REQ-CDV-16 in
  `specs/case-dashboard-view/spec.md` (requirement text and the scenarios
  The six work tabs come first and The work tabs fit a laptop screen now
  count five), with one line saying the timeline is the sidebar History
  tab per this change. Both changes are unarchived in this batch, so this
  is an edit, not a spec sync.
  - `openspec validate case-header --strict` and `openspec validate
    case-timeline --strict` both exit 0

## 5. Verification

- [ ] 5.1 `tests/e2e/case-timeline.spec.ts`: seeds one case with
  identifier 2026-0015, updates its description through the API as admin,
  opens the page, opens the sidebar, asserts by tab id that `audit` is
  present and `version-history` is not, that the first History row reads
  action update and user admin with the create row below it, and that
  choosing update in the Action filter leaves one row and the filter does
  not read Loading. Assert ids and testids, not labels, because the
  instance may run in Dutch.
  - the spec must appear in `tests/e2e/playwright.config.ts`'s project,
    the config CI reads
- [ ] 5.2 Run `npm run lint`, `npm run check:manifest`, `npm run test:unit`
  and the e2e spec locally; read `$?` on each, not the summary line.
- [ ] 5.3 `docs/case-detail.md` (or the page's docs entry): one paragraph
  on the History tab, what it shows and what waits on the activity leaf;
  load the `writing` skill first.
