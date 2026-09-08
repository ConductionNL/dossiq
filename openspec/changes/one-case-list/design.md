# Design: one-case-list

## Context

`Cases` and `Tasks` (`src/manifest.json`) are both type `index`, rendered by
nextcloud-vue 2.40.0's `CnIndexPage`. The dist reads `quickFilters` as a
list of `{ label, filter, default? }` (`dist/esm/composables/
indexSources.js`, `CnIndexPage/useSelfFetchList.js`, rendered by
`CnQuickFilterBar`); a page-level `quickFilters` wins over a source-level
one. The `@me` token resolves in a chip filter where it does not resolve in
a page base filter, which is why `MyWork` is a custom wrapper today and why
these lenses are configuration. `Cases.bulkActions` holds one entry,
`reassign`, whose handler `reassignSelection` lives in
`src/customComponents.js` and opens `src/modals/BulkReassignModal.vue`.
`src/dialogs/BulkTransitionDialog.vue` takes `caseIds`, lists the available
transitions of the first case, previews through `buildPreviewPayload`,
executes through `buildExecutePayload(selection, transitionId, comment)` and
summarises per case (`src/utils/bulkTransitionHelpers.js`). The comment is
optional there. `DeadlinePauseService::registerPauze` and
`resumeAfterPauze` already pause and resume a term instance.

## Decisions

**Chips, not pages.** Per D1 revised, Queue and MyWork stay. The chips give
the Cases page the same lenses without touching `menu`, so gate-53 and the
ADR-100 page ratchet see no change. Unclaimed on Cases and the Queue base
filter are the same two conditions (`assignee: "IS NULL"`,
`isFinalStatus: false`), kept literally equal so the lists cannot drift.

**Mine is default on both lists.** A default chip is applied before the
first fetch, so the first paint is already narrowed; the e2e asserts the
active chip and the rows on landing, not after a click.

**One dialog, four modes.** `BulkTransitionDialog` gains a `mode` prop
(`transition`, `suspend`, `resume`, `extend`) and a required `reason`
field. Transition keeps its behaviour with the reason posted as the comment.
Suspend and Resume post to the bulk endpoints with the pause payload the
board does not use yet; Extend term shows a date input and posts the new
deadline. The alternative, four dialogs, was rejected: the preview, the
per-case summary and the failure reporting are the part worth keeping equal.

**Deadline through the countdown cell.** The column config names the cell
(`widget: countdown` on the `deadline` column); the cell computes days from
`deadline` against today and applies the overdue class. Interim, if the
2.40.0 data table turns out not to expose the countdown cell on a column
(only `CnCountdownWidgetForm` is visible in the dist), a dossiq formatter
`deadlineCountdown` in `src/customComponents.js` renders the same text and
class, `[blocked: nextcloud-vue 2.40.0 data table has no countdown column
cell]`. The e2e asserts on the text and the class, so it survives the swap.

**The tile's View all carries the chip.** The Overdue dashboard tile links
to `Cases` with the query the Overdue chip uses, and the page activates the
chip whose filter equals the query, so the filter is visible and clearable
instead of silently dropped.

## Risks

- `@today` in a chip filter: the token must resolve on the client before
  the query is sent. If 2.40.0 does not resolve it, the chip computes the
  ISO date at mount; the manifest still declares `@today` so the intent is
  readable.
- A chip and a sidebar filter on the same field (`deadline`) combine as
  AND; the spec requires this and the e2e covers Mine plus Deadline before.
