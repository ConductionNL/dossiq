---
kind: config
depends_on: []
---

# Proposal: one-case-list

Round 2 competitor analysis, rows A07, A08, A21, A22 and A36 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 2 on the
placement ladder: manifest configuration on the two index pages that already
exist, `Cases` and `Tasks`, using the `quickFilters`, `columns`, `sidebar`
and `bulkActions` keys that nextcloud-vue 2.40.0 reads.

## Why

You cannot narrow the case list to your own work, to unclaimed work or to
overdue work without leaving it. `Cases` (`src/manifest.json`) lists every
case, closed ones included, with a plain Deadline column and no deadline
filter; the Overdue tile's View all drops its filter (triage item 4,
`_round2/dossiq-baseline/pages/Cases.md`, `journeys.md` J1 and J7). `Tasks`
has saved views and the generic filters, no presets. Every competitor puts
the lenses on the list itself: gzac's tabs Alle, Mijn and Niet-toegewezen on
the case list and the task list with Afgerond unticked by default
(`valtimo/round2/pages/CaseList.md`, `pages/TaskList.md`), zaaksysteem's
quick links Alle zaken and Mijn openstaande zaken with a red target date and
a Dagen column (`xxllnc-zaken/round2/search-anatomy.md`,
`pages/LegacyDashboard.md`), opencase's My cases page over open cases only
(`opencase/round2/pages/MyCases.md`). Bulk work stops at reassign:
`Cases.bulkActions` holds one entry while `BulkTransitionDialog.vue` and
`BulkStatusTransitionService` already serve the workflow board (findings A21,
`reassignment-is-a-bulk-action`).

## Decision D1, revised

Ruben keeps the Queue page (`add-work-queue`) and the MyWork page as separate
pages. Nothing is folded, nothing is retired and no menu entry changes. The
ADR-097 role-lens rule is noted, not applied. This change is only the chips,
the columns, the sidebar filters and the bulk actions on the two index pages.

## What changes

- **A07, A08: lenses on Cases.** `quickFilters` chips All (the default),
  Mine (assignee `@me`, `isFinalStatus` false), Unclaimed (assignee
  `IS NULL`, `isFinalStatus` false), Closed (`isFinalStatus` true) and
  Overdue (`deadline` before `@today`, `isFinalStatus` false). The Queue
  page keeps the same Unclaimed filter, so the two agree by construction.
- **A36: lenses on Tasks.** The same All, Mine and Unclaimed chips over
  `caseTask`, All default, `isTerminalStatus` false on Mine and Unclaimed.

## Decision D-default, revised

Ruben revised "Mine is the default chip" to "All is the default chip" on
both indexes. A `quickFilters` list activates a chip on mount, so a Mine
default silently narrows the first paint to the signed-in user and an empty
result reads as an empty register. All keeps the landing view the one the
page already shows and leaves Mine one click away. Recorded in the `my-work`
delta.
- **A22: the deadline you can read.** The Deadline column on `Cases` renders
  through the countdown cell: days left, red once past due. The sidebar gains
  a Deadline before filter.
- **A35, referenced.** The Requester column over `initiatorDisplayName` and
  its sidebar text filter are specified in `requester-on-the-case`
  (`initiator-display` REQ-ID-2). This change places the column in the
  column order and does not restate it.
- **A21: bulk actions beyond reassign.** `bulkActions` Transition, Suspend,
  Resume and Extend term next to Reassign. Each opens the existing bulk
  dialog for the selection with a reason field, and the handler posts to the
  bulk-transition endpoints the board already uses.

## Capabilities

- `my-work`: ADDED Lenses on the Cases index [V1].
- `case-management`: ADDED REQ-CM-24 (open work by default, Closed on request),
  REQ-CM-25 (Deadline before in the sidebar).
- `task-management`: ADDED REQ-TASK-016 (lenses on the Tasks index).
- `case-bulk-status-transition`: ADDED Bulk actions on the case index.
- `signalering-widgets`: ADDED Countdown Deadline Column [V1].

## Out of scope

- Folding Queue or MyWork into Cases, retiring a menu entry (D1 revised).
- The Requester column itself and the masking of protected persons
  (`requester-on-the-case`).
- Per-case-type navigation children (D2, `case-type-navigation`).
- Bulk jobs with simulation and background execution (Tier B, D6).

## Impact

Kind config: `src/manifest.json` on two pages, one bulk-action handler in
`src/customComponents.js` reusing `BulkTransitionDialog.vue`, one dialog prop
for the reason, one unit spec, one e2e spec. No PHP route is added: pause and
resume go through `DeadlinePauseService` behind the bulk-transition
endpoints; extend term writes `deadline`. No schema change.
